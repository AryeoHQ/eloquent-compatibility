<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;

final readonly class Exclusion
{
    /**
     * @param  list<ExcludedListener>  $listeners
     */
    public function __construct(
        public ModelEvent $event,
        public array $listeners,
    ) {}
}
