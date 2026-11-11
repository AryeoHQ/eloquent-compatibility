<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;

interface ScopesWrites
{
    /**
     * @template TResult
     *
     * @param  array<class-string<Model>, list<int|string>>  $successors
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public function run(array $successors, Closure $write): mixed;
}
