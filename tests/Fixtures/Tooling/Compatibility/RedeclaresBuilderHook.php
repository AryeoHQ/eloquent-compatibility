<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

final class RedeclaresBuilderHook extends Model implements Compatible
{
    use HasCompatibility;

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function newEloquentBuilder($query)
    {
        return parent::newEloquentBuilder($query);
    }
}
