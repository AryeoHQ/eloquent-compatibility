<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Hands every write of an Eloquent builder to the write router, when its model belongs to a registered compatibility
 * map. An application's own builder for a mapped model uses this trait; CompatibleBuilder is the default.
 *
 * @phpstan-require-extends Builder
 */
trait RoutesCompatibleWrites
{
    private null|Model $compatibilitySource = null;

    /**
     * @internal HasCompatibility marks the builder of a model's own update or delete
     */
    public function compatibilityWriteOf(Model $model): void
    {
        $this->compatibilitySource = $model;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return $this->toBase()->insert($values);
        }

        if ($values !== []) {
            $router->insert($this, $this->compatibilityRows($values), false);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  null|string  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int|string
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return $this->toBase()->insertGetId($values, $sequence);
        }

        return $router->insert($this, [$values], false)[0];
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return $this->toBase()->insertOrIgnore($values);
        }

        return $values === [] ? 0 : count($router->insert($this, $this->compatibilityRows($values), true));
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        $this->compatibilityRouter()?->refuseSetInsert($this, 'insertUsing');

        return $this->toBase()->insertUsing($columns, $query);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->compatibilityRouter()?->refuseSetInsert($this, 'insertOrIgnoreUsing');

        return $this->toBase()->insertOrIgnoreUsing($columns, $query);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  null|array<array-key, mixed>  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $this->compatibilityRouter()?->refuseSetInsert($this, 'upsert');

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return parent::update($values);
        }

        return $router->update($this, $this->addUpdatedAtColumn($values), $this->compatibilitySource);
    }

    /**
     * @param  null|array<int, string>|string  $column
     */
    public function touch($column = null): false|int
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return parent::touch($column);
        }

        $time = $this->model->freshTimestamp();

        if ($column === null) {
            $column = $this->model->getUpdatedAtColumn();

            if (! $this->model->usesTimestamps() || $column === null) {
                return false;
            }
        }

        $columns = [];

        foreach (Arr::wrap($column) as $name) {
            $columns[(string) $name] = $this->model->fromDateTime($time);
        }

        return $router->update($this, $columns, $this->compatibilitySource);
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        return $this->incrementEach([(string) $column => $amount], $extra);
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        return $this->decrementEach([(string) $column => $amount], $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return $this->toBase()->incrementEach($columns, $this->addUpdatedAtColumn($extra));
        }

        return $router->increment($this, $columns, $this->addUpdatedAtColumn($extra), $this->compatibilitySource);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        return $this->incrementEach(array_map(fn (float|int $amount) => -$amount, $columns), $extra);
    }

    /**
     * @return mixed
     */
    public function delete()
    {
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        $router = $this->compatibilityRouter();

        return $router === null ? $this->toBase()->delete() : $router->delete($this, $this->compatibilitySource);
    }

    /**
     * @return mixed
     */
    public function forceDelete()
    {
        $router = $this->compatibilityRouter();

        return $router === null ? $this->query->delete() : $router->delete($this, $this->compatibilitySource);
    }

    private function compatibilityRouter(): null|WriteRouter
    {
        $container = Container::getInstance();

        if (! $container->resolved(WriteRouter::class)) {
            return null;
        }

        $router = $container->make(WriteRouter::class);

        return $router->routes($this) ? $router : null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function compatibilityRows(array $values): array
    {
        /** @var list<array<string, mixed>> */
        return is_array(reset($values)) ? array_values($values) : [$values];
    }
}
