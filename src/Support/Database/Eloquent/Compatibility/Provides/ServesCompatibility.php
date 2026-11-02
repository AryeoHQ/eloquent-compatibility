<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ReflectionClass;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Builder\LegacyQueryBuilder;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Exceptions\UndiscoveredServedModel;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Compatible
 */
trait ServesCompatibility
{
    /** @var array<class-string, true> */
    private static array $admittedServedModels = [];

    public static function bootServesCompatibility(): void
    {
        unset(self::$admittedServedModels[static::class]);
    }

    public function initializeServesCompatibility(): void
    {
        if (array_key_exists(static::class, self::$admittedServedModels)) {
            return;
        }

        if (! (new ReflectionClass(static::class))->isAnonymous() && ! Container::getInstance()->make(ServedModels::class)->admits(static::class)) {
            throw UndiscoveredServedModel::named(static::class);
        }

        self::$admittedServedModels[static::class] = true;
    }

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
            ExternalAttributes::resolveAll($this);
        }

        return parent::attributesToArray();
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
