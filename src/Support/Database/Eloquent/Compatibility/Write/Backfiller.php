<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;
use LogicException;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\IsNotNull;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\ReadAs;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class Backfiller
{
    public const int CHUNK = 1000;

    public const string BACKFILLS = 'compatibility_backfills';

    private const int BINDINGS = 65535;

    private const string SOURCE = 'compatibility_source';

    private const string TARGET = 'compatibility_target';

    private const string KEY = 'compatibility_key';

    private ColumnCatalog $catalog;

    private string $key;

    private null|string $connectionName;

    public function __construct(private ResolvedMap $map, private ConnectionInterface $connection)
    {
        $legacy = new $map->model;
        $this->catalog = new ColumnCatalog(
            connection: $connection,
            throughWriteConnection: true,
        );
        $this->key = $legacy->getKeyName();
        $this->connectionName = $legacy->getConnectionName();
    }

    /**
     * @param  null|Closure(list<int|string>, int): void  $chunked
     * @return array{read: int, changed: int, watermark: string}
     */
    public function run(int $chunk = self::CHUNK, null|string $since = null, null|Closure $chunked = null): array
    {
        $this->refuseUnwalkable($chunk);
        $previous = $this->remembered();

        if ($since !== null && $previous !== null && new DateTimeImmutable($since) > new DateTimeImmutable($previous)) {
            throw new LogicException("--since takes a watermark no later than {$previous}, which the last backfill of {$this->map->table} remembers, not ".(new DateTimeImmutable($since))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z').'; a later one skips the legacy rows updated in between.');
        }

        $updatedAt = $this->now('backfill');
        [$read, $changed] = $this->walk(
            chunk: $chunk,
            scope: $since === null ? null : $this->updatedSince(self::watermark($since)),
            chunked: $chunked,
        );
        $changed += $this->deleteGone();
        $this->remember(
            watermark: $updatedAt,
            rows: $read,
        );

        return ['read' => $read, 'changed' => $changed, 'watermark' => $updatedAt];
    }

    /**
     * @return array{int, int, string}
     */
    public function catchUp(string $watermark): array
    {
        $this->refuseUnwalkable(self::CHUNK);
        $updatedAt = $this->now('install');
        $updated = $this->updatedAt();
        $since = $updated === null ? null : $this->updatedSince($watermark);
        $scope = $since === null ? null : fn (QueryBuilder $query) => $query->where($since)->orWhereNull($updated)->orWhereNull($this->map->successorKey);
        [$read, $changed] = $this->walk(
            chunk: self::CHUNK,
            scope: $scope,
            chunked: null,
        );

        return [$changed + $this->deleteGone(), $read, $updatedAt];
    }

    public function refuseUnconvergeable(): void
    {
        if ($this->updatedAt() === null) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'install', "{$this->map->table} has no updated_at column its legacy model writes, so install() cannot limit its catch-up to the rows written since the backfill, and would walk every legacy row while it holds the lock");
        }
    }

    public function refuseMisplaced(): void
    {
        $type = (string) $this->catalog->type($this->map->table, $this->key);
        $misplaced = [];
        $last = null;

        do {
            $query = $this->connection->table($this->map->table)->useWritePdo()->orderBy($this->key)->limit(self::CHUNK);

            if ($last !== null) {
                $query->where($this->key, '>', $last);
            }

            $rows = array_map(fn (object $row) => (array) $row, $query->get()->all());
            $chosen = [];

            foreach ($rows as $row) {
                $chosen[$this->chosen($row)][] = self::keyOf($row[$this->key] ?? null);
            }

            foreach ($chosen as $model => $keys) {
                $branch = $this->map->morphs[$model];
                $primary = SqlFragments::identifier($branch->primary->table);
                $legacy = SqlFragments::identifier($this->map->table);
                $target = $branch->target === null ? '' : ' AND EXISTS (SELECT FROM '.SqlFragments::identifier($branch->target->table).' WHERE '.implode(' AND ', ViewCompiler::conditions($branch->target, true)).')';
                $misplaced = [...$misplaced, ...array_column($this->connection->select(
                    "SELECT CAST(chosen.key AS {$type}) AS key FROM jsonb_array_elements_text(CAST(? AS jsonb)) AS chosen (key)"
                        ." JOIN {$legacy} ON {$legacy}.".SqlFragments::identifier($this->key)." = CAST(chosen.key AS {$type})"
                        ." WHERE NOT EXISTS (SELECT FROM {$primary} WHERE {$primary}.".SqlFragments::identifier($branch->primary->key)." = {$legacy}.".SqlFragments::identifier($this->map->successorKey).$target.')',
                    [json_encode($keys, JSON_THROW_ON_ERROR)],
                    false,
                ), 'key')];
            }

            $last = $rows === [] ? $last : self::keyOf(end($rows)[$this->key] ?? null);
        } while (count($rows) === self::CHUNK);

        if ($misplaced !== []) {
            sort($misplaced);

            throw InvalidCompatibilityMap::cannot(
                map: $this->map->table,
                operation: 'install',
                because: count($misplaced)." {$this->map->table} rows point at no target their relatedMorph() choose: closure picks; the first are {$this->key} ".implode(
                    separator: ', ',
                    array: array_slice(
                        array: $misplaced,
                        offset: 0,
                        length: 5,
                    ),
                ).'. Run php artisan compatibility:backfill '.$this->map->model.', then install()',
            );
        }
    }

    public static function ledger(string $table): string
    {
        return SqlFragments::objectName(
            map: $table,
            name: $table.'_backfill',
        );
    }

    /**
     * @return list<string>
     */
    public static function storage(ResolvedMap $map, ConnectionInterface $connection): array
    {
        $catalog = new ColumnCatalog(
            connection: $connection,
            throughWriteConnection: true,
        );
        $key = (new $map->model)->getKeyName();
        $keyType = $catalog->type(
            table: $map->table,
            column: $key,
        ) ?? throw InvalidCompatibilityMap::because(
            map: $map->table,
            problem: "{$map->table} has no column {$key}, the key of ".class_basename($map->model),
        );
        $rootType = $catalog->type(
            table: $map->primary->table,
            column: $map->primary->key,
        ) ?? throw InvalidCompatibilityMap::because(
            map: $map->table,
            problem: "the successor table {$map->primary->table} has no column {$map->primary->key}",
        );
        $ledger = self::ledger($map->table);

        return [
            'CREATE TABLE IF NOT EXISTS '.SqlFragments::identifier(self::BACKFILLS)." (\n  legacy_type text PRIMARY KEY,\n  watermark_at timestamptz,\n  pass integer NOT NULL DEFAULT 0,\n  pass_started_at timestamptz,\n  pass_finished_at timestamptz,\n  pass_rows bigint\n)",
            'INSERT INTO '.SqlFragments::identifier(self::BACKFILLS).' (legacy_type) VALUES ('.SqlFragments::literal($map->table).') ON CONFLICT (legacy_type) DO NOTHING',
            'CREATE TABLE IF NOT EXISTS '.SqlFragments::identifier($ledger)." (\n  legacy_id {$keyType} PRIMARY KEY,\n  successor_id {$rootType},\n  status text NOT NULL,\n  reason text,\n  attempts integer NOT NULL DEFAULT 0,\n  attempted_at timestamptz NOT NULL\n)",
            'CREATE INDEX IF NOT EXISTS '.SqlFragments::identifier(SqlFragments::objectName(
                map: $map->table,
                name: $ledger.'_status_index',
            )).' ON '.SqlFragments::identifier($ledger)." (status) WHERE status IN ('refused', 'failed')",
        ];
    }

    public static function forgetStatement(ResolvedMap $map): string
    {
        return "DO \$compatibility\$\nBEGIN\n"
            ."  IF to_regclass(quote_ident(current_schema()) || '.' || ".SqlFragments::literal(self::BACKFILLS).") IS NOT NULL THEN\n"
            .'    DELETE FROM '.SqlFragments::identifier(self::BACKFILLS).' WHERE legacy_type = '.SqlFragments::literal($map->table).";\n"
            ."  END IF;\nEND\n\$compatibility\$";
    }

    public function remembered(): null|string
    {
        if (! $this->exists(self::BACKFILLS)) {
            return null;
        }

        $watermark = $this->connection->scalar(
            query: "select to_char(watermark_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') from ".SqlFragments::identifier(self::BACKFILLS).' where legacy_type = ?',
            bindings: [$this->map->table],
            useReadPdo: false,
        );

        return is_string($watermark) ? $watermark : null;
    }

    public function remember(string $watermark, int $rows): void
    {
        $this->connection->update(
            query: 'update '.SqlFragments::identifier(self::BACKFILLS).' set watermark_at = cast(? as timestamptz), pass = pass + 1, pass_started_at = cast(? as timestamptz), pass_finished_at = now(), pass_rows = ? where legacy_type = ?',
            bindings: [$watermark, $watermark, $rows, $this->map->table],
        );
    }

    public static function watermark(string $since): string
    {
        try {
            return (new DateTimeImmutable($since))->format('Y-m-d\TH:i:s.uP');
        } catch (Exception) {
            throw new LogicException("--since takes the watermark a backfill printed, such as 2026-11-02T13:00:00Z, not {$since}.");
        }
    }

    /**
     * @param  null|Closure(QueryBuilder): mixed  $scope
     * @param  null|Closure(list<int|string>, int): void  $chunked
     * @return array{int, int}
     */
    private function walk(int $chunk, null|Closure $scope, null|Closure $chunked): array
    {
        $read = 0;
        $changed = 0;
        $last = null;

        do {
            $rows = $this->connection->transaction(function () use ($chunk, $scope, $chunked, $last, &$changed): array {
                $zone = $this->inApplicationZone();
                $query = $this->connection->table($this->map->table)->useWritePdo()->orderBy($this->key)->limit($chunk);

                if ($scope !== null) {
                    $query->where($scope);
                }

                if ($last !== null) {
                    $query->where($this->key, '>', $last);
                }

                $rows = array_map(fn (object $row) => (array) $row, $query->get()->all());
                $keys = array_map(fn (array $row) => self::keyOf($row[$this->key] ?? null), $rows);

                if ($rows !== []) {
                    $written = $this->write($rows);
                    $changed += $written;

                    if ($chunked !== null) {
                        $chunked($keys, $written);
                    }
                }

                $this->restoreZone($zone);

                return $rows;
            });

            $read += count($rows);
            $last = $rows === [] ? $last : self::keyOf(end($rows)[$this->key] ?? null);
        } while (count($rows) === $chunk);

        return [$read, $changed];
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     */
    private function write(array $rows): int
    {
        $roots = $this->roots($rows);
        $changed = $this->map->morphs === [] ? $this->writeBranch(
            branch: $this->map,
            rows: $rows,
            roots: $roots,
        ) : $this->writeMorphs(
            rows: $rows,
            roots: $roots,
        );
        $this->record($roots);

        return $changed;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     * @return array<int|string, array{int|string, bool}>
     */
    private function roots(array $rows): array
    {
        $primary = $this->map->primary;
        $named = [];
        $unkeyed = [];

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            $root = $row[$this->map->successorKey] ?? null;

            if (is_int($root) || is_string($root)) {
                $named[$key] = $root;
            } else {
                $unkeyed[] = $key;
            }
        }

        $allocated = $unkeyed === [] ? [] : array_combine(
            keys: $unkeyed,
            values: $this->allocate(count($unkeyed)),
        );

        foreach (array_chunk(
            array: $allocated === [] ? [] : $this->fillKeys($allocated),
            length: self::BINDINGS - 2,
        ) as $batch) {
            foreach ($this->connection->table($this->map->table)->useWritePdo()->whereIn(
                column: $this->key,
                values: $batch,
            )->whereNotNull($this->map->successorKey)->pluck(
                column: $this->map->successorKey,
                key: $this->key,
            ) as $key => $root) {
                if (is_int($root) || is_string($root)) {
                    $named[$key] = $root;
                    unset($allocated[$key]);
                }
            }
        }

        $existing = [];

        foreach (array_chunk(
            array: array_values($named),
            length: self::BINDINGS - 2,
        ) as $batch) {
            foreach ($this->connection->table($primary->table)->useWritePdo()->whereIn(
                column: $primary->key,
                values: $batch,
            )->pluck($primary->key) as $found) {
                $existing[(string) $found] = true;
            }
        }

        $roots = [];

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            $root = $named[$key] ?? $allocated[$key];
            $roots[$key] = [$root, array_key_exists(
                key: $key,
                array: $named,
            ) && array_key_exists(
                key: (string) $root,
                array: $existing,
            )];
        }

        return $roots;
    }

    /**
     * @return non-empty-list<int|string>
     */
    private function allocate(int $count): array
    {
        $root = new $this->map->primary->model;

        if ($root->usesUniqueIds() && in_array(
            needle: $root->getKeyName(),
            haystack: $root->uniqueIds(),
            strict: true,
        )) {
            return array_map(
                callback: fn () => $root->newUniqueId(),
                array: range(
                    start: 1,
                    end: $count,
                ),
            );
        }

        $default = $this->catalog->of($this->map->primary->table)[$this->map->primary->key]->default ?? null;

        if ($default === null) {
            throw InvalidCompatibilityMap::cannot(
                map: $this->map->table,
                operation: 'backfill',
                because: "{$this->map->table} rows hold no {$this->map->successorKey}, and {$this->map->primary->table}.{$this->map->primary->key} has no default and ".class_basename($this->map->primary->model).' generates no unique id, so the backfill has no key to create their roots with',
            );
        }

        $keys = array_values(array_map(
            callback: self::keyOf(...),
            array: array_column(
                array: $this->connection->select(
                    query: "select {$default} as key from generate_series(1, ?)",
                    bindings: [$count],
                    useReadPdo: false,
                ),
                column_key: 'key',
            ),
        ));

        return $keys === [] ? throw new LogicException("{$this->map->primary->table} allocated no key.") : $keys;
    }

    /**
     * @param  array<int|string, int|string>  $allocated
     * @return list<int|string>
     */
    private function fillKeys(array $allocated): array
    {
        $types = [(string) $this->catalog->type(
            table: $this->map->table,
            column: $this->key,
        ), (string) $this->catalog->type(
            table: $this->map->primary->table,
            column: $this->map->primary->key,
        )];
        $legacy = SqlFragments::identifier($this->map->table);
        $successorKey = SqlFragments::identifier($this->map->successorKey);
        $pairs = array_map(fn (int|string $legacyKey, int|string $root) => [$legacyKey, $root], array_keys($allocated), $allocated);
        $unfilled = array_fill_keys(
            keys: array_map(
                callback: strval(...),
                array: array_keys($allocated),
            ),
            value: true,
        );

        foreach ($this->batches(
            rows: $pairs,
            width: 2,
        ) as $batch) {
            foreach ($this->connection->select(
                query: "UPDATE {$legacy} SET {$successorKey} = source.compatibility_root"
                    ."\nFROM (VALUES ".implode(
                        separator: ', ',
                        array: array_fill(
                            start_index: 0,
                            count: count($batch),
                            value: "(CAST(? AS {$types[0]}), CAST(? AS {$types[1]}))",
                        ),
                    ).') AS source (compatibility_legacy, compatibility_root)'
                    ."\nWHERE {$legacy}.".SqlFragments::identifier($this->key)." = source.compatibility_legacy AND {$legacy}.{$successorKey} IS NULL"
                    ."\nRETURNING {$legacy}.".SqlFragments::identifier($this->key).' AS '.SqlFragments::identifier(self::KEY),
                bindings: array_merge(...$batch),
                useReadPdo: false,
            ) as $filled) {
                unset($unfilled[(string) self::keyOf(((array) $filled)[self::KEY] ?? null)]);
            }
        }

        return array_values(array_filter(
            array: array_keys($allocated),
            callback: fn (int|string $legacyKey) => array_key_exists(
                key: (string) $legacyKey,
                array: $unfilled,
            ),
        ));
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     * @param  array<int|string, array{int|string, bool}>  $roots
     */
    private function writeMorphs(array $rows, array $roots): int
    {
        $routed = [];

        foreach ($rows as $row) {
            $routed[$this->chosen($row)][] = $row;
        }

        $changed = 0;

        foreach ($this->map->morphs as $target => $variant) {
            if (array_key_exists($target, $routed)) {
                $changed += $this->writeMorph(
                    variant: $variant,
                    rows: $routed[$target],
                    roots: $roots,
                );
            }
        }

        return $changed;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     * @param  array<int|string, array{int|string, bool}>  $roots
     */
    private function writeMorph(ResolvedMap $variant, array $rows, array $roots): int
    {
        $morph = $variant->morph ?? throw new LogicException("Compatibility map '{$this->map->table}' backfills a relatedMorph() target of no relatedMorph().");
        $related = $variant->target ?? throw new LogicException("Compatibility map '{$this->map->table}' backfills no relatedMorph() target.");
        $primaryTable = $variant->primary->table;
        $stored = [];
        $external = [];

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            [$stored[$key], $external[$key]] = $this->split($variant, $row, $key);
        }

        $changed = 0;
        $existing = $this->held(
            branch: $variant,
            keys: $this->rootsOf(
                roots: $roots,
                keys: array_keys($stored),
            ),
        )->keyBy(fn (Model $root) => self::keyOf($root->getKey()));
        $targets = $this->targets($related, $morph->typeColumn, $morph->idColumn, $existing);
        $read = [];

        foreach ($variant->externals() as $name) {
            $owner = $variant->owners[$name];
            $owned = match ($owner) {
                $variant->primary => $existing,
                $related->successor => $targets,
                default => null,
            };

            if ($owned !== null) {
                $read[$name] = $owned->isEmpty() ? [] : $variant->attributes[$name]->readExternally($owned);
            }
        }

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            $primary = $existing[$roots[$key][0]] ?? (new $variant->primary->model)->setConnection($this->connectionName)->setAttribute(
                key: $variant->primary->key,
                value: $roots[$key][0],
            );
            $type = $primary->getAttributes()[$morph->typeColumn] ?? null;
            $held = $primary->getAttributes()[$morph->idColumn] ?? null;
            $target = $type === $related->morphAlias ? $targets[is_scalar($held) ? (string) $held : ''] ?? null : null;
            $old = $primary->exists && $target === null ? $this->map->morphHolding($type) : null;
            $target ??= (new $related->model)->setConnection($this->connectionName);
            $target->setRawAttributes(array_replace($target->getAttributes(), $this->present($variant, $target, $stored[$key][$related->table])));
            $changed += $this->saveSuccessor($variant, $target, $external[$key], $read, $key, array_keys($stored[$key][$related->table]));
            $primary->setRawAttributes(array_replace($primary->getAttributes(), $this->present($variant, $primary, $stored[$key][$primaryTable]), [$morph->typeColumn => $related->morphAlias, $morph->idColumn => $target->getKey()]));
            $changed += $this->saveSuccessor($variant, $primary, $external[$key], $read, $key, array_keys($stored[$key][$primaryTable]));

            if ($old !== null && $old->target !== null) {
                $changed += $this->deleteTarget($old->target, $held, $key);
            }
        }

        foreach ($variant->related as $dependent) {
            if ($dependent !== $related) {
                $changed += $this->writeRelated(
                    branch: $variant,
                    related: $dependent,
                    stored: $stored,
                    roots: $roots,
                );
            }
        }

        return $changed + $this->writeRelatedExternals(
            branch: $variant,
            stored: $stored,
            external: $external,
            roots: $roots,
        );
    }

    /**
     * @param  array<int|string, array<string, array<string, mixed>>>  $stored
     * @param  array<int|string, array<string, mixed>>  $external
     * @param  array<int|string, array{int|string, bool}>  $roots
     */
    private function writeRelatedExternals(ResolvedMap $branch, array $stored, array $external, array $roots): int
    {
        $owned = [];

        foreach ($branch->externals() as $name) {
            $owner = $branch->owners[$name];

            if ($owner !== $branch->primary && $owner !== $branch->target?->successor) {
                $owned[$owner->table][] = $name;
            }
        }

        if ($owned === []) {
            return 0;
        }

        $parents = [];
        $reached = [];
        $wanted = [];

        foreach ($branch->related as $related) {
            $parents[$related->table] = $related->parentTable;
        }

        foreach ($owned as $table => $names) {
            for ($reach = $table; array_key_exists($reach, $parents); $reach = $parents[$reach]) {
                $reached[$reach] = true;

                foreach ($external as $key => $values) {
                    if (array_filter(array_intersect_key($values, array_flip($names)), fn (mixed $value) => $value !== null) !== []) {
                        $wanted[$key][$reach] = true;
                    }
                }
            }
        }

        $models = [];

        $legacyOf = [];

        foreach (array_keys($stored) as $key) {
            $legacyOf[(string) $roots[$key][0]] = $key;
        }

        foreach ($this->held(
            branch: $branch,
            keys: $this->rootsOf(
                roots: $roots,
                keys: array_keys($stored),
            ),
        ) as $primary) {
            $models[$legacyOf[(string) self::keyOf($primary->getKey())]] = [$branch->primary->table => $primary];
        }

        $changed = 0;

        foreach ($branch->related as $related) {
            if (! array_key_exists($related->table, $reached)) {
                continue;
            }

            foreach ($models as $key => $held) {
                $parent = $held[$related->parentTable] ?? null;
                $relation = $parent === null ? null : ($related->relation)($parent);

                if ($relation !== null && ! $relation instanceof Relation) {
                    throw new LogicException('The related() relation from '.class_basename($related->parent).' to '.class_basename($related->model).' returns no relation.');
                }

                $model = $relation?->getQuery()->useWritePdo()->first();
                $wants = $wanted[$key][$related->table] ?? false;

                if ($model === null && $wants && $relation instanceof HasOneOrMany && array_key_exists($related->table, $owned)) {
                    $model = $relation->make();
                }

                if ($model === null && $wants) {
                    throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', "{$this->map->table} row {$this->key} {$key} holds computedAs() values for {$related->table}, and the related() relation from {$related->parentTable} reads no {$related->table} row the backfill can create for it; its parent row is missing, or it is a BelongsTo");
                }

                if ($model instanceof Model) {
                    $models[$key][$related->table] = $model;
                }
            }

            if (array_key_exists($related->table, $owned)) {
                $changed += $this->saveOwned($branch, $related->table, $owned[$related->table], $models, $stored, $external);
            }
        }

        return $changed;
    }

    /**
     * @param  list<string>  $names
     * @param  array<int|string, array<string, Model>>  $models
     * @param  array<int|string, array<string, array<string, mixed>>>  $stored
     * @param  array<int|string, array<string, mixed>>  $external
     */
    private function saveOwned(ResolvedMap $branch, string $table, array $names, array $models, array $stored, array $external): int
    {
        $located = array_filter(array_map(fn (array $held) => $held[$table] ?? null, $models));
        $first = reset($located);

        if ($first === false) {
            return 0;
        }

        $existing = $first->newCollection(array_values(array_filter($located, fn (Model $model) => $model->exists)));
        $read = [];
        $changed = 0;

        foreach ($names as $name) {
            $read[$name] = $existing->isEmpty() ? [] : $branch->attributes[$name]->readExternally($existing);
        }

        foreach ($located as $key => $model) {
            $changed += $this->saveExternals($branch, $model, array_intersect_key($external[$key] ?? [], array_flip($names)), $read, $key, array_keys($stored[$key][$table] ?? []));
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $external
     * @param  array<string, array<array-key, mixed>>  $read
     * @param  list<string>  $mapped
     */
    private function saveExternals(ResolvedMap $branch, Model $successor, array $external, array $read, int|string $key, array $mapped): int
    {
        $written = false;

        foreach ($external as $name => $value) {
            if (! $successor->exists || ! ExternalAttributes::same($value, $read[$name][$successor->getKey()] ?? null)) {
                $written = $this->writeComputed($branch, $name, $successor, $value, $key) || $written;
            }
        }

        if (! $written && ! $successor->isDirty()) {
            return 0;
        }

        $this->stamp($successor, $mapped);

        if ($successor->save() === false) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', 'a '.class_basename($successor).' listener refused the save of the successor of '.$this->map->table.' row '.$this->key.' '.$key);
        }

        return 1;
    }

    private function writeComputed(ResolvedMap $branch, string $name, Model $successor, mixed $value, int|string $key): bool
    {
        $operation = $branch->attributes[$name]->operation();

        if ($operation === null) {
            return false;
        }

        $instance = UnitOfWork::resolve(
            class: $operation,
            interface: WritesAttribute::class,
            role: 'operation',
        );
        $record = (new $this->map->model)->setConnection($this->connectionName)->newInstance([], true)->setRawAttributes([$this->key => $key, $name => $value], true);
        $owner = $branch->owners[$name];
        $instance->write((new Staging(
            map: $branch,
            root: $owner === $branch->primary ? $successor : (new $branch->primary->model)->setConnection($this->connectionName),
            models: $owner === $branch->primary ? [] : [$owner->table => $successor],
            forwarding: $successor,
        ))->successors(), $value, new Change(
            record: $record,
            original: $record,
            attribute: $name,
            origin: Origin::Backfill,
        ));

        return true;
    }

    /**
     * @param  Collection<array-key, Model>  $primaries
     * @return Collection<array-key, Model>
     */
    private function targets(Related $related, string $type, string $id, Collection $primaries): Collection
    {
        $keys = array_values(array_filter($primaries->map(fn (Model $primary) => $primary->getAttributes()[$type] === $related->morphAlias ? $primary->getAttributes()[$id] : null)->all(), fn (mixed $key) => $key !== null));
        $targets = (new $related->model)->newCollection();

        foreach (array_chunk($keys, self::BINDINGS - 2) as $batch) {
            $targets = $targets->concat((new $related->model)->setConnection($this->connectionName)->newQuery()->useWritePdo()->whereKey($batch)->get());
        }

        return $targets->keyBy(fn (Model $target) => self::keyOf($target->getKey()));
    }

    /**
     * @param  array<string, mixed>  $external
     * @param  array<string, array<array-key, mixed>>  $read
     * @param  list<string>  $mapped
     */
    private function saveSuccessor(ResolvedMap $variant, Model $successor, array $external, array $read, int|string $key, array $mapped): int
    {
        $written = false;

        foreach ($external as $name => $value) {
            if ($variant->owners[$name]->table === $successor->getTable() && (! $successor->exists || ! ExternalAttributes::same($value, $read[$name][$successor->getKey()] ?? null))) {
                $written = $this->writeComputed($variant, $name, $successor, $value, $key) || $written;
            }
        }

        if ($successor->exists && ! $written && ! $successor->isDirty()) {
            return 0;
        }

        $this->stamp($successor, $mapped);

        if ($successor->save() === false) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', 'a '.class_basename($successor).' listener refused the save of the successor of '.$this->map->table.' row '.$this->key.' '.$key);
        }

        return 1;
    }

    private function deleteTarget(Related $old, mixed $id, int|string $key): int
    {
        if ($id === null || $this->connection->table($old->parentTable)->useWritePdo()->where((string) $old->morphType, $old->morphAlias)->where($old->parentKey, $id)->exists()) {
            return 0;
        }

        $target = (new $old->model)->setConnection($this->connectionName)->newQuery()->useWritePdo()->whereKey($id)->first();

        if ($target === null) {
            return 0;
        }

        if ($target->forceDelete() === false) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', 'a '.class_basename($target).' listener refused the delete that moves '.$this->map->table.' row '.$this->key.' '.$key.' to another relatedMorph() target');
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function chosen(array $row): string
    {
        return (string) $this->map->morphFor($this->probe($row))->target?->model;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     * @param  array<int|string, array{int|string, bool}>  $roots
     */
    private function writeBranch(ResolvedMap $branch, array $rows, array $roots): int
    {
        $stored = [];
        $external = [];

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            [$stored[$key], $external[$key]] = $this->split($branch, $row, $key);
        }

        $changed = array_filter(
            array: $branch->attributes,
            callback: fn ($attribute, string $name) => $attribute->isExternal() && $branch->owners[$name] === $branch->primary,
            mode: ARRAY_FILTER_USE_BOTH,
        ) === []
            ? $this->upsert(
                branch: $branch,
                stored: $stored,
                roots: $roots,
            )
            : $this->saveEach(
                branch: $branch,
                rows: $rows,
                stored: $stored,
                external: $external,
                roots: $roots,
            );

        foreach ($branch->related as $related) {
            $changed += $this->writeRelated(
                branch: $branch,
                related: $related,
                stored: $stored,
                roots: $roots,
            );
        }

        return $changed + $this->writeRelatedExternals(
            branch: $branch,
            stored: $stored,
            external: $external,
            roots: $roots,
        );
    }

    /**
     * @param  array<int|string, array{int|string, bool}>  $roots
     * @param  list<int|string>  $keys
     * @return list<int|string>
     */
    private function rootsOf(array $roots, array $keys): array
    {
        return array_values(array_map(
            callback: fn (int|string $key) => $roots[$key][0],
            array: $keys,
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{array<string, array<string, mixed>>, array<string, mixed>}
     */
    private function split(ResolvedMap $branch, array $row, int|string $key): array
    {
        $stored = [$branch->primary->table => []];
        $external = [];

        foreach ($branch->related as $related) {
            $stored[$related->table] = [];
        }

        foreach ($branch->attributes as $name => $attribute) {
            if ($attribute->isExternal()) {
                $external[$name] = $row[$name] ?? null;

                continue;
            }

            $column = $attribute->column();
            $conversion = $attribute->conversions()[0] ?? null;

            if ($conversion instanceof ReadAs) {
                continue;
            }

            try {
                $value = $attribute->write($row[$name] ?? null);
            } catch (LogicException $exception) {
                throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', "{$this->map->table} row {$this->key} {$key} holds a {$name} that attribute '{$name}' cannot store: {$exception->getMessage()}");
            }

            if ($column !== null) {
                $stored[$branch->owners[$name]->table][$column] = $value;
            }
        }

        return [$stored, $external];
    }

    /**
     * @param  non-empty-array<int|string, array<string, array<string, mixed>>>  $stored
     * @param  array<int|string, array{int|string, bool}>  $roots
     */
    private function upsert(ResolvedMap $branch, array $stored, array $roots): int
    {
        $table = $branch->primary->table;
        $successor = new $branch->primary->model;
        $key = $branch->primary->key;
        $columns = array_values(array_diff(array_keys(reset($stored)[$table]), [$key]));
        $generated = $successor->usesUniqueIds() ? array_values(array_diff($successor->uniqueIds(), $columns, [$key])) : [];
        [$created, $updated] = $this->stamps($successor, $columns);
        $stamp = $created === null && $updated === null ? [] : [$successor->freshTimestampString()];
        $inserted = [$key, ...$columns, ...$generated, ...array_filter(
            array: [$created, $updated],
            callback: is_string(...),
        )];
        $types = $this->typesOf($table, $inserted);
        $presence = $this->presence($branch, $table);
        $target = SqlFragments::identifier(self::TARGET);
        $action = $columns === [] ? 'DO NOTHING' : 'DO UPDATE SET '.implode(
            separator: ', ',
            array: [
                ...array_map(
                    callback: fn (string $column) => SqlFragments::identifier($column).' = '.$this->kept(
                        column: $column,
                        presence: $presence,
                        before: $target,
                        after: 'excluded',
                    ),
                    array: $columns,
                ),
                ...($updated === null ? [] : [SqlFragments::identifier($updated).' = excluded.'.SqlFragments::identifier($updated)]),
            ],
        )
            .' WHERE '.$this->distinct(
                columns: $columns,
                types: $types,
                before: $target,
                after: 'excluded',
                presence: $presence,
            );
        $rows = [];

        foreach ($stored as $legacyKey => $values) {
            $rows[] = [$roots[$legacyKey][0], ...array_map(
                callback: fn (string $column) => $values[$table][$column],
                array: $columns,
            ), ...array_map(
                callback: fn () => $successor->newUniqueId(),
                array: $generated,
            ), ...($created === null ? [] : $stamp), ...($updated === null ? [] : $stamp)];
        }

        $changed = 0;

        foreach ($this->batches($rows, count($inserted)) as $batch) {
            $changed += count($this->connection->select(
                'INSERT INTO '.SqlFragments::identifier($table)." AS {$target} (".implode(', ', array_map(SqlFragments::identifier(...), $inserted)).')'
                    .' VALUES '.implode(', ', array_fill(0, count($batch), '('.implode(', ', array_map(fn (string $column) => 'CAST(? AS '.$types[$column].')', $inserted)).')'))
                    .' ON CONFLICT ('.SqlFragments::identifier($key).') '.$action
                    .' RETURNING 1',
                array_merge(...$batch),
                false,
            ));
        }

        return $changed;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     * @param  array<int|string, array<string, array<string, mixed>>>  $stored
     * @param  array<int|string, array<string, mixed>>  $external
     * @param  array<int|string, array{int|string, bool}>  $roots
     */
    private function saveEach(ResolvedMap $branch, array $rows, array $stored, array $external, array $roots): int
    {
        $table = $branch->primary->table;
        $existing = $this->held(
            branch: $branch,
            keys: $this->rootsOf(
                roots: $roots,
                keys: array_keys($stored),
            ),
        )->keyBy(fn (Model $root) => self::keyOf($root->getKey()));
        $read = [];

        foreach ($branch->externals() as $name) {
            if ($branch->owners[$name] === $branch->primary) {
                $read[$name] = $existing->isEmpty() ? [] : $branch->attributes[$name]->readExternally($existing);
            }
        }

        $changed = 0;

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            $successor = $existing[$roots[$key][0]] ?? (new $branch->primary->model)->setConnection($this->connectionName)->setAttribute(
                key: $branch->primary->key,
                value: $roots[$key][0],
            );
            $successor->setRawAttributes(array_replace($successor->getAttributes(), $this->present($branch, $successor, $stored[$key][$table])));
            $written = false;

            foreach (array_intersect_key($external[$key], $read) as $name => $value) {
                if (! $successor->exists || ! ExternalAttributes::same($value, $read[$name][$successor->getKey()] ?? null)) {
                    $written = $this->writeComputed($branch, $name, $successor, $value, $key) || $written;
                }
            }

            if ($successor->exists && ! $written && ! $successor->isDirty()) {
                continue;
            }

            $this->stamp($successor, array_keys($stored[$key][$table]));

            if ($successor->save() === false) {
                throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', 'a '.class_basename($successor).' listener refused the save of the successor of '.$this->map->table.' row '.$this->key.' '.$key);
            }

            $changed++;
        }

        return $changed;
    }

    /**
     * @param  array<int|string, array<string, array<string, mixed>>>  $stored
     * @param  array<int|string, array{int|string, bool}>  $roots
     */
    private function writeRelated(ResolvedMap $branch, Related $related, array $stored, array $roots): int
    {
        $table = $related->table;
        $columns = array_keys(reset($stored)[$table] ?? []);

        if ($columns === []) {
            return 0;
        }

        $successor = new $related->model;
        $alias = SqlFragments::identifier($table);
        $source = SqlFragments::identifier(self::SOURCE);
        $target = SqlFragments::identifier(self::TARGET);
        $relatedKey = SqlFragments::identifier($successor->getKeyName());
        $wrapped = array_map(SqlFragments::identifier(...), $columns);
        $presence = $this->presence($branch, $table);
        $fixed = array_diff_key($related->attributes, array_flip([...$columns, $related->relatedKey]));
        [$created, $updated] = $this->stamps($successor, [...$columns, ...array_map(strval(...), array_keys($fixed))]);
        $generated = ! $related->belongsTo && $successor->usesUniqueIds() ? array_values(array_diff($successor->uniqueIds(), $columns, array_keys($fixed), [$related->relatedKey])) : [];
        $rootKey = $branch->primary->key;
        $types = $this->typesOf($table, [$related->relatedKey, ...array_keys($fixed), ...$columns, ...$generated, ...array_filter([$created, $updated], is_string(...))]);
        $sourceColumns = [self::KEY, ...$columns, ...array_map(fn (string $column) => 'compatibility_new_'.$column, $generated)];
        $sourceTypes = [(string) $this->catalog->type(
            table: $branch->primary->table,
            column: $rootKey,
        ), ...array_map(
            callback: fn (string $column) => $types[$column],
            array: $columns,
        ), ...array_map(
            callback: fn (string $column) => $types[$column],
            array: $generated,
        )];
        $set = $wrapped === [] ? 'false' : implode(' OR ', array_map(fn (string $column) => "{$source}.{$column} IS NOT NULL", $wrapped));
        $missing = "{$alias}.{$relatedKey} IS NULL AND ({$set})";
        $stamp = $successor->freshTimestampString();
        $rows = [];
        $legacyKeys = [];

        foreach ($stored as $key => $values) {
            $rows[] = [$roots[$key][0], ...array_values($values[$table]), ...array_map(
                callback: fn () => $successor->newUniqueId(),
                array: $generated,
            )];
            $legacyKeys[(string) $roots[$key][0]] = $key;
        }

        $changed = 0;

        foreach ($this->batches($rows, count($sourceColumns) + 2) as $batch) {
            $from = '(VALUES '.implode(', ', array_fill(0, count($batch), '('.implode(', ', array_map(fn (string $type) => "CAST(? AS {$type})", $sourceTypes)).')')).") AS {$source} (".implode(', ', array_map(SqlFragments::identifier(...), $sourceColumns)).')'
                ."\nJOIN ".SqlFragments::identifier($branch->primary->table).' ON '.SqlFragments::identifier($branch->primary->table).'.'.SqlFragments::identifier($rootKey).' = '.$source.'.'.SqlFragments::identifier(self::KEY)
                .implode('', array_map(fn (string $lateral) => "\n".$lateral, ViewCompiler::laterals($branch, $table)));
            $bindings = array_merge(...$batch);

            $changed += $this->connection->affectingStatement(
                'UPDATE '.SqlFragments::identifier($table)." AS {$target} SET ".implode(', ', [...array_map(fn (string $column) => SqlFragments::identifier($column).' = '.$this->kept($column, $presence, $target, $source), $columns), ...($updated === null ? [] : [SqlFragments::identifier($updated).' = CAST(? AS '.$types[$updated].')'])])
                    ."\nFROM {$from}"
                    ."\nWHERE {$target}.{$relatedKey} = {$alias}.{$relatedKey} AND ".$this->distinct($columns, $types, $target, $source, $presence),
                [...($updated === null ? [] : [$stamp]), ...$bindings],
            );

            if (! $related->belongsTo) {
                $parent = SqlFragments::identifier($related->parentTable).'.'.SqlFragments::identifier($related->parentKey);
                $inserted = [$related->relatedKey, ...array_keys($fixed), ...$columns, ...$generated, ...array_filter([$created, $updated], is_string(...))];
                $selected = [
                    $parent,
                    ...array_map(fn (string $column) => 'CAST(? AS '.$types[$column].')', array_keys($fixed)),
                    ...array_map(fn (string $column) => "{$source}.{$column}", $wrapped),
                    ...array_map(fn (string $column) => $source.'.'.SqlFragments::identifier('compatibility_new_'.$column), $generated),
                    ...array_map(fn (string $column) => 'CAST(? AS '.$types[$column].')', array_filter([$created, $updated], is_string(...))),
                ];

                $changed += $this->connection->affectingStatement(
                    'INSERT INTO '.SqlFragments::identifier($table).' ('.implode(', ', array_map(SqlFragments::identifier(...), $inserted)).')'
                        ."\nSELECT ".implode(', ', $selected)
                        ."\nFROM {$from}"
                        ."\nWHERE {$missing} AND {$parent} IS NOT NULL",
                    [...array_values($fixed), ...array_fill(0, count(array_filter([$created, $updated], is_string(...))), $stamp), ...$bindings],
                );
            }

            $unreached = array_map(
                callback: fn (mixed $root) => $legacyKeys[(string) $root] ?? $root,
                array: array_column(
                    array: $this->connection->select(
                        query: 'SELECT '.$source.'.'.SqlFragments::identifier(self::KEY)." AS key FROM {$from}\nWHERE {$missing}",
                        bindings: $bindings,
                        useReadPdo: false,
                    ),
                    column_key: 'key',
                ),
            );
            sort($unreached);

            if ($unreached !== []) {
                throw InvalidCompatibilityMap::cannot(
                    map: $this->map->table,
                    operation: 'backfill',
                    because: "{$this->map->table} rows {$this->key} ".implode(
                        separator: ', ',
                        array: array_slice(
                            array: $unreached,
                            offset: 0,
                            length: 5,
                        ),
                    )." hold values for {$table}, and ".($related->belongsTo
                        ? "the related() relation from {$related->parentTable} reaches {$table} through a BelongsTo, which the backfill does not create"
                        : "the related() relation from {$related->parentTable} reads no {$table} row the backfill can create for them; its parent row is missing, or its conditions need withAttributes()"),
                );
            }
        }

        return $changed;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $types
     * @param  list<string>  $presence
     */
    private function distinct(array $columns, array $types, string $before, string $after, array $presence = []): string
    {
        $side = fn (string $alias) => implode(', ', array_map(fn (string $column) => match (true) {
            in_array($column, $presence, true) => '('.$alias.'.'.SqlFragments::identifier($column).' IS NOT NULL)',
            $this->catalog->equatable($types[$column]) => $alias.'.'.SqlFragments::identifier($column),
            default => 'CAST('.$alias.'.'.SqlFragments::identifier($column).' AS text)',
        }, $columns));

        return '('.$side($before).') IS DISTINCT FROM ('.$side($after).')';
    }

    /**
     * @return list<string>
     */
    private function presence(ResolvedMap $branch, string $table): array
    {
        $columns = [];

        foreach ($branch->attributes as $name => $attribute) {
            $column = $attribute->column();

            if ($column !== null && ! $attribute->isExternal() && ($branch->owners[$name] ?? null)?->table === $table && ($attribute->conversions()[0] ?? null) instanceof IsNotNull) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function present(ResolvedMap $branch, Model $successor, array $values): array
    {
        foreach ($successor->exists ? $this->presence($branch, $successor->getTable()) : [] as $present) {
            if (($values[$present] ?? null) !== null && $successor->getRawOriginal($present) !== null) {
                $values[$present] = $successor->getRawOriginal($present);
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $mapped
     */
    private function stamp(Model $successor, array $mapped): void
    {
        [$created, $updated] = $this->stamps($successor, $mapped);
        $successor->timestamps = false;
        $now = $successor->freshTimestamp();

        if ($updated !== null && ! $successor->isDirty($updated)) {
            $successor->setAttribute($updated, $now);
        }

        if ($created !== null && ! $successor->exists && ! $successor->isDirty($created)) {
            $successor->setAttribute($created, $now);
        }
    }

    /**
     * @param  list<string>  $presence
     */
    private function kept(string $column, array $presence, string $before, string $after): string
    {
        $wrapped = SqlFragments::identifier($column);

        return in_array($column, $presence, true)
            ? "CASE WHEN {$before}.{$wrapped} IS NOT NULL AND {$after}.{$wrapped} IS NOT NULL THEN {$before}.{$wrapped} ELSE {$after}.{$wrapped} END"
            : "{$after}.{$wrapped}";
    }

    private function deleteGone(): int
    {
        $legacy = SqlFragments::identifier($this->map->table);
        $ledger = SqlFragments::identifier(self::ledger($this->map->table));
        $key = SqlFragments::identifier($this->key);
        $primary = SqlFragments::identifier($this->map->primary->table);
        $successorKey = SqlFragments::identifier($this->map->successorKey);

        $deleted = $this->connection->scalar(
            "WITH compatibility_gone AS (SELECT ledger.legacy_id, ledger.successor_id FROM {$ledger} AS ledger WHERE ledger.status <> 'deleted' AND NOT EXISTS (SELECT FROM {$legacy} WHERE {$legacy}.{$key} = ledger.legacy_id)),\n"
                ."compatibility_deleted AS (DELETE FROM {$primary} USING compatibility_gone WHERE {$primary}.".SqlFragments::identifier($this->map->primary->key).' = compatibility_gone.successor_id'
                ." AND NOT EXISTS (SELECT FROM {$legacy} WHERE {$legacy}.{$successorKey} = compatibility_gone.successor_id)"
                .implode(
                    separator: '',
                    array: array_map(
                        callback: fn (string $condition) => ' AND NOT COALESCE(('.$condition.'), false)',
                        array: ViewCompiler::hidden($this->map),
                    ),
                )
                ." RETURNING 1),\n"
                ."compatibility_forgotten AS (UPDATE {$ledger} AS ledger SET status = 'deleted', attempts = ledger.attempts + 1, attempted_at = now() FROM compatibility_gone WHERE ledger.legacy_id = compatibility_gone.legacy_id)\n"
                .'SELECT count(*) FROM compatibility_deleted',
            [],
            false,
        );

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @param  array<int|string, array{int|string, bool}>  $roots
     */
    private function record(array $roots): void
    {
        $types = [(string) $this->catalog->type(
            table: $this->map->table,
            column: $this->key,
        ), (string) $this->catalog->type(
            table: $this->map->primary->table,
            column: $this->map->primary->key,
        )];
        $rows = array_map(fn (int|string $key, array $root) => [$key, $root[0], $root[1] ? 'updated' : 'created'], array_keys($roots), $roots);
        $ledger = SqlFragments::identifier(self::ledger($this->map->table));

        foreach ($this->batches(
            rows: $rows,
            width: 3,
        ) as $batch) {
            $this->connection->insert(
                query: "INSERT INTO {$ledger} AS ledger (legacy_id, successor_id, status, attempts, attempted_at) VALUES "
                    .implode(
                        separator: ', ',
                        array: array_fill(
                            start_index: 0,
                            count: count($batch),
                            value: "(CAST(? AS {$types[0]}), CAST(? AS {$types[1]}), ?, 1, now())",
                        ),
                    )
                    .' ON CONFLICT (legacy_id) DO UPDATE SET successor_id = excluded.successor_id, status = excluded.status, reason = NULL, attempts = ledger.attempts + 1, attempted_at = excluded.attempted_at',
                bindings: array_merge(...$batch),
            );
        }
    }

    /**
     * @param  list<int|string>  $keys
     * @return Collection<int, Model>
     */
    private function held(ResolvedMap $branch, array $keys): Collection
    {
        $held = (new $branch->primary->model)->newCollection();

        foreach (array_chunk($keys, self::BINDINGS - 2) as $batch) {
            $held = $held->concat((new $branch->primary->model)->setConnection($this->connectionName)->newModelQuery()->useWritePdo()->whereKey($batch)->get());
        }

        return $held;
    }

    /**
     * @param  list<string>  $columns
     * @return array{null|string, null|string}
     */
    private function stamps(Model $successor, array $columns): array
    {
        if (! $successor->usesTimestamps()) {
            return [null, null];
        }

        $created = $successor->getCreatedAtColumn();
        $updated = $successor->getUpdatedAtColumn();

        return [
            $created === null || in_array($created, $columns, true) ? null : $created,
            $updated === null || in_array($updated, $columns, true) ? null : $updated,
        ];
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private function typesOf(string $table, array $columns): array
    {
        $catalog = $this->catalog->of($table);

        if ($catalog === []) {
            throw InvalidCompatibilityMap::because($this->map->table, "the successor table {$table} does not exist");
        }

        $types = [];

        foreach ($columns as $column) {
            $types[$column] = array_key_exists(
                key: $column,
                array: $catalog,
            ) ? $catalog[$column]->type : throw InvalidCompatibilityMap::because(
                map: $this->map->table,
                problem: "{$table} has no column {$column}, which the backfill writes",
            );
        }

        return $types;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return list<non-empty-list<list<mixed>>>
     */
    private function batches(array $rows, int $width): array
    {
        return array_chunk($rows, max(1, intdiv(self::BINDINGS - 2, max(1, $width))));
    }

    private function refuseUnwalkable(int $chunk): void
    {
        if ($chunk < 1) {
            throw new LogicException("A backfill reads at least one row per chunk, not {$chunk}.");
        }

        $legacy = $this->catalog->of($this->map->table);

        if ($legacy === []) {
            throw InvalidCompatibilityMap::because($this->map->table, "the legacy table {$this->map->table} does not exist");
        }

        if (LegacyBuilder::serves(new $this->map->model)) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', "{$this->map->table} is already installed as a view, and the backfill walks the legacy table while it is still a table");
        }

        if (! array_key_exists(
            key: $this->map->successorKey,
            array: $legacy,
        ) || ! $this->exists(self::ledger($this->map->table)) || ! $this->exists(self::BACKFILLS)) {
            throw InvalidCompatibilityMap::cannot(
                map: $this->map->table,
                operation: 'backfill',
                because: "Compatibility::prepare() adds {$this->map->table}.{$this->map->successorKey} and creates ".self::ledger($this->map->table).' and '.self::BACKFILLS.', and it has not run; run Compatibility::prepare() first',
            );
        }

        foreach ($this->map->morphs === [] ? [$this->map] : $this->map->morphs as $branch) {
            foreach (array_keys(array_filter(
                array: $branch->attributes,
                callback: fn ($attribute) => $attribute->column() !== null || $attribute->isExternal(),
            )) as $name) {
                if (! array_key_exists($name, $legacy)) {
                    throw InvalidCompatibilityMap::because($this->map->table, "{$this->map->table} has no column {$name}, so the backfill has no value to write for it");
                }
            }
        }
    }

    private function exists(string $table): bool
    {
        return $this->connection->scalar(
            query: "select to_regclass(quote_ident(current_schema()) || '.' || ?) is not null",
            bindings: [SqlFragments::identifier($table)],
            useReadPdo: false,
        ) === true;
    }

    private function updatedAt(): null|string
    {
        $legacy = new $this->map->model;
        $column = $legacy->usesTimestamps() ? $legacy->getUpdatedAtColumn() : null;

        return $column !== null && array_key_exists($column, $this->catalog->of($this->map->table)) ? $column : null;
    }

    /**
     * @return Closure(QueryBuilder): mixed
     */
    private function updatedSince(string $watermark): Closure
    {
        $column = $this->updatedAt() ?? throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', "{$this->map->table} has no updated_at column of ".class_basename($this->map->model).', so no watermark limits a catch-up run');
        $bound = SqlFragments::isNaive($this->catalog->type($this->map->table, $column)) ? SqlFragments::inApplicationZone('CAST(? AS timestamptz)') : 'CAST(? AS timestamptz)';

        return fn (QueryBuilder $query) => $query->whereRaw(SqlFragments::identifier($column).' >= '.$bound, [$watermark]);
    }

    private function now(string $operation): string
    {
        $now = (array) $this->connection->selectOne(
            "select to_char(least(now(), min(xact_start) filter (where backend_type = 'client backend')) at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') as watermark,
                    count(*) filter (where state is null and backend_type is null) as hidden,
                    current_user as role
             from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid()",
            [],
            false,
        );
        $hidden = (int) ($now['hidden'] ?? 0);

        if ($hidden > 0) {
            $role = (string) ($now['role'] ?? '');

            throw InvalidCompatibilityMap::cannot($this->map->table, $operation, "pg_stat_activity hides from {$role} whether {$hidden} other sessions of this database hold a transaction open, so no watermark can start before a write they have not committed yet. Grant pg_read_all_stats to {$role}, then {$operation} again");
        }

        return (string) ($now['watermark'] ?? '');
    }

    private function inApplicationZone(): null|string
    {
        $session = $this->connection->transactionLevel() > 1 ? $this->connection->scalar("select current_setting('TimeZone')", [], false) : null;
        $this->connection->select("select set_config('TimeZone', ?, true)", [Config::string('app.timezone', 'UTC')], false);

        return is_string($session) ? $session : null;
    }

    private function restoreZone(null|string $session): void
    {
        if ($session !== null) {
            $this->connection->select("select set_config('TimeZone', ?, true)", [$session], false);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function probe(array $row): Model
    {
        $legacy = (new $this->map->model)->setConnection($this->connectionName);
        $legacy->setRawAttributes($row, true);

        return $legacy;
    }

    private static function keyOf(mixed $key): int|string
    {
        return is_int($key) || is_string($key) ? $key : throw new LogicException('A legacy row has no key to backfill it by.');
    }
}
