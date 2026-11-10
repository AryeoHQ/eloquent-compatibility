<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use LogicException;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\InitializesLegacy;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Events\Forwarder;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use WeakMap;

final class RetainedRows
{
    /** @var null|WeakMap<ServedModels, array<class-string<Model>, list<class-string<Model>>>> */
    private static null|WeakMap $carriers = null;

    public function __construct(private readonly ResolvedMap $map, private readonly ConnectionInterface $connection) {}

    public static function carries(Model $root): bool
    {
        return self::carriers($root) !== [];
    }

    public static function shares(Model $root): bool
    {
        return count(self::carriers($root)) > 1;
    }

    public static function carry(Model $root): void
    {
        $connection = $root->getConnection();
        $writing = Applying::legacyOf($root);
        $members = array_values(array_filter(
            array: self::carriers($root),
            callback: fn (ResolvedMap $map) => $writing?->getTable() !== $map->table && self::member($root, $map),
        ));
        self::refuseMoved(
            root: $root,
            chosen: Forwarder::chosen($root),
            members: $members,
        );

        foreach ($members as $map) {
            if ($connection->transactionLevel() > 0) {
                $connection->statement('LOCK TABLE '.SqlFragments::identifier($map->table).' IN ACCESS SHARE MODE');
            }

            if (! self::installed($connection, $map)) {
                continue;
            }

            (new self($map, $connection))->carried($root);
        }
    }

    private function carried(Model $root): void
    {
        $legacy = (new $this->map->model)->setConnection($root->getConnectionName());

        if ($legacy->usesUniqueIds()) {
            $legacy->setUniqueIds();
        }

        $sequence = $legacy->getKey() === null ? $this->connection->scalar('select pg_get_serial_sequence(?, ?)', [SqlFragments::identifier(ViewCompiler::retainedTable($this->map)), $legacy->getKeyName()]) : null;

        if (is_string($sequence)) {
            $legacy->setAttribute(
                key: $legacy->getKeyName(),
                value: $this->connection->scalar('select nextval(cast(? as regclass))', [$sequence]),
            );
        }

        $legacy->setAttribute(
            key: $this->map->successorKey,
            value: UnitOfWork::keyOf($root),
        );

        $initializer = $this->map->legacyInitializer;

        if ($initializer !== null) {
            $instance = Container::getInstance()->make($initializer);

            if (! $instance instanceof InitializesLegacy) {
                throw new LogicException("The initializer {$initializer} does not implement ".InitializesLegacy::class.'.');
            }

            $graphs = (new UnitOfWork(
                map: $this->map,
                connection: $root->getConnection(),
            ))->graphs([[$this->map, $root]], lock: true);
            UnitOfWork::hook(
                run: fn () => $instance->initialize($graphs[0]->successors(), new Creation(
                    record: $legacy,
                    origin: Origin::Live,
                )),
                origin: Origin::Live,
                model: $this->map->model,
                key: null,
                attribute: null,
                class: $initializer,
            );
        }

        $values = array_intersect_key($legacy->getAttributes(), array_flip($this->map->legacyOnly(array_keys($legacy->getAttributes()))));
        unset($values[$this->map->successorKey]);

        if ($legacy->getKey() === null) {
            unset($values[$legacy->getKeyName()]);
        }

        $this->insert(
            root: UnitOfWork::keyOf($root),
            values: $values,
        );
    }

    /**
     * @return list<ResolvedMap>
     */
    private static function carriers(Model $root): array
    {
        $served = Container::getInstance()->make(ServedModels::class);
        $carriers = self::$carriers ??= new WeakMap;
        $known = $carriers[$served] ?? [];

        if (! array_key_exists($root::class, $known)) {
            $known[$root::class] = array_values(array_filter(
                array: $served->all(),
                callback: function (string $model) use ($root): bool {
                    $successor = Compatibility::of($model)->successors()[0] ?? null;

                    return $successor !== null && is_a(
                        object_or_class: $root,
                        class: $successor,
                    );
                },
            ));
            $carriers[$served] = $known;
        }

        $maps = [];

        foreach ($known[$root::class] as $model) {
            $map = LegacyBuilder::mapOf($model);

            if ($map->retainedTable !== null) {
                $maps[] = $map;
            }
        }

        return $maps;
    }

    /**
     * @param  array<class-string<Model>, bool>  $chosen
     * @param  list<ResolvedMap>  $members
     */
    private static function refuseMoved(Model $root, array $chosen, array $members): void
    {
        $joined = array_map(fn (ResolvedMap $map) => $map->model, $members);

        foreach ($chosen as $model => $admitted) {
            if ($admitted !== in_array(
                needle: $model,
                haystack: $joined,
                strict: true,
            )) {
                throw CompatibilityWriteRefused::membershipMoved(
                    root: $root::class,
                    raised: array_keys(array_filter($chosen)),
                    joined: $joined,
                );
            }
        }
    }

    private static function member(Model $root, ResolvedMap $map): bool
    {
        $when = $map->primary->membershipScope();

        if ($when === null) {
            return true;
        }

        $query = $root->newModelQuery()->useWritePdo()->whereKey($root->getKey());
        $when($query);

        return $query->exists();
    }

    private static function installed(ConnectionInterface $connection, ResolvedMap $map): bool
    {
        return $connection->scalar("select exists (select from pg_class where oid = to_regclass(?) and relkind = 'v')", [SqlFragments::identifier($map->table)]) === true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(int|string $root, array $values): int|string
    {
        $key = (new $this->map->model)->getKeyName();
        $query = $this->connection->table(ViewCompiler::retainedTable($this->map));
        $row = [...$values, $this->map->successorKey => $root];
        $inserted = ((array) $this->connection->selectOne(
            query: $query->getGrammar()->compileInsertGetId(
                query: $query,
                values: $row,
                sequence: $key,
            ),
            bindings: $query->cleanBindings($row),
            useReadPdo: false,
        ))[$key] ?? null;

        if ($this->connection instanceof Connection) {
            $this->connection->recordsHaveBeenModified();
        }

        return is_int($inserted) || is_string($inserted) ? $inserted : throw new LogicException(ViewCompiler::retainedTable($this->map)." returned no {$key} for the new ".class_basename($this->map->model).'.');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function set(int|string $key, array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->connection->table(ViewCompiler::retainedTable($this->map))
            ->where(column: (new $this->map->model)->getKeyName(), operator: '=', value: $key)
            ->update($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    public function update(QueryBuilder $roots, array $values, array $amounts, null|string $alias = null): void
    {
        if ($values === [] && $amounts === []) {
            return;
        }

        $retained = ViewCompiler::retainedTable($this->map);
        $table = SqlFragments::identifier($retained);
        $alias ??= $this->map->table;
        $legacy = SqlFragments::identifier($alias);
        $from = SqlFragments::identifier($this->map->table).($alias === $this->map->table ? '' : ' AS '.$legacy);
        $successorKey = SqlFragments::identifier($this->map->successorKey);
        $catalog = new ColumnCatalog($this->connection, throughSearchPath: true);
        $query = $this->connection->table($retained);
        $grammar = $query->getGrammar();
        $columns = [];
        $selects = [];
        $bindings = [];

        foreach ($values as $key => $value) {
            $column = Str::before($key, '->');
            $columns[] = SqlFragments::identifier($column);

            if ($column !== $key) {
                $selects[] = Str::after($grammar->compileUpdate($query, [$key => $value]), ' set '.$grammar->wrap($column).' = ');
                $bindings = [...$bindings, ...$grammar->prepareBindingsForUpdate([], [$key => $value])];

                continue;
            }

            if ($value instanceof Expression) {
                $selects[] = (string) $value->getValue($grammar);

                continue;
            }

            $selects[] = 'CAST(? AS '.($catalog->type($retained, $column) ?? 'text').')';
            $bindings = [...$bindings, ...$grammar->prepareBindingsForUpdate([], [$key => $value])];
        }

        foreach ($amounts as $column => $amount) {
            $columns[] = SqlFragments::identifier($column);
            $selects[] = $legacy.'.'.SqlFragments::identifier($column).' + '.$amount;
        }

        $names = array_map(
            callback: fn (int $index) => 'compatibility_value_'.$index,
            array: array_keys($columns),
        );

        $this->connection->affectingStatement(
            'UPDATE '.$table.' SET '.implode(
                separator: ', ',
                array: array_map(fn (string $column, string $name) => $column.' = source.'.$name, $columns, $names),
            )
                .' FROM (SELECT '.$legacy.'.'.$successorKey.' AS compatibility_root, '.implode(
                    separator: ', ',
                    array: array_map(fn (string $select, string $name) => $select.' AS '.$name, $selects, $names),
                )
                .' FROM '.$from.' WHERE '.$legacy.'.'.$successorKey.' IN ('.$roots->toSql().')) AS source'
                .' WHERE '.$table.'.'.$successorKey.' = source.compatibility_root',
            [...$query->cleanBindings($bindings), ...$roots->getBindings()],
        );
    }
}
