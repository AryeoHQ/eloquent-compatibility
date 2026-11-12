<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use LogicException;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\SuccessorsMoved;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use Throwable;

/**
 * @internal
 */
final class Backfiller
{
    public const int CHUNK = 1000;

    public const int MARGIN = 300;

    private const string SAVEPOINT = 'compatibility_backfill_row';

    private const array WAITED_STATES = ['55P03', '40P01'];

    private const string DATA_EXCEPTION = '22';

    private const string CANCELED = '57014';

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

    /**
     * @param  null|Closure(Pass, int|string, int|string): void  $progress
     */
    public function run(int $chunk = self::CHUNK, null|CarbonInterval $margin = null, null|Closure $progress = null): Pass
    {
        $this->watermark->hold('backfill');

        try {
            $this->refuseUnwalkable($chunk);
            $since = $this->since($margin);

            if ($since !== null) {
                $this->updatedAtOf('backfill');
            }

            $started = $this->watermark->start();
            $pass = $this->walk(
                chunk: $chunk,
                since: $since,
                scoped: true,
                deletes: true,
                before: null,
                progress: $progress,
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
                before: null,
            );
        }

        return $pass;
    }

    public function deletions(): Pass
    {
        $this->roundTrip();
        $pass = new Pass;
        $this->deleteGone(
            pass: $pass,
            scoped: true,
            chunk: self::CHUNK,
        );

        return $pass;
    }

    /**
     * @param  Closure(int|string): void  $before
     */
    public function finalPass(null|CarbonInterval $margin, Closure $before): Pass
    {
        return $this->walk(
            chunk: self::CHUNK,
            since: $this->since($margin),
            scoped: false,
            deletes: false,
            before: $before,
            progress: null,
        );
    }

    public static function waited(Throwable $exception): null|QueryException
    {
        while ($exception instanceof DeadlockException && $exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }

        return $exception instanceof QueryException && in_array(
            needle: $exception->getCode(),
            haystack: self::WAITED_STATES,
            strict: true,
        ) ? $exception : null;
    }

    public static function canceled(Throwable $exception): null|QueryException
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException && $cause->getCode() === self::CANCELED) {
                return $cause;
            }
        }

        return null;
    }

    public function refuseUnconvergeable(int $passSize): void
    {
        if ($this->updatedAt() === null) {
            throw InvalidCompatibilityMap::cannot(
                map: $this->map->table,
                operation: 'install',
                because: "{$this->map->table} has no updated_at column its legacy model writes, so install() cannot limit its catch-up to the rows written since the backfill, and would walk every legacy row while it holds the lock",
            );
        }

        $unstamped = (int) $this->connection->table($this->map->table)->useWritePdo()->whereNull($this->updatedAtOf('install'))->count();

        if ($unstamped >= $passSize) {
            throw InvalidCompatibilityMap::cannot(
                map: $this->map->table,
                operation: 'install',
                because: "{$unstamped} {$this->map->table} rows have a NULL ".$this->updatedAtOf('install').", and every pass walks them again, the final pass under the lock included; install() takes the lock only while fewer than its passSize of {$passSize} do. Stamp ".$this->updatedAtOf('install').' on those rows, then install again',
            );
        }
    }

    /**
     * @param  null|Closure(int|string): void  $before
     * @param  null|Closure(Pass, int|string, int|string): void  $progress
     */
    private function walk(int $chunk, null|string $since, bool $scoped, bool $deletes, null|Closure $before, null|Closure $progress): Pass
    {
        $this->roundTrip();
        $pass = new Pass;
        $last = null;

        do {
            $read = new Pass;
            $keys = $since === null ? null : $this->candidates(
                since: $since,
                after: $last,
                chunk: $chunk,
            );
            $rows = $this->chunk(
                pass: $read,
                rows: $keys === null ? fn () => $this->rowsAfter(
                    last: $last,
                    chunk: $chunk,
                ) : fn () => $this->rowsOf($keys),
                scoped: $scoped,
                before: $before,
            );
            $walked = $keys ?? array_map(
                callback: fn (array $row) => self::keyOf($row[$this->key] ?? null),
                array: $rows,
            );
            $pass->absorb($read);

            if ($walked !== [] && $progress !== null) {
                $progress($read, $walked[0], $walked[array_key_last($walked)]);
            }

            $last = $walked === [] ? $last : $walked[array_key_last($walked)];
        } while (count($walked) === $chunk);

        if ($deletes) {
            $this->deleteGone(
                pass: $pass,
                scoped: $scoped,
                chunk: $chunk,
            );
        }

        return $pass;
    }

    /**
     * @param  Closure(): list<array<string, mixed>>  $rows
     * @param  null|Closure(int|string): void  $before
     * @return list<array<string, mixed>>
     */
    private function chunk(Pass $pass, Closure $rows, bool $scoped, null|Closure $before): array
    {
        if ($this->commitsEachRow($scoped)) {
            $read = $this->connection->transaction(function () use ($rows): array {
                $zone = $this->inApplicationZone();
                $read = $rows();
                $this->restoreZone($zone);

                return $read;
            });
            $previous = $this->previous($read);

            foreach ($read as $row) {
                $this->connection->transaction(fn () => $this->row(
                    pass: $pass,
                    row: $row,
                    previous: $previous,
                    scoped: $scoped,
                    before: $before,
                ));
            }

            $pass->walked += count($read);

            return $read;
        }

        return $this->connection->transaction(function () use ($pass, $rows, $scoped, $before): array {
            $zone = $this->inApplicationZone();
            $read = $rows();
            $previous = $this->previous($read);

            foreach ($read as $row) {
                $this->row(
                    pass: $pass,
                    row: $row,
                    previous: $previous,
                    scoped: $scoped,
                    before: $before,
                );
            }

            $pass->walked += count($read);
            $this->restoreZone($zone);

            return $read;
        });
    }

    private function commitsEachRow(bool $scoped): bool
    {
        return $scoped && $this->map->scope !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $read
     * @return array<string, Outcome>
     */
    private function previous(array $read): array
    {
        return $this->ledger->statuses(array_map(
            callback: fn (array $row) => self::keyOf($row[$this->key] ?? null),
            array: $read,
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Outcome>  $previous
     * @param  null|Closure(int|string): void  $before
     */
    private function row(Pass $pass, array $row, array $previous, bool $scoped, null|Closure $before): void
    {
        $key = self::keyOf($row[$this->key] ?? null);
        $named = self::rootKeyOf($row[$this->map->successorKey] ?? null);

        if ($before !== null) {
            $before($key);
        }

        [$outcome, $successor, $reason] = $this->attempt(
            write: fn () => $this->write(
                key: $key,
                named: $named,
                scoped: $scoped,
            ),
            key: $key,
            successor: $named,
            scoped: $scoped,
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

    /**
     * @param  Closure(): array{Outcome, null|int|string}  $write
     * @return array{Outcome, null|int|string, null|string}
     */
    private function attempt(Closure $write, int|string $key, null|int|string $successor, bool $scoped, bool $retried = false): array
    {
        $this->connection->unprepared('SAVEPOINT '.self::SAVEPOINT);

        try {
            [$outcome, $written] = $write();
        } catch (Throwable $exception) {
            $this->connection->unprepared('ROLLBACK TO SAVEPOINT '.self::SAVEPOINT);
            $this->connection->unprepared('RELEASE SAVEPOINT '.self::SAVEPOINT);

            $canceled = $scoped ? null : self::canceled($exception);

            if ($canceled !== null) {
                throw $canceled;
            }

            if (self::waited($exception) !== null) {
                if (! $scoped || $retried) {
                    throw $exception;
                }

                return $this->attempt(
                    write: $write,
                    key: $key,
                    successor: $successor,
                    scoped: $scoped,
                    retried: true,
                );
            }

            if ($exception instanceof Refusal) {
                return [Outcome::Refused, $successor, $exception->getMessage()];
            }

            $refusal = $exception instanceof CompatibilityWriteRefused || $exception instanceof SuccessorsMoved ? $exception : $this->unparsable(
                key: $key,
                exception: $exception,
            );

            if ($refusal !== null) {
                Log::info("Compatibility map '{$this->map->table}' backfill: the write of {$this->map->table} {$this->key} {$key} was refused: {$refusal->getMessage()}");

                return [Outcome::Refused, $successor, $refusal instanceof SuccessorsMoved ? CompatibilityWriteRefused::movedReason($refusal) : $refusal->reason];
            }

            Log::warning("Compatibility map '{$this->map->table}' backfill: the write of {$this->map->table} {$this->key} {$key} failed", ['exception' => $exception]);

            return [Outcome::Failed, $successor, self::reason($exception)];
        }

        $this->connection->unprepared('RELEASE SAVEPOINT '.self::SAVEPOINT);

        return [$outcome, $written, null];
    }

    private function unparsable(int|string $key, Throwable $exception): null|CompatibilityWriteRefused
    {
        $row = RoundTrip::unparsed($exception) ? $this->current(
            key: $key,
            lock: false,
        ) : null;

        if ($row === null) {
            return null;
        }

        $legacy = $this->legacy($row);
        $attribute = $this->roundTrip()->unparsable(
            variant: $this->variantFor($legacy),
            legacy: $legacy,
            key: $key,
        );

        return $attribute === null ? null : CompatibilityWriteRefused::unparsable(
            model: $this->map->model,
            attribute: $attribute,
            key: $key,
            previous: $exception,
        );
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
        $touched = $scoped && $unit->scopes() ? $this->touched(
            unit: $unit,
            writer: $writer,
            key: $key,
            named: $named,
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
    private function touched(UnitOfWork $unit, RecordWriter $writer, int|string $key, null|int|string $named): array
    {
        if ($named !== null && $this->rootExists($named)) {
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
        $legacy = $this->legacy($row);

        if ($this->changed(
            variant: $this->variantFor($legacy),
            legacy: $legacy,
            key: $key,
            row: $row,
        )[0] === []) {
            return [Outcome::Unchanged, $rootKey];
        }

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

        [$mapped, $served] = $this->changed(
            variant: $this->variantFor($legacy),
            legacy: $legacy,
            key: $key,
            row: $row,
        );

        if ($mapped === []) {
            return [Outcome::Unchanged, $rootKey];
        }

        return [$writer->catchUp(
            legacy: $legacy,
            key: $key,
            graph: $graph,
            original: $this->legacy($served),
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
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function changed(ResolvedMap $variant, Model $legacy, int|string $key, array $row): array
    {
        $candidates = [];
        $compared = [];

        foreach ($variant->attributes as $name => $attribute) {
            if (! array_key_exists(
                key: $name,
                array: $row,
            ) || ($attribute->isExternal() && $attribute->operation() === null)) {
                continue;
            }

            if ($attribute->isExternal()) {
                $candidates[$name] = $row[$name];
            } elseif (! RoundTrip::skips(
                attribute: $attribute,
                origin: Origin::Backfill,
            )) {
                $candidates[$name] = $row[$name];
                $compared[] = $name;
            }
        }

        [$served, $differs] = $this->roundTrip()->served(
            variant: $variant,
            legacy: $legacy,
            key: $key,
            names: $compared,
        ) ?? [[], []];
        $changed = array_filter(
            array: $candidates,
            callback: fn (string $name): bool => ($differs[$name] ?? true) === true,
            mode: ARRAY_FILTER_USE_KEY,
        );

        if ($changed === []) {
            return [[], $served];
        }

        foreach ($variant->attributes as $name => $attribute) {
            $owner = $variant->owners[$name];
            $successor = new $owner->model;

            if (array_key_exists(
                key: $name,
                array: $row,
            ) && $successor->usesTimestamps() && $attribute->column() === $successor->getUpdatedAtColumn() && ! RoundTrip::skips(
                attribute: $attribute,
                origin: Origin::Backfill,
            )) {
                $changed[$name] = $row[$name];
            }
        }

        return [$changed, $served];
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

    private function deleteGone(Pass $pass, bool $scoped, int $chunk): void
    {
        $after = null;

        do {
            $batch = $this->ledger->gone(
                limit: $chunk,
                after: $after,
            );

            if ($batch === []) {
                return;
            }

            $after = $batch[array_key_last($batch)][0];

            if ($this->commitsEachRow($scoped)) {
                foreach ($batch as [$key, $successor, $status]) {
                    $this->connection->transaction(fn () => $this->gone(
                        pass: $pass,
                        key: $key,
                        successor: $successor,
                        status: $status,
                        scoped: $scoped,
                    ));
                }

                continue;
            }

            $this->connection->transaction(function () use ($batch, $pass, $scoped): void {
                foreach ($batch as [$key, $successor, $status]) {
                    $this->gone(
                        pass: $pass,
                        key: $key,
                        successor: $successor,
                        status: $status,
                        scoped: $scoped,
                    );
                }
            });
        } while (count($batch) === $chunk);
    }

    private function gone(Pass $pass, int|string $key, null|int|string $successor, Outcome $status, bool $scoped): void
    {
        $pass->gone++;
        [$outcome, , $reason] = $this->attempt(
            write: fn () => [$this->delete(
                key: $key,
                successor: $successor,
                scoped: $scoped,
            ), $successor],
            key: $key,
            successor: $successor,
            scoped: $scoped,
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
    private function candidates(string $since, null|int|string $after, int $chunk): array
    {
        $column = $this->updatedAtOf('backfill');
        $legacy = SqlFragments::identifier($this->map->table);
        $key = SqlFragments::identifier($this->key);
        $updated = SqlFragments::identifier($column);
        $bound = SqlFragments::isNaive($this->catalog->type(
            table: $this->map->table,
            column: $column,
        )) ? SqlFragments::inApplicationZone('CAST(? AS timestamptz)') : 'CAST(? AS timestamptz)';
        $past = $after === null ? '' : ' AND %s > ?';

        return array_values(array_map(
            callback: fn (object $row) => self::keyOf(((array) $row)['key'] ?? null),
            array: $this->connection->select(
                query: "SELECT {$key} AS key FROM {$legacy} WHERE {$updated} >= {$bound}".sprintf($past, $key)
                    ." UNION SELECT {$key} FROM {$legacy} WHERE {$updated} IS NULL".sprintf($past, $key)
                    .' UNION '.$this->ledger->blockedKeys().sprintf($past, 'ledger.legacy_id')
                    .' ORDER BY 1 LIMIT ?',
                bindings: $after === null ? [$since, $chunk] : [$since, $after, $after, $after, $chunk],
                useReadPdo: false,
            ),
        ));
    }

    private function updatedAtOf(string $operation): string
    {
        return $this->updatedAt() ?? throw InvalidCompatibilityMap::cannot(
            map: $this->map->table,
            operation: $operation,
            because: "{$this->map->table} has no updated_at column of ".class_basename($this->map->model).', so no watermark limits a pass',
        );
    }

    private function since(null|CarbonInterval $margin): null|string
    {
        $bound = $this->watermark->bound();

        if ($bound === null) {
            return null;
        }

        return Watermark::before(
            moment: $bound,
            margin: $margin ?? CarbonInterval::seconds(self::MARGIN),
        );
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
                columnCatalog: $this->catalog,
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
            hides: false,
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

    private static function reason(Throwable $exception): string
    {
        $message = $exception instanceof QueryException ? $exception->getPrevious()?->getMessage() ?? $exception->getMessage() : $exception->getMessage();
        $first = (string) strtok(
            string: $message,
            token: "\n",
        );

        if ($exception instanceof QueryException && str_starts_with(
            haystack: (string) $exception->getCode(),
            needle: self::DATA_EXCEPTION,
        ) && preg_match(
            pattern: '/^SQLSTATE\[[0-9A-Z]{5}\]: [^:]+/',
            subject: $first,
            matches: $condition,
        ) === 1) {
            $first = $condition[0];
        }

        return $exception::class.': '.$first;
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
