<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Builder\LegacyQueryBuilder;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;

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
