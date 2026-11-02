<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use BackedEnum;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Stringable;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\Fixed;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use WeakMap;
use WeakReference;

final class ExternalAttributes
{
    private const int CHUNK = 500;

    /** @var array<class-string<Model>, list<string>> */
    private static array $names = [];

    /** @var null|WeakMap<Model, self> */
    private static null|WeakMap $batches = null;

    /** @var array<string, true> */
    private array $loading = [];

    /**
     * @param  list<WeakReference<Model>>  $models
     */
    private function __construct(private readonly array $models) {}

    /**
     * @param  array<array-key, mixed>  $models
     */
    public static function gather(array $models): void
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

        $batch = new self(array_map(fn (Model $model) => WeakReference::create($model), $joining));

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
            $batches[$legacy] = new self([WeakReference::create($legacy)]);
        }

        $batches[$legacy]->load($legacy, $name);
    }

    public static function resolveAll(Model $legacy): void
    {
        foreach (self::of($legacy::class) as $name) {
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
     * @param  array<array-key, null|Model>  $records
     * @return array<array-key, mixed>
     */
    public static function values(ResolvedMap $map, string $name, array $records, null|string $connection): array
    {
        $legacyKey = (new $map->model)->getKeyName();
        $values = [];

        foreach ($map->branches === [] ? [$map] : $map->branches as $branch) {
            $attribute = $branch->attributes[$name];

            if (! $attribute->isExternal()) {
                continue;
            }

            $column = (string) $branch->identifiers[$legacyKey]->column();
            $query = (new $branch->primary->model)->setConnection($connection)->newModelQuery()->whereIn($column, array_keys($records));

            if ($branch->membership !== null) {
                $query->where($branch->membership->column, $branch->membership->alias);
            }

            $successors = $query->get();
            $read = $attribute->readExternally($successors);

            foreach ($successors as $successor) {
                $key = $successor->getAttribute($column);
                $successorKey = $successor->getKey();

                if ((is_int($key) || is_string($key)) && (is_int($successorKey) || is_string($successorKey))) {
                    $values[$key] = $read[$successorKey] ?? null;
                }
            }
        }

        foreach ($records as $key => $record) {
            if (array_key_exists($key, $values) || $record === null || $map->branches === []) {
                continue;
            }

            $attribute = $map->branchFor($record)->attributes[$name];
            $fixed = $attribute->conversions()[0] ?? null;
            $values[$key] = ! $attribute->isExternal() && $fixed instanceof Fixed ? $fixed->value : null;
        }

        return $values;
    }

    public static function verify(ResolvedMap $map, ConnectionInterface $connection, string $name): void
    {
        $key = (new $map->model)->getKeyName();
        $refused = 0;
        $first = [];

        $connection->table($map->table)->whereNotNull($name)->orderBy($key)->select([$key, $name])->chunk(self::CHUNK, function (iterable $rows) use ($map, $name, $key, &$refused, &$first): void {
            $stored = [];

            foreach ($rows as $row) {
                $row = (array) $row;
                $stored[self::key($row[$key] ?? null)] = $row[$name] ?? null;
            }

            $values = self::values($map, $name, array_fill_keys(array_keys($stored), null), (new $map->model)->getConnectionName());

            foreach ($stored as $legacyKey => $value) {
                if (! self::same($value, $values[$legacyKey] ?? null)) {
                    $refused++;

                    if (count($first) < 5) {
                        $first[] = $legacyKey;
                    }
                }
            }
        });

        if ($refused > 0) {
            throw InvalidCompatibilityMap::cannot($map->table, 'install', "{$refused} {$map->table} rows hold a {$name} that the external() read of attribute '{$name}' does not return; the first are {$key} ".implode(', ', $first).". Write each value through the attribute's external() write before install(), because install() drops {$map->table}.{$name}");
        }
    }

    public static function restore(ResolvedMap $map, ConnectionInterface $connection, string $name, string $type): void
    {
        $key = (new $map->model)->getKeyName();
        $table = SqlFragments::identifier($map->table);

        $connection->table($map->table)->orderBy($key)->select($key)->chunk(self::CHUNK, function (iterable $rows) use ($map, $connection, $name, $type, $key, $table): void {
            $keys = [];

            foreach ($rows as $row) {
                $keys[self::key(((array) $row)[$key] ?? null)] = null;
            }

            $bindings = [];

            foreach (self::values($map, $name, $keys, (new $map->model)->getConnectionName()) as $legacyKey => $value) {
                if ($value !== null) {
                    array_push($bindings, (string) $legacyKey, self::bindable($map, $name, $value));
                }
            }

            if ($bindings === []) {
                return;
            }

            $connection->update(
                "UPDATE {$table} SET ".SqlFragments::identifier($name)." = CAST(restored.value AS {$type})"
                .' FROM (VALUES '.implode(', ', array_fill(0, intdiv(count($bindings), 2), '(?, ?)')).') AS restored (key, value)'
                ." WHERE CAST({$table}.".SqlFragments::identifier($key).' AS text) = restored.key',
                $bindings,
            );
        });
    }

    private function load(Model $legacy, string $name): void
    {
        if (array_key_exists($name, $this->loading)) {
            return;
        }

        $pending = [];

        foreach ($this->models as $reference) {
            $model = $reference->get();

            if ($model === null || ! $model->exists || array_key_exists($name, $model->getAttributes())) {
                continue;
            }

            $key = $model->getRawOriginal($model->getKeyName()) ?? $model->getKey();

            if (is_int($key) || is_string($key)) {
                $pending[$key][] = $model;
            }
        }

        if ($pending === [] || ! LegacyBuilder::serves($legacy)) {
            return;
        }

        $this->loading[$name] = true;

        try {
            $values = self::values(LegacyBuilder::mapOf($legacy::class), $name, array_map(fn (array $models) => $models[0], $pending), $legacy->getConnectionName());
        } finally {
            unset($this->loading[$name]);
        }

        foreach ($pending as $key => $models) {
            foreach ($models as $model) {
                $model->setRawAttributes(array_replace($model->getAttributes(), [$name => $values[$key] ?? null]));
                $model->syncOriginalAttribute($name);
            }
        }
    }

    private static function key(mixed $key): int|string
    {
        return is_int($key) || is_string($key) ? $key : throw new LogicException('A legacy row has no key to resolve an external attribute by.');
    }

    private static function same(mixed $stored, mixed $read): bool
    {
        $read = $read instanceof BackedEnum ? $read->value : $read;

        return match (true) {
            $stored === null || $read === null => $stored === $read,
            is_bool($stored) || is_bool($read) => (bool) $stored === (bool) $read,
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
