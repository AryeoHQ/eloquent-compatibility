<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Builder\LegacyQueryBuilder;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Compatible
 */
trait ServesCompatibility
{
    /**
     * @param  QueryBuilder  $query
     * @return LegacyBuilder<$this>
     */
    public function newEloquentBuilder($query): LegacyBuilder
    {
        /** @var LegacyBuilder<$this> $builder */
        $builder = new LegacyBuilder($query);

        return $builder;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  bool  $exists
     * @return static
     */
    public function newInstance($attributes = [], $exists = false)
    {
        $model = parent::newInstance($attributes, $exists);

        if ($exists) {
            ExternalAttributes::inherit($this, $model);
        }

        return $model;
    }

    /**
     * @param  string  $key
     */
    public function getAttribute($key): mixed
    {
        if (is_string($key) && $this->exists && ! array_key_exists($key, $this->attributes)) {
            ExternalAttributes::resolve($this, $key);
        }

        return parent::getAttribute($key);
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        if (is_string($key) && $this->exists && ! array_key_exists($key, $this->original)) {
            ExternalAttributes::resolve($this, $key);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        if ($this->exists) {
            ExternalAttributes::resolveAll($this, array_keys($this->getArrayableItems(array_fill_keys(ExternalAttributes::of(static::class), true))));
        }

        return parent::attributesToArray();
    }

    /**
     * @param  null|string  $key
     * @param  mixed  $default
     */
    public function getOriginal($key = null, $default = null): mixed
    {
        ExternalAttributes::resolveOriginal($this, $key);

        return parent::getOriginal($key, $default);
    }

    /**
     * @param  null|string  $key
     * @param  mixed  $default
     */
    public function getRawOriginal($key = null, $default = null): mixed
    {
        ExternalAttributes::resolveOriginal($this, $key);

        return parent::getRawOriginal($key, $default);
    }

    /**
     * @param  null|array<array-key, mixed>  $except
     * @return static
     */
    public function replicate(null|array $except = null)
    {
        if ($this->exists) {
            ExternalAttributes::resolveAll($this);
        }

        return parent::replicate($except);
    }

    /**
     * @return $this
     */
    public function refresh()
    {
        parent::refresh();
        ExternalAttributes::refreshed($this);

        return $this;
    }

    protected function newBaseQueryBuilder(): LegacyQueryBuilder
    {
        $connection = $this->getConnection();

        return new LegacyQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor(), $this);
    }

    /**
     * @return null|bool
     */
    public function delete()
    {
        return LegacyBuilder::deleting($this, fn () => parent::delete());
    }

    /**
     * @param  string  $event
     * @param  bool  $halt
     * @return mixed
     */
    protected function fireModelEvent($event, $halt = true)
    {
        $result = parent::fireModelEvent($event, $halt);

        if ($event === 'restoring' && $result !== false) {
            LegacyBuilder::restoreOf($this);
        } elseif ($event === 'restored' || $result === false) {
            LegacyBuilder::forgetRestoreOf($this);
        }

        return $result;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        parent::setKeysForSaveQuery($query);

        if ($query instanceof LegacyBuilder) {
            $query->writeOf($this);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        LegacyBuilder::insertOf($this);

        return parent::getAttributesForInsert();
    }
}
