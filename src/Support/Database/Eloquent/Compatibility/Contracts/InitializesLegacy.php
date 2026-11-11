<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @template TRoot of Model
 */
interface InitializesLegacy
{
    /**
     * @param  Successors<TRoot>  $successors
     */
    public function initialize(Successors $successors, Creation $creation): void;
}
