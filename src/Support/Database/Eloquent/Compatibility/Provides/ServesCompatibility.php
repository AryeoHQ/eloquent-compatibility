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
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        if ($this->exists) {
            ExternalAttributes::resolveAll($this);
        }

        return parent::attributesToArray();
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
