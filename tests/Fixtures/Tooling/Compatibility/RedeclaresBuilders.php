<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

abstract class RedeclaresBuilders extends Model implements Compatible
{
    use ServesCompatibility;

    public static function compatibility(Compatibility $compatibility): Compatibility
    {
        return $compatibility;
    }

    /**
     * @param  QueryBuilder  $query
     * @return Builder<static>
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    protected function newBaseQueryBuilder(): QueryBuilder
    {
        $connection = $this->getConnection();

        return new QueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        return $this->getAttributes();
    }
}
