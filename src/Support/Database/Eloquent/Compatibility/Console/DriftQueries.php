<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\Column;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;

final readonly class DriftQueries
{
    private const int CHUNK = 1000;

    private const string COMPILED = 'compatibility_compiled';

    private const string HOLDER = 'compatibility_holder';

    private const array UNWRITABLE = ['25006', '42501'];

    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @param  list<ResolvedMap>  $siblings
     * @return list<DriftFinding>
     */
    public function find(ResolvedMap $map, int $limit, array $siblings = []): array
    {
        $installed = $this->installed($map);

        return [
            ...($installed ? [] : $this->withoutPrimary($map, $limit)),
            ...($installed ? $this->staleView($map) : $this->differing($map, $limit)),
            ...($installed ? [] : $this->misplaced($map, $limit)),
            ...($installed ? $this->retainedWithoutPrimary($map, $limit) : []),
            ...$this->heldTwice(
                map: $map,
                siblings: $siblings,
                limit: $limit,
            ),
            ...$this->withoutMorphTarget($map, $limit),
            ...$this->outsideMembership(
                map: $map,
                limit: $limit,
            ),
            ...$this->refusedByGuards($map, $limit),
        ];
    }

    /**
     * @param  list<ResolvedMap>  $siblings
     * @return list<DriftFinding>
     */
    public function membership(ResolvedMap $map, array $siblings, int $limit): array
    {
        return [...$this->outsideMembership(
            map: $map,
            limit: $limit,
        ), ...$this->heldTwice(
            map: $map,
            siblings: $siblings,
            limit: $limit,
        )];
    }

    /**
     * @return null|array{installed: string, compiled: string}
     */
    public function views(ResolvedMap $map): null|array
    {
        if (! $this->installed($map)) {
            return null;
        }

        $replace = (new ViewCompiler(new ColumnCatalog($this->connection), $this->connection))->replace($map);
        $prefix = 'CREATE OR REPLACE VIEW '.SqlFragments::identifier($map->table)." AS\n";

        if (! str_starts_with($replace, $prefix)) {
            throw new LogicException("Compatibility map '{$map->table}' compiled no CREATE OR REPLACE VIEW statement.");
        }

        $this->connection->beginTransaction();

        try {
            $this->compile($map, substr($replace, strlen($prefix)));
            $views = (array) $this->connection->selectOne(
                "select pg_get_viewdef(to_regclass(quote_ident(current_schema()) || '.' || ?), true) as installed, pg_get_viewdef(to_regclass(?), true) as compiled",
                [SqlFragments::identifier($map->table), 'pg_temp.'.SqlFragments::identifier(self::COMPILED)],
                false,
            );
        } finally {
            $this->connection->rollBack();
        }

        return ['installed' => self::text($views['installed'] ?? null), 'compiled' => self::text($views['compiled'] ?? null)];
    }

    private function compile(ResolvedMap $map, string $select): void
    {
        try {
            $this->connection->unprepared('CREATE TEMPORARY VIEW '.SqlFragments::identifier(self::COMPILED)." AS\n".$select);
        } catch (QueryException $exception) {
            $state = (string) $exception->getCode();

            if (! in_array($state, self::UNWRITABLE, true)) {
                throw $exception;
            }

            throw InvalidCompatibilityMap::cannot($map->table, 'check', "the stale-view check creates a temporary view, so it needs a writable connection with the TEMP privilege, and the connection refused it with SQLSTATE {$state}");
        }
    }

    private function installed(ResolvedMap $map): bool
    {
        $kind = $this->connection->scalar(
            "select cast(relkind as text) from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)",
            [SqlFragments::identifier($map->table)],
            false,
        );

        return match ($kind) {
            'v' => true,
            'r' => false,
            default => throw InvalidCompatibilityMap::cannot($map->table, 'check', "{$map->table} is neither a table nor a view"),
        };
    }

    /**
     * @return list<DriftFinding>
     */
    private function staleView(ResolvedMap $map): array
    {
        $views = $this->views($map);

        if ($views === null || $views['installed'] === $views['compiled']) {
            return [];
        }

        return [new DriftFinding(
            model: $map->model,
            check: DriftCheck::StaleView,
            record: $map->table,
            detail: 'the installed view differs from the view the map compiles; run Compatibility::update()',
        )];
    }

    /**
     * @return list<DriftFinding>
     */
    private function withoutPrimary(ResolvedMap $map, int $limit): array
    {
        $legacy = SqlFragments::identifier($map->table);
        $legacyKey = $legacy.'.'.SqlFragments::identifier(self::key($map));
        [$pending, $bindings] = $this->pending($map, $legacy);
        $rows = $this->connection->select(
            "SELECT {$legacyKey} AS key FROM {$legacy} WHERE NOT ".self::held($map, $legacy).($pending === '' ? '' : " AND {$pending}")." ORDER BY {$legacyKey} LIMIT ?",
            [...$bindings, $limit],
            false,
        );

        return array_values(array_map(
            fn (object $row) => new DriftFinding(
                model: $map->model,
                check: DriftCheck::NoPrimary,
                record: self::record(
                    table: $map->table,
                    key: $row->key ?? null,
                ),
                detail: "its {$map->successorKey} names no {$map->primary->table} row, so install() refuses it",
            ),
            $rows,
        ));
    }

    /**
     * @return list<DriftFinding>
     */
    private function differing(ResolvedMap $map, int $limit): array
    {
        $catalog = new ColumnCatalog($this->connection);
        $compiler = new ViewCompiler($catalog, $this->connection);
        $shape = $catalog->of($map->table);
        $key = self::key($map);
        $retained = SqlFragments::identifier(ViewCompiler::retainedTable($map));
        $legacyKey = $retained.'.'.SqlFragments::identifier($key);
        [$pending, $bindings] = $this->pending($map, $retained);
        $differs = [];

        foreach ($compiler->served($map, $shape, $map->externals(), $map->table) as $name => $read) {
            [$read, $stored] = self::comparable($catalog, $shape[$name], $read, $retained.'.'.SqlFragments::identifier($name));
            $differs[] = "CASE WHEN {$read} IS DISTINCT FROM {$stored} THEN ".SqlFragments::literal($name).' END';
        }

        $findings = array_values(array_map(
            callback: fn (object $row) => new DriftFinding(
                model: $map->model,
                check: DriftCheck::ValuesDiffer,
                record: self::record(
                    table: $map->table,
                    key: $row->key ?? null,
                ),
                detail: "{$map->table} holds other values for ".self::text($row->attributes ?? null).' than the view would serve from '.$map->primary->table,
            ),
            array: $this->connection->select(
                query: "SELECT compared.key, array_to_string(compared.attributes, ', ') AS attributes FROM (SELECT {$legacyKey} AS key, array_remove(ARRAY[".implode(', ', $differs).'], NULL) AS attributes'
                    .' FROM '.SqlFragments::identifier($map->table).' AS '.$retained
                    .' '.ViewCompiler::root(
                        map: $map,
                        retained: ViewCompiler::retainedTable($map),
                    )
                    .implode('', array_map(fn (string $lateral) => ' '.$lateral, ViewCompiler::laterals($map)))
                    .($pending === '' ? '' : " WHERE {$pending}").') AS compared WHERE cardinality(compared.attributes) > 0 ORDER BY compared.key LIMIT ?',
                bindings: [...$bindings, $limit],
                useReadPdo: false,
            ),
        ));

        return [...$findings, ...$this->externalsDiffering($map, $shape, $limit - count($findings))];
    }

    /**
     * @return array{string, string}
     */
    private static function comparable(ColumnCatalog $catalog, Column $column, string $read, string $stored): array
    {
        $json = match ($column->baseType ?? $column->type) {
            'json' => 'jsonb',
            'json[]' => 'jsonb[]',
            default => null,
        };

        return match (true) {
            $json !== null => ["CAST({$read} AS {$json})", "CAST({$stored} AS {$json})"],
            $catalog->equatable($column->type) => [$read, $stored],
            default => ["CAST({$read} AS text)", "CAST({$stored} AS text)"],
        };
    }

    /**
     * @param  array<string, Column>  $shape
     * @return list<DriftFinding>
     */
    private function externalsDiffering(ResolvedMap $map, array $shape, int $limit): array
    {
        $externals = array_values(array_filter($map->externals(), fn (string $name) => array_key_exists($name, $shape)));

        if ($externals === [] || $limit < 1) {
            return [];
        }

        $connection = (new $map->model)->getConnectionName();
        $catalog = new ColumnCatalog(
            connection: (new $map->model)->setConnection($connection)->getConnection(),
            throughSearchPath: true,
        );
        $findings = [];

        $this->walk($map, function (array $rows) use ($map, $externals, $connection, $catalog, $limit, &$findings): bool {
            $differs = [];

            foreach ($externals as $name) {
                $values = ExternalAttributes::values(
                    map: $map,
                    name: $name,
                    keys: array_keys($rows),
                    connection: $connection,
                    writePdo: true,
                    catalog: $catalog,
                );

                foreach ($rows as $key => $row) {
                    if (! ExternalAttributes::same($row[$name] ?? null, $values[$key] ?? null)) {
                        $differs[$key][] = $name;
                    }
                }
            }

            foreach (array_keys($rows) as $key) {
                if (array_key_exists($key, $differs) && count($findings) < $limit) {
                    $findings[] = new DriftFinding($map->model, DriftCheck::ValuesDiffer, self::record($map->table, $key), "{$map->table} holds other values for ".implode(', ', $differs[$key]).' than '.(count($differs[$key]) === 1 ? 'its computedAs() read returns' : 'their computedAs() reads return'));
                }
            }

            return count($findings) < $limit;
        });

        return $findings;
    }

    /**
     * @return list<DriftFinding>
     */
    private function misplaced(ResolvedMap $map, int $limit): array
    {
        if ($map->morphs === []) {
            return [];
        }

        $key = self::key($map);
        $type = (string) (new ColumnCatalog($this->connection))->type($map->table, $key);
        $connection = (new $map->model)->getConnectionName();
        $found = [];

        $this->walk($map, function (array $rows) use ($map, $key, $type, $connection, $limit, &$found): bool {
            $chosen = [];

            foreach ($rows as $legacyKey => $row) {
                $probe = (new $map->model)->setConnection($connection);
                $probe->setRawAttributes($row, true);
                $chosen[(string) $map->morphFor($probe)->target?->model][] = $legacyKey;
            }

            foreach ($chosen as $model => $keys) {
                $variant = $map->morphs[$model];
                $candidate = "CAST(chosen.key AS {$type})";
                $target = $variant->target === null ? '' : ' AND EXISTS (SELECT FROM '.SqlFragments::identifier($variant->target->table).' WHERE '.implode(' AND ', ViewCompiler::conditions($variant->target, true)).')';
                $rows = $this->connection->select(
                    "SELECT {$candidate} AS key FROM jsonb_array_elements_text(CAST(? AS jsonb)) AS chosen (key)"
                        .' WHERE NOT EXISTS (SELECT FROM '.SqlFragments::identifier($variant->primary->table).' WHERE '.self::holding($variant, $key, $candidate).$target.')'
                        .self::reaching($map, $key, $candidate),
                    [json_encode($keys, JSON_THROW_ON_ERROR)],
                    false,
                );

                foreach ($rows as $row) {
                    $found[] = [self::keyOf($row->key ?? null), $variant];
                }
            }

            return count($found) < $limit;
        });

        usort($found, fn (array $first, array $second) => $first[0] <=> $second[0]);

        return array_map(
            fn (array $misplaced) => new DriftFinding(
                model: $map->model,
                check: DriftCheck::Misplaced,
                record: self::record(
                    table: $map->table,
                    key: $misplaced[0],
                ),
                detail: "its relatedMorph() choose: closure picks {$misplaced[1]->target?->table}, and its {$map->primary->table} row points at no {$misplaced[1]->target?->table} row, so install() refuses it",
            ),
            array_slice($found, 0, $limit),
        );
    }

    private static function reaching(ResolvedMap $map, string $key, string $candidate): string
    {
        $morph = $map->morph;

        if ($morph === null || $map->morphs === []) {
            return '';
        }

        $primary = SqlFragments::identifier($map->primary->table);
        $reached = array_map(fn (Related $target) => 'EXISTS (SELECT FROM '.SqlFragments::identifier($target->table).' WHERE '.implode(' AND ', ViewCompiler::conditions($target, true)).')', array_values($morph->targets));

        return " AND EXISTS (SELECT FROM {$primary} WHERE ".self::holding($map, $key, $candidate).' AND ('.implode(' OR ', $reached).'))';
    }

    /**
     * @param  Closure(array<int|string, array<string, mixed>>): bool  $chunk
     */
    private function walk(ResolvedMap $map, Closure $chunk): void
    {
        $legacy = SqlFragments::identifier($map->table);
        $key = self::key($map);
        $legacyKey = $legacy.'.'.SqlFragments::identifier($key);
        [$pending, $bindings] = $this->pending($map, $legacy);
        $last = null;

        do {
            $rows = [];

            foreach ($this->connection->select(
                "SELECT {$legacy}.* FROM {$legacy} WHERE ".self::held($map, $legacy).($pending === '' ? '' : " AND {$pending}").($last === null ? '' : " AND {$legacyKey} > ?")." ORDER BY {$legacyKey} LIMIT ".self::CHUNK,
                [...$bindings, ...($last === null ? [] : [$last])],
                false,
            ) as $row) {
                $row = (array) $row;
                $rows[self::keyOf($row[$key] ?? null)] = $row;
            }

            $last = array_key_last($rows) ?? $last;
        } while ($rows !== [] && $chunk($rows) && count($rows) === self::CHUNK);
    }

    /**
     * @return array{string, list<int|string>}
     */
    private function pending(ResolvedMap $map, string $table): array
    {
        $shape = (new ColumnCatalog($this->connection))->of($map->table);
        $watermark = (new Backfiller($map, $this->connection))->remembered();
        $legacy = new $map->model;
        $updatedAt = $legacy->usesTimestamps() ? $legacy->getUpdatedAtColumn() : null;

        if ($watermark === null || $updatedAt === null || ! array_key_exists(
            key: $updatedAt,
            array: $shape,
        )) {
            return ['', []];
        }

        $bound = SqlFragments::isNaive($shape[$updatedAt]->type) ? SqlFragments::inApplicationZone('CAST(? AS timestamptz)') : 'CAST(? AS timestamptz)';

        $column = $table.'.'.SqlFragments::identifier($updatedAt);

        return ["NOT (COALESCE({$column} >= {$bound}, false) OR {$column} IS NULL OR {$table}.".SqlFragments::identifier($map->successorKey).' IS NULL)', [$watermark]];
    }

    /**
     * @return list<DriftFinding>
     */
    private function retainedWithoutPrimary(ResolvedMap $map, int $limit): array
    {
        $retainedTable = ViewCompiler::retainedTable($map);
        $retained = SqlFragments::identifier($retainedTable);
        $legacyKey = $retained.'.'.SqlFragments::identifier(self::key($map));
        $sql = "SELECT {$legacyKey} AS key FROM {$retained} WHERE NOT ".self::held(
            map: $map,
            legacy: $retained,
        );

        return array_values(array_map(
            fn (object $row) => new DriftFinding(
                model: $map->model,
                check: DriftCheck::RetainedWithoutPrimary,
                record: self::record(
                    table: $retainedTable,
                    key: $row->key ?? null,
                ),
                detail: "its {$map->successorKey} names no {$map->primary->table} row, so the view serves none of it",
            ),
            $this->connection->select(
                query: "{$sql} ORDER BY {$legacyKey} LIMIT ?",
                bindings: [$limit],
                useReadPdo: false,
            ),
        ));
    }

    /**
     * @param  list<ResolvedMap>  $siblings
     * @return list<DriftFinding>
     */
    private function heldTwice(ResolvedMap $map, array $siblings, int $limit): array
    {
        $legacy = SqlFragments::identifier($map->table);
        $findings = [];

        $catalog = new ColumnCatalog($this->connection);

        foreach ($siblings as $sibling) {
            if ($sibling->table === $map->table || $sibling->primary->table !== $map->primary->table || ! array_key_exists(
                key: $sibling->successorKey,
                array: $catalog->of($sibling->table),
            )) {
                continue;
            }

            $theirs = SqlFragments::identifier($sibling->table);
            $rows = $this->connection->select(
                query: "SELECT {$legacy}.".SqlFragments::identifier(self::key($map))." AS key FROM {$legacy} WHERE EXISTS (SELECT FROM {$theirs} WHERE {$theirs}.".SqlFragments::identifier($sibling->successorKey)." = {$legacy}.".SqlFragments::identifier($map->successorKey).') ORDER BY 1 LIMIT ?',
                bindings: [$limit],
                useReadPdo: false,
            );

            foreach ($rows as $row) {
                $findings[] = new DriftFinding(
                    model: $map->model,
                    check: DriftCheck::HeldTwice,
                    record: self::record(
                        table: $map->table,
                        key: $row->key ?? null,
                    ),
                    detail: "its {$map->primary->table} row also serves a ".class_basename($sibling->model).' record, so one shared successor serves two legacy records',
                );
            }
        }

        return array_slice(
            array: $findings,
            offset: 0,
            length: $limit,
        );
    }

    /**
     * @return list<DriftFinding>
     */
    private function withoutMorphTarget(ResolvedMap $map, int $limit): array
    {
        $morph = $map->morph;

        if ($morph === null || $map->morphs === []) {
            return [];
        }

        $primary = SqlFragments::identifier($map->primary->table);
        $type = $primary.'.'.SqlFragments::identifier($morph->typeColumn);
        $targets = [];

        foreach ($morph->targets as $target) {
            $targets[(string) $target->morphAlias] = $target;
        }

        $reached = array_map(fn (Related $target) => 'EXISTS (SELECT FROM '.SqlFragments::identifier($target->table).' WHERE '.implode(' AND ', ViewCompiler::conditions($target, true)).')', array_values($targets));
        $legacy = SqlFragments::identifier(self::HOLDER);
        $rows = $this->connection->select(
            "SELECT {$legacy}.".SqlFragments::identifier(self::key($map))." AS key, {$type} AS type FROM ".SqlFragments::identifier($map->table).' AS '.$legacy.' '.ViewCompiler::root(
                map: $map,
                retained: self::HOLDER,
            )
                .' WHERE NOT ('.implode(' OR ', $reached).') ORDER BY 1 LIMIT ?',
            [$limit],
            false,
        );

        return array_values(array_map(function (object $row) use ($map, $morph, $targets): DriftFinding {
            $named = is_string($row->type ?? null) ? $row->type : null;
            $target = $named === null ? null : $targets[$named] ?? null;
            $detail = $target === null
                ? "its {$morph->typeColumn} ".($named === null ? 'is NULL, which names' : "'{$named}' names").' no target relatedMorph() declares'
                : "its {$target->table} row ".($target->deletedAt === null ? 'does not exist' : 'does not exist or is deleted');

            return new DriftFinding($map->model, DriftCheck::NoMorphTarget, self::record($map->table, $row->key ?? null), $detail);
        }, $rows));
    }

    /**
     * @return list<DriftFinding>
     */
    private function outsideMembership(ResolvedMap $map, int $limit): array
    {
        $when = $map->primary->membershipScope();

        if ($when === null) {
            return [];
        }

        $legacy = SqlFragments::identifier($map->table);
        $members = (new $map->primary->model)->setConnection((new $map->model)->getConnectionName())->newModelQuery();
        $members->select($members->qualifyColumn($map->primary->key))->whereColumn(
            first: $members->qualifyColumn($map->primary->key),
            operator: '=',
            second: $map->table.'.'.$map->successorKey,
        )->where(function (Builder $member) use ($when): void {
            $when($member);
        });
        $base = $members->toBase();
        $rows = $this->connection->select(
            query: "SELECT {$legacy}.".SqlFragments::identifier(self::key($map))." AS key FROM {$legacy} WHERE ".self::held(
                map: $map,
                legacy: $legacy,
            ).' AND NOT EXISTS ('.$base->toSql().') ORDER BY 1 LIMIT ?',
            bindings: [...$base->getBindings(), $limit],
            useReadPdo: false,
        );

        return array_values(array_map(
            callback: fn (object $row) => new DriftFinding(
                model: $map->model,
                check: DriftCheck::OutsideMembership,
                record: self::record(
                    table: $map->table,
                    key: $row->key ?? null,
                ),
                detail: "its {$map->primary->table} row no longer matches the when() of ".class_basename($map->model),
            ),
            array: $rows,
        ));
    }

    /**
     * @return list<DriftFinding>
     */
    private function refusedByGuards(ResolvedMap $map, int $limit): array
    {
        if ($map->guards === []) {
            return [];
        }

        $findings = [];

        foreach ((new $map->model)->newModelQuery()->lazyById(self::CHUNK) as $record) {
            foreach ($map->guards as $guard) {
                if (count($findings) < $limit && $guard->refuses(clone $record, $record)) {
                    $findings[] = new DriftFinding($map->model, DriftCheck::GuardRefuses, self::record($map->table, $record->getKey()), "guard '{$guard->name}' refuses the record as it stands: {$guard->because}");
                }
            }

            if (count($findings) === $limit) {
                break;
            }
        }

        return $findings;
    }

    private static function key(ResolvedMap $map): string
    {
        return (new $map->model)->getKeyName();
    }

    private static function holding(ResolvedMap $map, string $key, string $legacyKey): string
    {
        $primary = SqlFragments::identifier($map->primary->table);
        $legacy = SqlFragments::identifier($map->table);

        return "{$primary}.".SqlFragments::identifier($map->primary->key)." = (SELECT {$legacy}.".SqlFragments::identifier($map->successorKey)." FROM {$legacy} WHERE {$legacy}.".SqlFragments::identifier($key)." = {$legacyKey})";
    }

    private static function held(ResolvedMap $map, string $legacy): string
    {
        $primary = SqlFragments::identifier($map->primary->table);

        return "EXISTS (SELECT FROM {$primary} WHERE {$primary}.".SqlFragments::identifier($map->primary->key)." = {$legacy}.".SqlFragments::identifier($map->successorKey).')';
    }

    private static function record(string $table, mixed $key): string
    {
        return "{$table}#".(is_int($key) || is_string($key) ? (string) $key : 'NULL');
    }

    private static function keyOf(mixed $key): int|string
    {
        return is_int($key) || is_string($key) ? $key : throw new LogicException('A legacy row has no key to compare it by.');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
