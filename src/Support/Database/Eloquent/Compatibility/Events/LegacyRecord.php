<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression as QueryExpression;
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
    private const string WRITTEN = 'compatibility:written';

    /** @var array<array-key, Model> */
    private array $records = [];

    /** @var array<array-key, array<string, mixed>> */
    private array $before = [];

    private null|string $computed = null;

    private bool $stored = false;

    /** @var null|array<string, mixed> */
    private null|array $storedFrom = null;

    /** @var null|list<string> */
    private null|array $reached = null;

    /** @var array<string, mixed> */
    private array $destinations = [];

    /** @var array<array-key, bool> */
    private array $serves = [];

    private bool $expressed = false;

    /** @var array<string, mixed> */
    private array $keys = [];

    /**
     * @param  array<string, string>  $columns
     * @param  list<string>  $json
     * @param  list<string>  $reads
     */
    private function __construct(
        private readonly ResolvedMap $map,
        private readonly ResolvedMap $served,
        private readonly null|Related $dependent,
        private readonly array $columns,
        private readonly array $json,
        private readonly array $reads,
        public readonly bool $removes,
    ) {}

    /**
     * @return array{array<string, string>, list<string>, list<string>}
     */
    public static function compile(ResolvedMap $map, ResolvedMap $served, Model $successor): array
    {
        $connection = $successor->getConnection();
        $catalog = new ColumnCatalog($connection, throughSearchPath: true);
        $columns = (new ViewCompiler($catalog, $connection))->served($served, $catalog->of($map->table), $map->externals());
        $json = array_filter($catalog->of($successor->getTable()), fn (Column $column) => in_array($column->baseType ?? $column->type, ['json', 'jsonb'], true));
        $reads = $connection->select(
            "select distinct attribute.attname as name from pg_rewrite rewrite join pg_depend depend on depend.classid = 'pg_rewrite'::regclass and depend.objid = rewrite.oid join pg_attribute attribute on attribute.attrelid = depend.refobjid and attribute.attnum = depend.refobjsubid where rewrite.ev_class = to_regclass(?) and depend.refobjid = to_regclass(?)",
            [SqlFragments::identifier($map->table), SqlFragments::identifier($successor->getTable())],
            false,
        );

        return [$columns, array_map(strval(...), array_keys($json)), array_map(fn (object $read) => (string) $read->name, $reads)];
    }

    /**
     * @param  array<string, string>  $columns
     * @param  list<string>  $json
     * @param  list<string>  $reads
     */
    public static function read(ResolvedMap $map, ResolvedMap $served, null|Related $dependent, array $columns, array $json, array $reads, Model $successor, bool $removes): null|self
    {
        $record = new self($map, $served, $dependent, $columns, $json, $reads, $removes);

        if ($dependent === null && ! $successor->exists) {
            return $record;
        }

        return $record->admit($successor) ? $record : null;
    }

    /**
     * @return array<array-key, Model>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function after(Model $successor, bool $written = false): void
    {
        $this->expressed = $this->dependent !== null && $this->expresses($successor);
        $values = $this->values($successor);
        $computed = serialize([$successor->exists, array_diff_key($values, [$successor->getKeyName() => true])]);

        if ($computed === $this->computed) {
            return;
        }

        $this->computed = $computed;
        $rows = $this->compute($successor, $values, $written);

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

        if (! $written) {
            $this->admit($successor);
        }

        foreach (array_keys($this->records) as $key) {
            if (array_key_exists($key, $rows)) {
                $this->apply($key, $rows[$key]);
            } else {
                unset($this->records[$key], $this->before[$key]);
            }
        }
    }

    /**
     * @return array<array-key, Model>
     */
    public function changed(): array
    {
        if ($this->expressed) {
            return $this->records;
        }

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
        if ($this->stored && $this->storedFrom === $successor->getAttributes()) {
            return;
        }

        $this->stored = true;
        $this->storedFrom = $successor->getAttributes();

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
                unset($this->records[$key], $this->before[$key]);

                continue;
            }

            $legacy->setRawAttributes(array_replace($legacy->getAttributes(), $row));

            if (! $legacy->exists) {
                $legacy->exists = true;
                $legacy->wasRecentlyCreated = true;
                ExternalAttributes::gather([$legacy], true);
            }

            $this->reload($legacy);

            if ($this->dependent === null && $this->removes && method_exists($legacy, 'getDeletedAtColumn')) {
                $legacy->syncOriginalAttributes(array_values(array_filter([(string) $legacy->getDeletedAtColumn(), $legacy->usesTimestamps() ? $legacy->getUpdatedAtColumn() : null], is_string(...))));
            } elseif (! $legacy->wasRecentlyCreated) {
                $legacy->syncChanges();
            }
        }
    }

    public function serves(int|string $key): bool
    {
        return $this->serves[$key] ?? false;
    }

    public function reread(): void
    {
        $this->stored = false;
    }

    private function admit(Model $successor): bool
    {
        $filter = $this->filter($successor, false);
        $reaches = array_map(fn (mixed $value) => (string) $value, $filter[1] ?? []);

        if ($this->reached !== null && array_diff($reaches, $this->reached) === []) {
            return true;
        }

        $this->reached = array_values(array_unique([...$this->reached ?? [], ...$reaches]));
        $admitted = [];

        foreach ($this->stored($successor, false) as $key => $row) {
            if (array_key_exists($key, $this->before)) {
                continue;
            }

            if (Applying::writes($this->map->model, $key, $successor->getConnection()->getName(), fn (Model $held) => self::identity($this->map, $held))) {
                if ($this->dependent === null) {
                    return false;
                }

                continue;
            }

            $legacy = (new $this->map->model)->setConnection($successor->getConnectionName())->newInstance([], true);
            $legacy->setRawAttributes($row, true);
            $this->records[$key] = $legacy;
            $this->before[$key] = $row;
            $admitted[] = $legacy;
        }

        ExternalAttributes::gather($admitted, true);

        return true;
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
        $key = $legacy->getKey();

        if ((is_int($key) || is_string($key)) && (string) $key !== (string) ($original[$legacy->getKeyName()] ?? $key)) {
            ExternalAttributes::refreshed($legacy, $key);
        }

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
        $legacy = $this->records[$key];
        $legacy->setRawAttributes(array_replace($this->before[$key] ?? [], array_intersect_key($legacy->getAttributes(), array_flip($this->map->externals())), $row));
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
    private function compute(Model $successor, array $values, bool $after): array
    {
        $table = SqlFragments::identifier($successor->getTable());
        $key = SqlFragments::identifier($successor->getKeyName());
        $stored = $successor->exists ? $successor->getRawOriginal($successor->getKeyName()) ?? $successor->getKey() : null;
        $json = '{'.implode(',', array_map(fn (string $column, string $value) => json_encode($column, JSON_THROW_ON_ERROR).':'.$value, array_keys($values), $values)).'}';
        $written = $this->dependent === null ? '' : ', true AS '.SqlFragments::identifier(self::WRITTEN);
        [$row, $bindings] = $stored === null
            ? ['SELECT (jsonb_populate_record(NULL::'.$table.', CAST(? AS jsonb))).*'.$written, [$json]]
            : ['SELECT (jsonb_populate_record(stored, CAST(? AS jsonb))).*'.$written.' FROM '.$table.' AS stored WHERE stored.'.$key.' = ?', [$json, $stored]];
        $filter = null;

        if ($this->dependent !== null) {
            $filter = $this->filter($successor, $after);

            if ($filter === null) {
                return [];
            }

            $hard = $this->removes && ! self::softDeletes($successor);
            $others = 'SELECT '.$table.'.*, false AS '.SqlFragments::identifier(self::WRITTEN).' FROM '.$table.($stored === null ? '' : ' WHERE '.$table.'.'.$key.' <> ?');
            $row = $hard ? $others : $others.' UNION ALL '.$row;
            $bindings = [...($stored === null ? [] : [$stored]), ...($hard ? [] : $bindings), ...$filter[1]];
        }

        $select = $this->dependent === null ? [] : [SqlFragments::identifier($this->dependent->table).'.'.SqlFragments::identifier(self::WRITTEN).' AS '.SqlFragments::identifier(self::WRITTEN)];

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

        if ($this->dependent === null) {
            return array_map(fn (object $row) => (array) $row, $rows);
        }

        $keyed = $this->keyed($rows);

        foreach ($keyed as $legacyKey => $computed) {
            $this->serves[$legacyKey] = ($computed[self::WRITTEN] ?? null) === true;
            unset($keyed[$legacyKey][self::WRITTEN]);
        }

        return $keyed;
    }

    private function expresses(Model $successor): bool
    {
        foreach ($successor->exists ? $successor->getDirty() : $successor->getAttributes() as $column => $value) {
            if ($value instanceof Expression && in_array($column, $this->reads, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
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

            $prepared[(string) $column] = is_string($value) && in_array($column, $this->json, true) && json_validate($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
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
        $old = $this->relationshipKey($successor, $related->relatedKey);
        $attributes = $successor->getAttributes();
        $new = array_key_exists($related->relatedKey, $attributes) ? $attributes[$related->relatedKey] : $old;
        $new = $new instanceof Expression ? $this->destination($successor, $related->relatedKey, $new, $written) : $new;
        $values = array_values(array_unique(array_filter([$old, $new], fn (mixed $value) => $value !== null), SORT_REGULAR));

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

    private function destination(Model $successor, string $column, Expression $expression, bool $written): mixed
    {
        $connection = $successor->getConnection();
        $sql = (string) $expression->getValue($connection->getQueryGrammar());

        if (array_key_exists($sql, $this->destinations)) {
            return $this->destinations[$sql];
        }

        if ($written) {
            return $this->destinations[$sql] = $successor->newModelQuery()->useWritePdo()->whereKey($successor->getKey())->value($column);
        }

        $table = SqlFragments::identifier($successor->getTable());
        $row = $successor->exists
            ? $connection->selectOne('SELECT ('.$sql.') AS destination FROM '.$table.' WHERE '.$table.'.'.SqlFragments::identifier($successor->getKeyName()).' = ?', [$successor->getRawOriginal($successor->getKeyName()) ?? $successor->getKey()], false)
            : $connection->selectOne('SELECT ('.$sql.') AS destination', [], false);
        $destination = is_object($row) ? $row->destination ?? null : null;
        $literal = $connection->escape(is_scalar($destination) ? $destination : null);
        $successor->setRawAttributes(array_replace($successor->getAttributes(), [$column => new QueryExpression($literal)]));
        $this->destinations[$sql] = $this->destinations[$literal] = $destination;

        return $destination;
    }

    private function relationshipKey(Model $successor, string $column): mixed
    {
        $original = $successor->getRawOriginal();

        if (array_key_exists($column, $original) && ! $original[$column] instanceof Expression) {
            return $original[$column];
        }

        if (! array_key_exists($column, $this->keys)) {
            $this->keys[$column] = $successor->exists ? $successor->newModelQuery()->useWritePdo()->whereKey($original[$successor->getKeyName()] ?? $successor->getKey())->value($column) : null;
        }

        return $this->keys[$column];
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
