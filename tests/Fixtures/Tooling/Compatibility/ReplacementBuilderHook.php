<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

trait ReplacementBuilderHook
{
    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function newEloquentBuilder($query)
    {
        return new \Illuminate\Database\Eloquent\Builder($query);
    }
}
