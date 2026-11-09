<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Successors;

interface InitializesSuccessors
{
    /**
     * @param  Successors<Model>  $successors
     */
    public function initialize(Successors $successors, Creation $creation): void;
}
