<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Schema\Column;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use Support\Database\Eloquent\Compatibility\Write\Applying;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;

final class LegacyRecord
{
    /** @var array<array-key, Model> */
    private array $records = [];

    /** @var array<array-key, array<string, mixed>> */
    private array $before = [];

    private null|string $computed = null;

    private bool $stored = false;

    /**
     * @param  array<string, string>  $columns
     * @param  list<string>  $json
     */
    private function __construct(
        private readonly ResolvedMap $map,
        private readonly ResolvedMap $served,
        private readonly null|Related $dependent,
        private readonly array $columns,
        private readonly array $json,
        public readonly bool $removes,
    ) {}

    /**
     * @return array{array<string, string>, list<string>}
     */
    public static function compile(ResolvedMap $map, ResolvedMap $served, Model $successor): array
    {
        $connection = $successor->getConnection();
        $catalog = new ColumnCatalog($connection);
        $columns = (new ViewCompiler($catalog, $connection))->served($served, $catalog->of($map->table), $map->externals());
        $json = array_filter($catalog->of($successor->getTable()), fn (Column $column) => in_array($column->baseType ?? $column->type, ['json', 'jsonb'], true));

        return [$columns, array_map(strval(...), array_keys($json))];
    }

    /**
     * @param  array<string, string>  $columns
     * @param  list<string>  $json
     */
    public static function read(ResolvedMap $map, ResolvedMap $served, null|Related $dependent, array $columns, array $json, Model $successor, bool $removes): null|self
    {
        $record = new self($map, $served, $dependent, $columns, $json, $removes);

        if ($dependent === null && ! $successor->exists) {
            return $record;
        }

        foreach ($record->stored($successor, false) as $key => $row) {
            if (Applying::writes($map->model, $key, fn (Model $held) => self::identity($map, $held))) {
                if ($dependent === null) {
                    return null;
                }

                continue;
            }

            $legacy = (new $map->model)->setConnection($successor->getConnectionName())->newInstance([], true);
            $legacy->setRawAttributes($row, true);
            $record->records[$key] = $legacy;
            $record->before[$key] = $row;
        }

        ExternalAttributes::gather(array_values($record->records), true);

        return $record;
    }

    /**
     * @return array<array-key, Model>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function after(Model $successor): void
    {
        $values = $this->values($successor);
        $computed = serialize(array_diff_key($values, [$successor->getKeyName() => true]));

        if ($computed === $this->computed) {
            return;
        }

        $this->computed = $computed;
        $rows = $this->compute($successor, $values);

        if ($this->dependent === null) {
            $key = array_key_first($this->records) ?? '';
            $row = reset($rows);

            if ($row === false || ($this->before !== [] && $this->records === [])) {
                $this->records = [];

                return;
            }

            $this->records[$key] ??= (new $this->map->model)->setConnection($successor->getConnectionName());
            $this->apply($key, $row);

            return;
        }

        foreach (array_keys($this->records) as $key) {
            $this->apply($key, $rows[$key] ?? array_fill_keys(array_keys($this->columns), null));
        }
    }

    /**
     * @return array<array-key, Model>
     */
    public function changed(): array
    {
        $changed = [];

        foreach ($this->records as $key => $legacy) {
            foreach (array_keys($this->columns) as $name) {
                if (($this->before[$key][$name] ?? null) !== ($legacy->getAttributes()[$name] ?? null)) {
                    $changed[$key] = $legacy;

                    break;
                }
            }
        }

        return $changed;
    }

    public function store(Model $successor): void
    {
        if ($this->stored) {
            return;
        }

        $this->stored = true;

        if ($this->dependent === null && ! $successor->exists) {
            foreach ($this->records as $legacy) {
                $legacy->exists = false;
            }

            return;
        }

        $rows = $this->stored($successor, true);

        if ($this->dependent === null && $rows === []) {
            $this->records = [];

            return;
        }

        foreach ($this->records as $key => $legacy) {
            $row = $this->dependent === null ? reset($rows) : $rows[$key] ?? false;

            if ($row === false) {
                continue;
            }

            $legacy->setRawAttributes(array_replace($legacy->getAttributes(), $row));

            if (! $legacy->exists) {
                $legacy->exists = true;
                $legacy->wasRecentlyCreated = true;
                ExternalAttributes::gather([$legacy], true);

                continue;
            }

            $this->reload($legacy);

            if (! $legacy->wasRecentlyCreated) {
                $legacy->syncChanges();
            }
        }
    }

    public function reread(): void
    {
        $this->stored = false;
    }

    private static function identity(ResolvedMap $map, Model $held): null|int|string
    {
        $served = $map->branches === [] ? ($map->primary->model === $held::class ? $map : null) : $map->branches[$held::class] ?? null;

        if ($served === null || ! $held->exists) {
            return null;
        }

        $column = (string) $served->identifiers[(new $map->model)->getKeyName()]->column();
        $key = $held->getAttributes()[$column] ?? $held->newModelQuery()->whereKey($held->getKey())->value($column);

        return is_int($key) || is_string($key) ? $key : null;
    }

    private function reload(Model $legacy): void
    {
        $resolved = array_intersect_key($legacy->getAttributes(), array_flip($this->map->externals()));

        if ($resolved === []) {
            return;
        }

        $original = $legacy->getRawOriginal();
        $legacy->setRawAttributes(array_diff_key($legacy->getAttributes(), $resolved));

        foreach (array_keys($resolved) as $name) {
            $legacy->getAttribute($name);
        }

        $after = $legacy->getAttributes();
        $legacy->setRawAttributes($original, true);
        $legacy->setRawAttributes($after);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function apply(int|string $key, array $row): void
    {
        $this->records[$key]->setRawAttributes(array_replace($this->before[$key] ?? [], $row));
    }

    /**
     * @return array<array-key, array<string, mixed>>
     */
    private function stored(Model $successor, bool $written): array
    {
        $filter = $this->filter($successor, $written);

        if ($filter === null) {
            return [];
        }

        $primary = $this->served->primary->table;
        $legacyKey = $this->legacyKey();
        $identifier = SqlFragments::identifier($primary).'.'.SqlFragments::identifier((string) $this->served->identifiers[$legacyKey]->column());
        $rows = $successor->getConnection()->select(
            'SELECT * FROM '.SqlFragments::identifier($this->map->table).' WHERE '.SqlFragments::identifier($legacyKey).' IN (SELECT '.$identifier.' FROM '.ViewCompiler::primary($this->served).' WHERE '.$filter[0].') ORDER BY '.SqlFragments::identifier($legacyKey),
            $filter[1],
            false,
        );

        return $this->keyed($rows);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<array-key, array<string, mixed>>
     */
    private function compute(Model $successor, array $values): array
    {
        $table = SqlFragments::identifier($successor->getTable());
        $key = SqlFragments::identifier($successor->getKeyName());
        $stored = $successor->exists ? $successor->getRawOriginal($successor->getKeyName()) ?? $successor->getKey() : null;
        $json = json_encode((object) $values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        [$row, $bindings] = $stored === null
            ? ['SELECT (jsonb_populate_record(NULL::'.$table.', CAST(? AS jsonb))).*', [$json]]
            : ['SELECT (jsonb_populate_record(stored, CAST(? AS jsonb))).* FROM '.$table.' AS stored WHERE stored.'.$key.' = ?', [$json, $stored]];
        $filter = null;

        if ($this->dependent !== null) {
            $filter = $this->filter($successor, false);

            if ($filter === null) {
                return [];
            }

            $hard = $this->removes && ! self::softDeletes($successor);
            $others = 'SELECT * FROM '.$table.($stored === null ? '' : ' WHERE '.$table.'.'.$key.' <> ?');
            $row = $hard ? $others : $others.' UNION ALL '.$row;
            $bindings = [...($stored === null ? [] : [$stored]), ...($hard ? [] : $bindings), ...$filter[1]];
        }

        $select = [];

        foreach ($this->columns as $name => $sql) {
            $select[] = $sql.' AS '.SqlFragments::identifier($name);
        }

        $rows = $successor->getConnection()->select(
            'WITH '.$table.' AS ('.$row.') SELECT '.implode(', ', $select).' FROM '.ViewCompiler::primary($this->served)
                .implode('', array_map(fn (string $lateral) => ' '.$lateral, ViewCompiler::laterals($this->served)))
                .($filter === null ? '' : ' WHERE '.$filter[0]),
            $bindings,
            false,
        );

        return $this->dependent === null ? array_map(fn (object $row) => (array) $row, $rows) : $this->keyed($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Model $successor): array
    {
        $values = $successor->exists ? $successor->getDirty() : $successor->getAttributes();

        if ($this->removes && self::softDeletes($successor) && method_exists($successor, 'getDeletedAtColumn')) {
            $values[(string) $successor->getDeletedAtColumn()] = $successor->freshTimestampString();
        }

        $prepared = [];

        foreach ($values as $column => $value) {
            if ($value instanceof Expression) {
                continue;
            }

            $prepared[$column] = is_string($value) && in_array($column, $this->json, true) && json_validate($value) ? json_decode($value) : $value;
        }

        return $prepared;
    }

    /**
     * @return null|array{string, list<mixed>}
     */
    private function filter(Model $successor, bool $written): null|array
    {
        $primary = $this->served->primary;

        if ($this->dependent === null) {
            $key = $written ? $successor->getKey() : $successor->getRawOriginal($successor->getKeyName()) ?? $successor->getKey();

            return $key === null ? null : [SqlFragments::identifier($primary->table).'.'.SqlFragments::identifier($primary->key).' = ?', [$key]];
        }

        $related = $this->dependent;
        $values = array_values(array_unique(array_filter([$successor->getRawOriginal($related->relatedKey), $successor->getAttributes()[$related->relatedKey] ?? null], fn (mixed $value) => $value !== null), SORT_REGULAR));

        if ($values === []) {
            return null;
        }

        $condition = SqlFragments::identifier($related->parentTable).'.'.SqlFragments::identifier($related->parentKey).' IN ('.implode(', ', array_fill(0, count($values), '?')).')';

        while ($related->parentTable !== $primary->table) {
            $parent = $this->relatedTo($related->parentTable);
            $condition = SqlFragments::identifier($parent->parentTable).'.'.SqlFragments::identifier($parent->parentKey).' IN (SELECT '.SqlFragments::identifier($parent->table).'.'.SqlFragments::identifier($parent->relatedKey).' FROM '.SqlFragments::identifier($parent->table).' WHERE '.$condition.')';
            $related = $parent;
        }

        return [$condition, $values];
    }

    private function relatedTo(string $table): Related
    {
        foreach ($this->served->related as $related) {
            if ($related->table === $table) {
                return $related;
            }
        }

        throw new LogicException("Compatibility map '{$this->map->table}' reaches no table {$table} through related().");
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<array-key, array<string, mixed>>
     */
    private function keyed(array $rows): array
    {
        $keyed = [];
        $legacyKey = $this->legacyKey();

        foreach ($rows as $row) {
            $row = (array) $row;
            $key = $row[$legacyKey] ?? null;

            if (is_int($key) || is_string($key)) {
                $keyed[(string) $key] = $row;
            }
        }

        return $keyed;
    }

    private function legacyKey(): string
    {
        return (new $this->map->model)->getKeyName();
    }

    private static function softDeletes(Model $successor): bool
    {
        return method_exists($successor, 'isForceDeleting') && ! $successor->isForceDeleting();
    }
}
