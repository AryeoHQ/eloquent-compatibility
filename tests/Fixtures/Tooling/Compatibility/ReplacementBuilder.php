<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;

trait ReplacementBuilder
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
}
