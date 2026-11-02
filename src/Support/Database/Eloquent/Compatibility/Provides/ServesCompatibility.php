<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
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
}
