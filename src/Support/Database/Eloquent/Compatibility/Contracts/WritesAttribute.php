<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @template TRoot of Model
 */
interface WritesAttribute
{
    /**
     * @param  Successors<TRoot>  $successors
     */
    public function write(Successors $successors, mixed $value, Change $change): void;
}
