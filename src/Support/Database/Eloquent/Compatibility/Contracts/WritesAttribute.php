<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

interface WritesAttribute
{
    /**
     * @param  Successors<Model>  $successors
     */
    public function write(Successors $successors, mixed $value, Change $change): void;
}
