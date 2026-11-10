<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use LogicException;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use Throwable;

final class Backfiller
{
    public const int CHUNK = 1000;

    public const int MARGIN = 300;

    private const string SAVEPOINT = 'compatibility_backfill_row';

    private readonly ColumnCatalog $catalog;

    private readonly string $key;

    private readonly null|string $connectionName;

    private readonly Ledger $ledger;

    private readonly Watermark $watermark;

    private null|RoundTrip $roundTrip = null;

    public function __construct(private readonly ResolvedMap $map, private readonly ConnectionInterface $connection)
    {
        $legacy = new $map->model;
        $this->catalog = new ColumnCatalog(
            connection: $connection,
            throughWriteConnection: true,
        );
        $this->key = $legacy->getKeyName();
        $this->connectionName = $legacy->getConnectionName();
        $this->ledger = new Ledger(
            map: $map,
            connection: $connection,
        );
        $this->watermark = new Watermark(
            legacyType: $map->table,
            connection: $connection,
        );
    }

    public function run(int $chunk = self::CHUNK, null|CarbonInterval $margin = null): Pass
    {
        $this->watermark->hold('backfill');

        try {
            $this->refuseUnwalkable($chunk);
            $since = $this->since($margin);
            $started = $this->watermark->start();
            $pass = $this->walk(
                chunk: $chunk,
                since: $since,
                scoped: true,
            );
            $this->watermark->finish(
                started: $started,
                rows: $pass->walked,
            );
            $pass->watermark = $this->watermark->remembered();

            return $pass;
        } finally {
            $this->watermark->release();
        }
    }

    /**
     * @param  list<int|string>  $keys
     */
    public function catchUp(array $keys): Pass
    {
        $this->roundTrip();
        $pass = new Pass;

        foreach (array_chunk(array: $keys, length: self::CHUNK) as $batch) {
            $this->chunk(
                pass: $pass,
                rows: fn () => $this->rowsOf($batch),
                scoped: true,
            );
        }

        return $pass;
    }

    public function finalPass(null|CarbonInterval $margin): Pass
    {
        return $this->walk(
            chunk: self::CHUNK,
            since: $this->since($margin),
            scoped: false,
        );
    }

    public function refuseUnconvergeable(): void
    {
        if ($this->updatedAt() === null) {
            throw InvalidCompatibilityMap::cannot(
                map: $this->map->table,
                operation: 'install',
                because: "{$this->map->table} has no updated_at column its legacy model writes, so install() cannot limit its catch-up to the rows written since the backfill, and would walk every legacy row while it holds the lock",
            );
        }
    }

    public function remembered(): null|string
    {
        return $this->watermark->remembered();
    }

    private function walk(int $chunk, null|string $since, bool $scoped): Pass
    {
        $this->roundTrip();
        $pass = new Pass;

        if ($since === null) {
            $last = null;

            do {
                $read = $this->chunk(
                    pass: $pass,
                    rows: fn () => $this->rowsAfter(
                        last: $last,
                        chunk: $chunk,
                    ),
                    scoped: $scoped,
                );
                $last = $read === [] ? $last : self::keyOf(end($read)[$this->key] ?? null);
            } while (count($read) === $chunk);
        } else {
            foreach (array_chunk(array: $this->candidates($since), length: $chunk) as $batch) {
                $this->chunk(
                    pass: $pass,
                    rows: fn () => $this->rowsOf($batch),
                    scoped: $scoped,
                );
            }
        }

        $this->deleteGone(
            pass: $pass,
            scoped: $scoped,
        );

        return $pass;
    }

    /**
     * @param  Closure(): list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function chunk(Pass $pass, Closure $rows, bool $scoped): array
    {
        return $this->connection->transaction(function () use ($pass, $rows, $scoped): array {
            $zone = $this->inApplicationZone();
            $read = $rows();
            $previous = $this->ledger->statuses(array_map(
                callback: fn (array $row) => self::keyOf($row[$this->key] ?? null),
                array: $read,
            ));

            foreach ($read as $row) {
                $key = self::keyOf($row[$this->key] ?? null);
                $named = self::rootKeyOf($row[$this->map->successorKey] ?? null);
                [$outcome, $successor, $reason] = $this->attempt(
                    write: fn () => $this->write(
                        key: $key,
                        named: $named,
                        scoped: $scoped,
                    ),
                    successor: $named,
                );
                $this->ledger->record(
                    key: $key,
                    successor: $successor,
                    outcome: $outcome,
                    reason: $reason,
                );
                $pass->record(
                    key: $key,
                    outcome: $outcome,
                    previous: $previous[(string) $key] ?? null,
                    reason: $reason,
                );
            }

            $pass->walked += count($read);
            $this->restoreZone($zone);

            return $read;
        });
    }

    /**
     * @param  Closure(): array{Outcome, null|int|string}  $write
     * @return array{Outcome, null|int|string, null|string}
     */
    private function attempt(Closure $write, null|int|string $successor): array
    {
        $this->connection->unprepared('SAVEPOINT '.self::SAVEPOINT);

        try {
            [$outcome, $written] = $write();
        } catch (Throwable $exception) {
            $this->connection->unprepared('ROLLBACK TO SAVEPOINT '.self::SAVEPOINT);
            $this->connection->unprepared('RELEASE SAVEPOINT '.self::SAVEPOINT);

            return [$exception instanceof Refusal ? Outcome::Refused : Outcome::Failed, $successor, $exception->getMessage()];
        }

        $this->connection->unprepared('RELEASE SAVEPOINT '.self::SAVEPOINT);

        return [$outcome, $written, null];
    }

    /**
     * @return array{Outcome, null|int|string}
     */
    private function write(int|string $key, null|int|string $named, bool $scoped): array
    {
        $unit = $this->unit();
        $writer = new RecordWriter(
            map: $this->map,
            connection: $this->connectionOf(),
        );
        $updates = $named !== null && $this->rootExists($named);
        $touched = $scoped && $unit->scopes() ? $this->touched(
            unit: $unit,
            writer: $writer,
            key: $key,
            named: $named,
            updates: $updates,
        ) : [];
        $locked = function () use ($unit, $writer, $key, $named, $touched, $scoped): array {
            $row = $this->current(key: $key, lock: false);
            $current = self::rootKeyOf($row[$this->map->successorKey] ?? null);

            if ($row === null || $current === null || ! $this->rootExists($current)) {
                $row = $this->current(key: $key, lock: true);
                $current = self::rootKeyOf($row[$this->map->successorKey] ?? null);
            }

            if ($row === null) {
                return [Outcome::Unchanged, $named];
            }

            if ($current !== null && $this->rootExists($current)) {
                return $this->update(
                    unit: $unit,
                    writer: $writer,
                    key: $key,
                    rootKey: $current,
                    row: $row,
                    touched: $touched,
                    scoped: $scoped,
                );
            }

            return $this->create(
                unit: $unit,
                writer: $writer,
                key: $key,
                named: $current,
                row: $row,
                touched: $touched,
                scoped: $scoped,
            );
        };

        return $unit->transaction(fn (): array => $scoped ? $unit->scoped(
            touched: $touched,
            write: $locked,
        ) : $locked());
    }

    /**
     * @return array<class-string<Model>, list<int|string>>
     */
    private function touched(UnitOfWork $unit, RecordWriter $writer, int|string $key, null|int|string $named, bool $updates): array
    {
        if ($updates && $named !== null) {
            $root = $unit->roots(
                keys: [$named],
                lock: false,
            )[$named] ?? null;

            return $root === null ? [] : $unit->touched($unit->graphs(
                roots: [[$writer->variantOf($root), $root]],
                lock: false,
            ));
        }

        $row = $this->current(
            key: $key,
            lock: false,
        );

        if ($row === null) {
            return [];
        }

        $legacy = $this->legacy($row);
        $variant = $this->variantFor($legacy);

        return $unit->touched([$unit->links(
            graph: new Staging(
                map: $variant,
                root: $this->root(
                    unit: $unit,
                    variant: $variant,
                    named: $named,
                ),
                models: [],
            ),
            values: $this->supplied(
                variant: $variant,
                row: $row,
            ),
            lock: false,
        )]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<class-string<Model>, list<int|string>>  $touched
     * @return array{Outcome, null|int|string}
     */
    private function update(UnitOfWork $unit, RecordWriter $writer, int|string $key, int|string $rootKey, array $row, array $touched, bool $scoped): array
    {
        $root = $unit->roots(
            keys: [$rootKey],
            lock: true,
        )[$rootKey] ?? null;

        if ($root === null) {
            return [Outcome::Unchanged, $rootKey];
        }

        $graph = $unit->graphs(
            roots: [[$writer->variantOf($root), $root]],
            lock: true,
        )[0];

        if ($scoped) {
            $unit->refuseMoved(
                scoped: $touched,
                graphs: [$graph],
            );
        }

        $legacy = $this->legacy($row);
        $served = $this->roundTrip()->read(
            keyName: $this->key,
            key: $key,
        );
        $served = $served === null ? [] : (array) $served;
        $original = $this->legacy($served);
        $mapped = $this->changed(
            variant: $this->variantFor($legacy),
            legacy: $legacy,
            row: $row,
            served: $served,
        );

        if ($mapped === []) {
            return [Outcome::Unchanged, $rootKey];
        }

        return [$writer->catchUp(
            legacy: $legacy,
            key: $key,
            graph: $graph,
            original: $original,
            mapped: $mapped,
            roundTrip: $this->roundTrip(),
        ) ? Outcome::Updated : Outcome::Unchanged, $rootKey];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<class-string<Model>, list<int|string>>  $touched
     * @return array{Outcome, null|int|string}
     */
    private function create(UnitOfWork $unit, RecordWriter $writer, int|string $key, null|int|string $named, array $row, array $touched, bool $scoped): array
    {
        $legacy = $this->legacy($row);
        $variant = $this->variantFor($legacy);
        $mapped = $this->supplied(
            variant: $variant,
            row: $row,
        );
        $graph = $unit->links(
            graph: new Staging(
                map: $variant,
                root: $this->root(
                    unit: $unit,
                    variant: $variant,
                    named: $named,
                ),
                models: [],
            ),
            values: $mapped,
            lock: true,
        );

        if ($scoped) {
            $unit->refuseMoved(
                scoped: $touched,
                graphs: [$graph],
            );
        }

        return [Outcome::Created, $writer->backfill(
            legacy: $legacy,
            key: $key,
            graph: $graph,
            mapped: $mapped,
            roundTrip: $this->roundTrip(),
            identify: fn (Model $root) => $this->identify(
                key: $key,
                named: $named,
                root: $root,
            ),
        )];
    }

    private function root(UnitOfWork $unit, ResolvedMap $variant, null|int|string $named): Model
    {
        $root = $unit->successor($variant->primary->model);

        if ($named !== null) {
            $root->setAttribute(
                key: $variant->primary->key,
                value: $named,
            );
        }

        return $root;
    }

    private function identify(int|string $key, null|int|string $named, Model $root): void
    {
        if ($named !== null) {
            return;
        }

        $this->connection->table($this->map->table)->where(
            column: $this->key,
            operator: '=',
            value: $key,
        )->whereNull($this->map->successorKey)->update([
            $this->map->successorKey => UnitOfWork::keyOf($root),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $served
     * @return array<string, mixed>
     */
    private function changed(ResolvedMap $variant, Model $legacy, array $row, array $served): array
    {
        $changed = [];

        foreach ($variant->attributes as $name => $attribute) {
            if (! array_key_exists(
                key: $name,
                array: $row,
            )) {
                continue;
            }

            if ($attribute->isExternal()) {
                if ($attribute->operation() !== null) {
                    $changed[$name] = $row[$name];
                }

                continue;
            }

            if ($this->roundTrip()->skips(
                attribute: $attribute,
                origin: Origin::Backfill,
            )) {
                continue;
            }

            if (array_key_exists(
                key: $name,
                array: $served,
            ) && $this->roundTrip()->agrees(
                legacy: $legacy,
                name: $name,
                written: $row[$name],
                served: $served[$name],
            )) {
                continue;
            }

            $changed[$name] = $row[$name];
        }

        if ($changed === []) {
            return [];
        }

        foreach ($variant->attributes as $name => $attribute) {
            $owner = $variant->owners[$name];
            $successor = new $owner->model;

            if (array_key_exists(
                key: $name,
                array: $row,
            ) && $successor->usesTimestamps() && $attribute->column() === $successor->getUpdatedAtColumn() && ! $this->roundTrip()->skips(
                attribute: $attribute,
                origin: Origin::Backfill,
            )) {
                $changed[$name] = $row[$name];
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function supplied(ResolvedMap $variant, array $row): array
    {
        return array_intersect_key($row, $variant->attributes);
    }

    private function variantFor(Model $legacy): ResolvedMap
    {
        return $this->map->morphs === [] ? $this->map : $this->map->morphFor($legacy);
    }

    private function deleteGone(Pass $pass, bool $scoped): void
    {
        foreach (array_chunk(array: $this->ledger->gone(), length: self::CHUNK) as $batch) {
            $this->connection->transaction(function () use ($batch, $pass, $scoped): void {
                foreach ($batch as [$key, $successor, $status]) {
                    [$outcome, , $reason] = $this->attempt(
                        write: fn () => [$this->delete(
                            key: $key,
                            successor: $successor,
                            scoped: $scoped,
                        ), $successor],
                        successor: $successor,
                    );
                    $this->ledger->record(
                        key: $key,
                        successor: $successor,
                        outcome: $outcome,
                        reason: $reason,
                    );
                    $pass->record(
                        key: $key,
                        outcome: $outcome,
                        previous: $status,
                        reason: $reason,
                    );
                }
            });
        }
    }

    private function delete(int|string $key, null|int|string $successor, bool $scoped): Outcome
    {
        if ($successor === null || $this->connection->table($this->map->table)->useWritePdo()->where(
            column: $this->map->successorKey,
            operator: '=',
            value: $successor,
        )->exists()) {
            return Outcome::Deleted;
        }

        $unit = $this->unit();
        $writer = new RecordWriter(
            map: $this->map,
            connection: $this->connectionOf(),
        );
        $root = $unit->roots(
            keys: [$successor],
            lock: false,
        )[$successor] ?? null;

        if ($root === null) {
            return Outcome::Deleted;
        }

        $touched = $scoped && $unit->scopes() ? $unit->touched($unit->graphs(
            roots: [[$writer->variantOf($root), $root]],
            lock: false,
        )) : [];
        $delete = function () use ($unit, $writer, $key, $successor, $touched, $scoped): Outcome {
            $root = $unit->roots(
                keys: [$successor],
                lock: true,
            )[$successor] ?? null;

            if ($root === null) {
                return Outcome::Deleted;
            }

            if ($scoped) {
                $unit->refuseMoved(
                    scoped: $touched,
                    graphs: $unit->graphs(
                        roots: [[$writer->variantOf($root), $root]],
                        lock: true,
                    ),
                );
            }

            if (method_exists(
                object_or_class: $root,
                method: 'trashed',
            ) && $root->trashed() === true) {
                return Outcome::Deleted;
            }

            $legacy = $this->legacy([$this->key => $key, $this->map->successorKey => $successor]);
            $unit->run(
                legacy: $legacy,
                successor: $root,
                write: fn () => $root->delete(),
            );

            return Outcome::Deleted;
        };

        return $unit->transaction(fn (): Outcome => $scoped ? $unit->scoped(
            touched: $touched,
            write: $delete,
        ) : $delete());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsAfter(null|int|string $last, int $chunk): array
    {
        $query = $this->connection->table($this->map->table)->useWritePdo()->orderBy($this->key)->limit($chunk);

        if ($last !== null) {
            $query->where(
                column: $this->key,
                operator: '>',
                value: $last,
            );
        }

        return array_values(array_map(
            callback: fn (object $row) => (array) $row,
            array: $query->get()->all(),
        ));
    }

    /**
     * @param  list<int|string>  $keys
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return array_values(array_map(
            callback: fn (object $row) => (array) $row,
            array: $this->connection->table($this->map->table)->useWritePdo()->whereIn(
                column: $this->key,
                values: $keys,
            )->orderBy($this->key)->get()->all(),
        ));
    }

    /**
     * @return null|array<string, mixed>
     */
    private function current(int|string $key, bool $lock): null|array
    {
        $query = $this->connection->table($this->map->table)->useWritePdo()->where(
            column: $this->key,
            operator: '=',
            value: $key,
        );
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @return list<int|string>
     */
    private function candidates(string $since): array
    {
        $column = $this->updatedAt() ?? throw InvalidCompatibilityMap::cannot(
            map: $this->map->table,
            operation: 'backfill',
            because: "{$this->map->table} has no updated_at column of ".class_basename($this->map->model).', so no watermark limits a pass',
        );
        $legacy = SqlFragments::identifier($this->map->table);
        $key = SqlFragments::identifier($this->key);
        $updated = SqlFragments::identifier($column);
        $bound = SqlFragments::isNaive($this->catalog->type(
            table: $this->map->table,
            column: $column,
        )) ? SqlFragments::inApplicationZone('CAST(? AS timestamptz)') : 'CAST(? AS timestamptz)';

        return array_values(array_map(
            callback: fn (object $row) => self::keyOf(((array) $row)['key'] ?? null),
            array: $this->connection->select(
                query: "SELECT {$key} AS key FROM {$legacy} WHERE {$updated} >= {$bound}"
                    ." UNION SELECT {$key} FROM {$legacy} WHERE {$updated} IS NULL"
                    .' UNION SELECT legacy_id FROM '.SqlFragments::identifier(Ledger::table($this->map->table))." WHERE status IN ('refused', 'failed')"
                    .' ORDER BY 1',
                bindings: [$since],
                useReadPdo: false,
            ),
        ));
    }

    private function since(null|CarbonInterval $margin): null|string
    {
        $bound = $this->watermark->bound();

        if ($bound === null) {
            return null;
        }

        return (new DateTimeImmutable($bound))->sub($margin ?? CarbonInterval::seconds(self::MARGIN))->format('Y-m-d\TH:i:s.uP');
    }

    private function rootExists(int|string $root): bool
    {
        return $this->connection->table($this->map->primary->table)->useWritePdo()->where(
            column: $this->map->primary->key,
            operator: '=',
            value: $root,
        )->exists();
    }

    private function roundTrip(): RoundTrip
    {
        if ($this->roundTrip === null) {
            $compiler = new ViewCompiler(
                columnCatalog: new ColumnCatalog(
                    connection: $this->connection,
                    throughWriteConnection: true,
                ),
                connection: $this->connection,
            );
            $this->roundTrip = new RoundTrip(
                map: $this->map,
                connection: $this->connectionOf(),
                servedSelect: $compiler->beforeInstall($this->map),
                unhiddenSelect: $compiler->beforeInstall(
                    map: $this->map,
                    hides: false,
                ),
            );
        }

        return $this->roundTrip;
    }

    private function unit(): UnitOfWork
    {
        return new UnitOfWork(
            map: $this->map,
            connection: $this->connectionOf(),
            locksRetained: false,
        );
    }

    private function connectionOf(): Connection
    {
        return $this->connection instanceof Connection ? $this->connection : throw new LogicException('The backfill writes through an Illuminate\\Database\\Connection.');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function legacy(array $row): Model
    {
        return (new $this->map->model)->setConnection($this->connectionName)->newInstance(
            attributes: [],
            exists: true,
        )->setRawAttributes(
            attributes: $row,
            sync: true,
        );
    }

    private function refuseUnwalkable(int $chunk): void
    {
        if ($chunk < 1) {
            throw new LogicException("A backfill reads at least one row per chunk, not {$chunk}.");
        }

        $legacy = $this->catalog->of($this->map->table);

        if ($legacy === []) {
            throw InvalidCompatibilityMap::because(
                map: $this->map->table,
                problem: "the legacy table {$this->map->table} does not exist",
            );
        }

        if (LegacyBuilder::serves(new $this->map->model)) {
            throw InvalidCompatibilityMap::cannot(
                map: $this->map->table,
                operation: 'backfill',
                because: "{$this->map->table} is already installed as a view, and the backfill walks the legacy table while it is still a table",
            );
        }

        if (! array_key_exists(
            key: $this->map->successorKey,
            array: $legacy,
        ) || ! $this->ledger->exists() || ! $this->watermark->tracked()) {
            throw InvalidCompatibilityMap::cannot(
                map: $this->map->table,
                operation: 'backfill',
                because: "Compatibility::prepare() adds {$this->map->table}.{$this->map->successorKey}, creates ".Ledger::table($this->map->table).' and inserts the '.Watermark::TABLE." row of {$this->map->table}, and it has not run; run Compatibility::prepare() first",
            );
        }

        foreach ($this->map->morphs === [] ? [$this->map] : $this->map->morphs as $branch) {
            foreach (array_keys(array_filter(
                array: $branch->attributes,
                callback: fn ($attribute) => $attribute->column() !== null || $attribute->isExternal(),
            )) as $name) {
                if (! array_key_exists(
                    key: $name,
                    array: $legacy,
                )) {
                    throw InvalidCompatibilityMap::because(
                        map: $this->map->table,
                        problem: "{$this->map->table} has no column {$name}, so the backfill has no value to write for it",
                    );
                }
            }
        }
    }

    private function updatedAt(): null|string
    {
        $legacy = new $this->map->model;
        $column = $legacy->usesTimestamps() ? $legacy->getUpdatedAtColumn() : null;

        return $column !== null && array_key_exists(
            key: $column,
            array: $this->catalog->of($this->map->table),
        ) ? $column : null;
    }

    private function inApplicationZone(): null|string
    {
        $session = $this->connection->transactionLevel() > 1 ? $this->connection->scalar(
            query: "select current_setting('TimeZone')",
            useReadPdo: false,
        ) : null;
        $this->connection->select(
            query: "select set_config('TimeZone', ?, true)",
            bindings: [Config::string(
                key: 'app.timezone',
                default: 'UTC',
            )],
            useReadPdo: false,
        );

        return is_string($session) ? $session : null;
    }

    private function restoreZone(null|string $session): void
    {
        if ($session !== null) {
            $this->connection->select(
                query: "select set_config('TimeZone', ?, true)",
                bindings: [$session],
                useReadPdo: false,
            );
        }
    }

    private static function rootKeyOf(mixed $key): null|int|string
    {
        return is_int($key) || is_string($key) ? $key : null;
    }

    private static function keyOf(mixed $key): int|string
    {
        return is_int($key) || is_string($key) ? $key : throw new LogicException('A legacy row has no key to backfill it by.');
    }
}
