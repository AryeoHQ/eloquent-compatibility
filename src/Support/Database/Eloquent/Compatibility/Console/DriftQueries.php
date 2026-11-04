<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Closure;
use Illuminate\Database\ConnectionInterface;
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

    private const array UNWRITABLE = ['25006', '42501'];

    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @return list<DriftFinding>
     */
    public function find(ResolvedMap $map, int $limit): array
    {
        $installed = $this->installed($map);

        return [
            ...($installed ? [] : $this->withoutPrimary($map, $limit)),
            ...($installed ? $this->staleView($map) : $this->differing($map, $limit)),
            ...($installed ? [] : $this->misplaced($map, $limit)),
            ...($installed ? $this->retainedWithoutPrimary($map, $limit) : []),
            ...$this->heldTwice($map, $limit),
            ...$this->withoutMorphTarget($map, $limit),
            ...$this->unclassified($map),
            ...$this->refusedByGuards($map, $limit),
        ];
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

        return [new DriftFinding($map->model, DriftCheck::StaleView, $map->table, 'the installed view differs from the view the map compiles; run Compatibility::recompile()')];
    }

    /**
     * @return list<DriftFinding>
     */
    private function withoutPrimary(ResolvedMap $map, int $limit): array
    {
        $legacy = SqlFragments::identifier($map->table);
        $legacyKey = $legacy.'.'.SqlFragments::identifier(self::key($map));
        [$pending, $bindings] = $this->pending($map, $legacy);
        $identifiers = implode(' and ', array_keys($map->identifiers));
        $rows = $this->connection->select(
            "SELECT {$legacyKey} AS key FROM {$legacy} WHERE NOT ".self::held($map, $legacy).($pending === '' ? '' : " AND {$pending}")." ORDER BY {$legacyKey} LIMIT ?",
            [...$bindings, $limit],
            false,
        );

        return array_values(array_map(
            fn (object $row) => new DriftFinding($map->model, DriftCheck::NoPrimary, self::record($map->table, $row->key ?? null), 'no '.self::successors($map)." holds its {$identifiers}, so install() refuses it"),
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
        $findings = [];

        foreach (self::served($map) as $branch) {
            $differs = [];

            foreach ($compiler->served($branch, $shape, $map->externals(), $map->table) as $name => $read) {
                [$read, $stored] = self::comparable($catalog, $shape[$name], $read, $retained.'.'.SqlFragments::identifier($name));
                $differs[] = "CASE WHEN {$read} IS DISTINCT FROM {$stored} THEN ".SqlFragments::literal($name).' END';
            }

            $rows = $this->connection->select(
                "SELECT compared.key, array_to_string(compared.attributes, ', ') AS attributes FROM (SELECT {$legacyKey} AS key, array_remove(ARRAY[".implode(', ', $differs).'], NULL) AS attributes'
                    .' FROM '.ViewCompiler::primary($branch)
                    .' JOIN '.SqlFragments::identifier($map->table).' AS '.$retained.' ON '.$legacyKey.' = '.SqlFragments::identifier($branch->primary->table).'.'.SqlFragments::identifier((string) $branch->identifiers[$key]->column())
                    .implode('', array_map(fn (string $lateral) => ' '.$lateral, ViewCompiler::laterals($branch)))
                    .($pending === '' ? '' : " WHERE {$pending}").') AS compared WHERE cardinality(compared.attributes) > 0 ORDER BY compared.key LIMIT ?',
                [...$bindings, $limit],
                false,
            );

            foreach ($rows as $row) {
                $findings[] = new DriftFinding($map->model, DriftCheck::ValuesDiffer, self::record($map->table, $row->key ?? null), "{$map->table} holds other values for ".self::text($row->attributes ?? null).' than the view would serve from '.$branch->primary->table);
            }
        }

        $findings = array_slice($findings, 0, $limit);

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
        $findings = [];

        $this->walk($map, function (array $rows) use ($map, $externals, $connection, $limit, &$findings): bool {
            $differs = [];

            foreach ($externals as $name) {
                $values = ExternalAttributes::values($map, $name, array_keys($rows), $connection, true);

                foreach ($rows as $key => $row) {
                    if (! ExternalAttributes::same($row[$name] ?? null, $values[$key] ?? null)) {
                        $differs[$key][] = $name;
                    }
                }
            }

            foreach (array_keys($rows) as $key) {
                if (array_key_exists($key, $differs) && count($findings) < $limit) {
                    $findings[] = new DriftFinding($map->model, DriftCheck::ValuesDiffer, self::record($map->table, $key), "{$map->table} holds other values for ".implode(', ', $differs[$key]).' than '.(count($differs[$key]) === 1 ? 'its external() read returns' : 'their external() reads return'));
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
        if ($map->branches === [] && $map->morphs === []) {
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
                $chosen[$map->morphs === [] ? $map->branchFor($probe)->primary->model : (string) $map->morphFor($probe)->target?->model][] = $legacyKey;
            }

            foreach ($chosen as $model => $keys) {
                $variant = $map->branches[$model] ?? $map->morphs[$model];
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
            fn (array $misplaced) => new DriftFinding($map->model, DriftCheck::Misplaced, self::record($map->table, $misplaced[0]), self::misplacement($misplaced[1], $key)),
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

    private static function misplacement(ResolvedMap $variant, string $key): string
    {
        $primary = $variant->primary->table;
        $membership = $variant->membership;

        return $variant->target === null
            ? "its servedFrom() selector chooses {$primary}, and no {$primary} row".($membership === null ? '' : " whose {$membership->column} is '{$membership->alias}'")." holds its {$key}, so install() refuses it"
            : "its relatedMorph() choose: closure picks {$variant->target->table}, and its {$primary} row points at no {$variant->target->table} row, so install() refuses it";
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

        if ($watermark === null || $watermark['key'] === null || $updatedAt === null || ! array_key_exists($updatedAt, $shape)) {
            return ['', []];
        }

        $bound = SqlFragments::isNaive($shape[$updatedAt]->type) ? SqlFragments::inApplicationZone('CAST(? AS timestamptz)') : 'CAST(? AS timestamptz)';

        return ["NOT ({$table}.".SqlFragments::identifier(self::key($map))." > ? OR COALESCE({$table}.".SqlFragments::identifier($updatedAt)." >= {$bound}, false))", [$watermark['key'], $watermark['updatedAt']]];
    }

    /**
     * @return list<DriftFinding>
     */
    private function retainedWithoutPrimary(ResolvedMap $map, int $limit): array
    {
        $retainedTable = ViewCompiler::retainedTable($map);
        $retained = SqlFragments::identifier($retainedTable);
        $retainedKey = $retained.'.'.SqlFragments::identifier((string) $map->retainedKey);

        if ($map->branches === []) {
            $primary = SqlFragments::identifier($map->primary->table);
            $owner = "SELECT FROM {$primary} WHERE {$primary}.".SqlFragments::identifier($map->primary->key)." = {$retainedKey}";
            $sql = "SELECT {$retainedKey} AS key, EXISTS ({$owner}) AS reclassified FROM {$retained} WHERE NOT EXISTS ({$owner}".($map->membership === null ? '' : ' AND '.$map->membership->of($map->primary->table)).')';
        } else {
            $missing = array_map(fn (ResolvedMap $branch) => 'NOT EXISTS (SELECT FROM '.SqlFragments::identifier($branch->primary->table).' WHERE '.self::holding($branch, self::key($map), $retainedKey).')', self::served($map));
            $sql = "SELECT {$retainedKey} AS key, false AS reclassified FROM {$retained} WHERE ".implode(' AND ', $missing);
        }

        $membership = $map->membership;

        return array_values(array_map(
            fn (object $row) => new DriftFinding($map->model, DriftCheck::RetainedWithoutPrimary, self::record($retainedTable, $row->key ?? null), ($row->reclassified ?? null) === true && $membership !== null
                ? "its {$membership->table} row no longer has {$membership->column} '{$membership->alias}', so the view serves none of its legacy-only values"
                : 'no '.self::successors($map).' holds it, so the view serves none of its legacy-only values'),
            $this->connection->select("{$sql} ORDER BY {$retainedKey} LIMIT ?", [$limit], false),
        ));
    }

    /**
     * @return list<DriftFinding>
     */
    private function heldTwice(ResolvedMap $map, int $limit): array
    {
        if ($map->branches === []) {
            return [];
        }

        $key = self::key($map);
        $held = array_map(function (ResolvedMap $branch) use ($key): string {
            $table = SqlFragments::identifier($branch->primary->table);

            return "SELECT {$table}.".SqlFragments::identifier((string) $branch->identifiers[$key]->column()).' AS key, '.SqlFragments::literal($branch->primary->table)." AS successor FROM {$table}"
                .($branch->membership === null ? '' : ' WHERE '.$branch->membership->of($branch->primary->table));
        }, self::served($map));
        $rows = $this->connection->select(
            "SELECT held.key, string_agg(DISTINCT held.successor, ' and ' ORDER BY held.successor) AS successors FROM (".implode(' UNION ALL ', $held).') AS held'
                .' WHERE held.key IS NOT NULL GROUP BY held.key HAVING count(DISTINCT held.successor) > 1 ORDER BY held.key LIMIT ?',
            [$limit],
            false,
        );

        return array_values(array_map(
            fn (object $row) => new DriftFinding($map->model, DriftCheck::HeldTwice, self::record($map->table, $row->key ?? null), self::text($row->successors ?? null)." both hold its {$key}, so the view serves the record twice"),
            $rows,
        ));
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
        $rows = $this->connection->select(
            "SELECT {$primary}.".SqlFragments::identifier((string) $map->identifiers[self::key($map)]->column())." AS key, {$type} AS type FROM ".ViewCompiler::primary($map)
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
    private function unclassified(ResolvedMap $map): array
    {
        $findings = [];

        foreach (self::served($map) as $branch) {
            $membership = $branch->membership;

            if ($membership === null) {
                continue;
            }

            $count = $this->connection->scalar('SELECT count(*) FROM '.SqlFragments::identifier($membership->table).' WHERE '.SqlFragments::identifier($membership->column).' IS NULL', [], false);

            if (is_int($count) && $count > 0) {
                $findings[] = new DriftFinding($map->model, DriftCheck::Unclassified, $membership->table, "{$count} ".($count === 1 ? 'row has' : 'rows have')." no {$membership->column}, so no legacy model serves ".($count === 1 ? 'it' : 'them'));
            }
        }

        return $findings;
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

    /**
     * @return list<ResolvedMap>
     */
    private static function served(ResolvedMap $map): array
    {
        return $map->branches === [] ? [$map] : array_values($map->branches);
    }

    private static function key(ResolvedMap $map): string
    {
        return (new $map->model)->getKeyName();
    }

    private static function holding(ResolvedMap $branch, string $key, string $legacyKey): string
    {
        $primary = SqlFragments::identifier($branch->primary->table);

        return "{$primary}.".SqlFragments::identifier((string) $branch->identifiers[$key]->column())." = {$legacyKey}"
            .($branch->membership === null ? '' : ' AND '.$branch->membership->of($branch->primary->table));
    }

    private static function held(ResolvedMap $map, string $legacy): string
    {
        $key = self::key($map);

        return '('.implode(' OR ', array_map(function (ResolvedMap $branch) use ($key, $legacy): string {
            $primary = SqlFragments::identifier($branch->primary->table);
            $matches = [];

            foreach ($branch->identifiers as $name => $identifier) {
                $matches[] = "{$primary}.".SqlFragments::identifier((string) $identifier->column()).($name === $key ? ' = ' : ' IS NOT DISTINCT FROM ')."{$legacy}.".SqlFragments::identifier($name);
            }

            if ($branch->membership !== null) {
                $matches[] = $branch->membership->of($branch->primary->table);
            }

            return "EXISTS (SELECT FROM {$primary} WHERE ".implode(' AND ', $matches).')';
        }, self::served($map))).')';
    }

    private static function successors(ResolvedMap $map): string
    {
        $served = self::served($map);
        $membership = $served[0]->membership;

        return implode(' or ', array_map(fn (ResolvedMap $branch) => $branch->primary->table, $served)).' row'
            .($membership === null ? '' : " whose {$membership->column} is '{$membership->alias}'");
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
