<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use BackedEnum;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Stringable;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\Fixed;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use WeakMap;
use WeakReference;

final class ExternalAttributes
{
    private const int CHUNK = 500;

    private const string HELD_BY = 'compatibility_held_by';

    /** @var array<class-string<Model>, list<string>> */
    private static array $names = [];

    /** @var null|WeakMap<Model, self> */
    private static null|WeakMap $batches = null;

    /** @var null|WeakMap<Model, true> */
    private static null|WeakMap $written = null;

    /** @var array<string, true> */
    private array $loading = [];

    /**
     * @param  list<WeakReference<Model>>  $models
     */
    private function __construct(private readonly array $models, private readonly bool $writePdo, private readonly null|int|string $key = null) {}

    /**
     * @param  array<array-key, mixed>  $models
     */
    public static function gather(array $models, bool $writePdo): void
    {
        $first = reset($models);

        if (! $first instanceof Model || self::of($first::class) === []) {
            return;
        }

        $batches = self::$batches ??= new WeakMap;
        $joining = array_values(array_filter($models, fn (mixed $model) => $model instanceof Model && $model::class === $first::class && ! $batches->offsetExists($model)));

        if ($joining === []) {
            return;
        }

        $batch = new self(array_map(fn (Model $model) => WeakReference::create($model), $joining), $writePdo);

        foreach ($joining as $model) {
            $batches[$model] = $batch;
        }
    }

    public static function resolve(Model $legacy, string $name): void
    {
        if (! in_array($name, self::of($legacy::class), true)) {
            return;
        }

        $batches = self::$batches ??= new WeakMap;

        if (! $batches->offsetExists($legacy)) {
            $batches[$legacy] = new self([WeakReference::create($legacy)], self::$written?->offsetExists($legacy) ?? false);
        }

        $batches[$legacy]->load($legacy, $name);
    }

    public static function readFrom(Model $legacy, bool $writePdo): void
    {
        if ($writePdo) {
            $written = self::$written ??= new WeakMap;
            $written[$legacy] = true;
        }
    }

    public static function inherit(Model $from, Model $legacy): void
    {
        self::readFrom($legacy, self::$written?->offsetExists($from) ?? false);
    }

    public static function refreshed(Model $legacy, null|int|string $key = null): void
    {
        if (self::of($legacy::class) !== []) {
            $batches = self::$batches ??= new WeakMap;
            $batches[$legacy] = new self([WeakReference::create($legacy)], true, $key);
        }
    }

    public static function resolveOriginal(Model $legacy, mixed $key): void
    {
        if (! $legacy->exists) {
            return;
        }

        if ($key === null) {
            self::resolveAll($legacy);
        } elseif (is_string($key) && ! array_key_exists($key, $legacy->getAttributes())) {
            self::resolve($legacy, $key);
        }
    }

    /**
     * @param  null|list<string>  $names
     */
    public static function resolveAll(Model $legacy, null|array $names = null): void
    {
        foreach ($names ?? self::of($legacy::class) as $name) {
            if (! array_key_exists($name, $legacy->getAttributes())) {
                self::resolve($legacy, $name);
            }
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    public static function of(string $model): array
    {
        return self::$names[$model] ??= LegacyBuilder::mapOf($model)->externals();
    }

    /**
     * @param  list<int|string>  $keys
     * @return array<array-key, mixed>
     */
    public static function values(ResolvedMap $map, string $name, array $keys, null|string $connection, bool $writePdo): array
    {
        $values = [];
        $served = $map->branches === [] && $map->owners[$name] !== $map->primary ? $map->morphs : $map->branches;

        foreach ($served === [] ? [$map] : $served as $branch) {
            $attribute = $branch->attributes[$name];
            $owner = $branch->owners[$name];

            if ($attribute->isExternal()) {
                $values += $owner === $branch->primary
                    ? self::held($map, $branch, $keys, $connection, $writePdo, $attribute->readExternally(...), false)
                    : self::heldThrough($map, $branch, $owner->model, $keys, $connection, $writePdo, $attribute->readExternally(...));
            }
        }

        foreach ($served as $branch) {
            $attribute = $branch->attributes[$name];
            $fixed = $attribute->conversions()[0] ?? null;
            $remaining = array_values(array_filter($keys, fn (int|string $key) => ! array_key_exists($key, $values)));

            if ($remaining !== [] && ! $attribute->isExternal() && $fixed instanceof Fixed && $fixed->value !== null) {
                $values += array_fill_keys(array_keys(self::held($map, $branch, $remaining, $connection, $writePdo)), $fixed->value);
            }
        }

        return $values;
    }

    public static function verify(ResolvedMap $map, ConnectionInterface $connection, string $name): void
    {
        $key = (new $map->model)->getKeyName();
        $refused = 0;
        $first = [];

        $connection->table($map->table)->select([$key, $name])->chunkById(self::CHUNK, function (iterable $rows) use ($map, $name, $key, &$refused, &$first): void {
            $stored = [];

            foreach ($rows as $row) {
                $row = (array) $row;
                $stored[self::key($row[$key] ?? null)] = $row[$name] ?? null;
            }

            $values = self::values($map, $name, array_keys($stored), (new $map->model)->getConnectionName(), true);

            foreach ($stored as $legacyKey => $value) {
                if (! self::same($value, $values[$legacyKey] ?? null)) {
                    $refused++;

                    if (count($first) < 5) {
                        $first[] = $legacyKey;
                    }
                }
            }
        }, $key);

        if ($refused > 0) {
            throw InvalidCompatibilityMap::cannot($map->table, 'install', "{$refused} {$map->table} rows hold a {$name} that the external() read of attribute '{$name}' does not return; the first are {$key} ".implode(', ', $first).". Write each value through the attribute's external() write before install(), because install() drops {$map->table}.{$name}");
        }
    }

    public static function restore(ResolvedMap $map, ConnectionInterface $connection, string $name, string $type): void
    {
        $key = (new $map->model)->getKeyName();
        $retainedKey = (string) $map->retainedKey;
        $table = SqlFragments::identifier($map->table);
        $retainedType = (new ColumnCatalog($connection))->type($map->table, $retainedKey) ?? throw new LogicException("{$map->table} has no column {$retainedKey} to restore attribute '{$name}' by.");

        $connection->table($map->table)->select([$retainedKey, $key])->chunkById(self::CHUNK, function (iterable $rows) use ($map, $connection, $name, $type, $key, $retainedKey, $retainedType, $table): void {
            $retained = [];

            foreach ($rows as $row) {
                $row = (array) $row;
                $retained[self::key($row[$key] ?? null)] = self::key($row[$retainedKey] ?? null);
            }

            $bindings = [];

            foreach (self::values($map, $name, array_keys($retained), (new $map->model)->getConnectionName(), true) as $legacyKey => $value) {
                if ($value !== null) {
                    array_push($bindings, (string) $retained[$legacyKey], self::bindable($map, $name, $value));
                }
            }

            if ($bindings === []) {
                return;
            }

            $connection->update(
                "UPDATE {$table} SET ".SqlFragments::identifier($name)." = CAST(restored.value AS {$type})"
                .' FROM (VALUES '.implode(', ', array_fill(0, intdiv(count($bindings), 2), '(?, ?)')).') AS restored (key, value)'
                ." WHERE {$table}.".SqlFragments::identifier($retainedKey)." = CAST(restored.key AS {$retainedType})",
                $bindings,
            );
        }, $retainedKey);
    }

    /**
     * @param  list<int|string>  $keys
     * @param  null|Closure(Collection<array-key, Model>): array<array-key, mixed>  $read
     * @return array<array-key, mixed>
     */
    private static function held(ResolvedMap $map, ResolvedMap $branch, array $keys, null|string $connection, bool $writePdo, null|Closure $read = null, bool $aliased = true): array
    {
        $identifier = $branch->identifiers[(new $map->model)->getKeyName()];
        $column = (string) $identifier->column();
        $primary = (new $branch->primary->model)->setConnection($connection);
        $query = $primary->newModelQuery();
        $query->whereRaw($query->getQuery()->getGrammar()->wrap($primary->qualifyColumn($column)).' = any(cast(? as '.self::arrayType($identifier).'))', [self::arrayLiteral($keys)]);

        if ($writePdo) {
            $query->useWritePdo();
        }

        if ($branch->membership !== null) {
            $query->where($branch->membership->column, $branch->membership->alias);
        }

        if ($aliased && $branch->target !== null && $branch->morph !== null) {
            $query->where($branch->morph->typeColumn, $branch->target->morphAlias);
        }

        $successors = $query->get();
        $read = $read === null ? null : $read($successors);
        $held = [];

        foreach ($successors as $successor) {
            $key = $successor->getAttribute($column);
            $successorKey = $successor->getKey();

            if ((is_int($key) || is_string($key)) && (is_int($successorKey) || is_string($successorKey))) {
                $held[$key] = $read === null ? null : $read[$successorKey];
            }
        }

        return $held;
    }

    /**
     * @param  class-string<Model>  $owner
     * @param  list<int|string>  $keys
     * @param  Closure(Collection<array-key, Model>): array<array-key, mixed>  $read
     * @return array<array-key, mixed>
     */
    private static function heldThrough(ResolvedMap $map, ResolvedMap $branch, string $owner, array $keys, null|string $connection, bool $writePdo, Closure $read): array
    {
        $identifier = $branch->identifiers[(new $map->model)->getKeyName()];
        $legacyKey = SqlFragments::identifier($branch->primary->table).'.'.SqlFragments::identifier((string) $identifier->column());
        $model = (new $owner)->setConnection($connection);
        $table = $model->getTable();
        $query = $model->newQueryWithoutScopes()
            ->fromRaw(SqlFragments::identifier($branch->primary->table).' '.implode(' ', ViewCompiler::laterals($branch, $table)))
            ->select($table.'.*')
            ->selectRaw($legacyKey.' AS '.SqlFragments::identifier(self::HELD_BY))
            ->whereRaw($legacyKey.' = any(cast(? as '.self::arrayType($identifier).'))', [self::arrayLiteral($keys)])
            ->whereNotNull($table.'.'.$model->getKeyName());

        if ($writePdo) {
            $query->useWritePdo();
        }

        if ($branch->membership !== null) {
            $query->whereRaw($branch->membership->of($branch->primary->table));
        }

        $heldBy = [];
        $successors = $model->newCollection();

        foreach ($query->get() as $successor) {
            $attributes = $successor->getAttributes();
            $legacy = $attributes[self::HELD_BY] ?? null;
            $successorKey = $successor->getKey();
            $successor->setRawAttributes(array_diff_key($attributes, [self::HELD_BY => true]), true);

            if ((is_int($legacy) || is_string($legacy)) && (is_int($successorKey) || is_string($successorKey))) {
                $heldBy[$legacy] = $successorKey;
                $successors[$successorKey] ??= $successor;
            }
        }

        $values = $read($successors->values());

        return array_map(fn (int|string $successorKey) => $values[$successorKey] ?? null, $heldBy);
    }

    private function load(Model $legacy, string $name): void
    {
        if (array_key_exists($name, $this->loading)) {
            return;
        }

        $pending = [];
        $batches = self::$batches ??= new WeakMap;

        foreach ($this->models as $reference) {
            $model = $reference->get();

            if ($model === null || ! $model->exists || array_key_exists($name, $model->getAttributes()) || ($batches[$model] ?? $this) !== $this) {
                continue;
            }

            $key = $this->key ?? $model->getRawOriginal($model->getKeyName()) ?? $model->getKey();

            if (is_int($key) || is_string($key)) {
                $pending[$key][] = $model;
            }
        }

        if ($pending === [] || ! LegacyBuilder::serves($legacy)) {
            return;
        }

        $this->loading[$name] = true;

        try {
            $values = self::values(LegacyBuilder::mapOf($legacy::class), $name, array_keys($pending), $legacy->getConnectionName(), $this->writePdo);
        } finally {
            unset($this->loading[$name]);
        }

        $serve = Closure::bind(static function (Model $model, string $name, mixed $value): void {
            $model->attributes[$name] = $value;
            $model->original[$name] = $value;
        }, null, Model::class);

        foreach ($pending as $key => $models) {
            foreach ($models as $model) {
                $serve($model, $name, $values[$key] ?? null);
            }
        }
    }

    private static function arrayType(Identifier $identifier): string
    {
        return match (true) {
            $identifier->isUuid() => 'uuid[]',
            $identifier->isInteger() => 'bigint[]',
            default => 'text[]',
        };
    }

    /**
     * @param  list<int|string>  $keys
     */
    private static function arrayLiteral(array $keys): string
    {
        return '{'.implode(',', array_map(fn (int|string $key) => '"'.addcslashes((string) $key, '"\\').'"', $keys)).'}';
    }

    private static function key(mixed $key): int|string
    {
        return is_int($key) || is_string($key) ? $key : throw new LogicException('A legacy row has no key to resolve an external attribute by.');
    }

    public static function same(mixed $stored, mixed $read): bool
    {
        $read = $read instanceof BackedEnum ? $read->value : $read;

        return match (true) {
            $stored === null || $read === null => $stored === $read,
            is_bool($stored) || is_bool($read) => $stored === $read,
            is_scalar($stored) && (is_scalar($read) || $read instanceof Stringable) => (string) $stored === (string) $read,
            default => false,
        };
    }

    private static function bindable(ResolvedMap $map, string $name, mixed $value): bool|int|float|string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        return match (true) {
            is_scalar($value) => $value,
            $value instanceof Stringable => (string) $value,
            default => throw new LogicException("The external() read of attribute '{$name}' on {$map->table} returns ".get_debug_type($value).', which uninstall() cannot store in a column.'),
        };
    }
}
