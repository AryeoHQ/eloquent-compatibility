<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Operations;

use Support\Database\Eloquent\Compatibility\Contracts\InitializesSuccessors;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Successors;

final class InitializeOrder implements InitializesSuccessors
{
    public function initialize(Successors $successors, Creation $creation): void
    {
        $successors->relatedOrNew(Invoice::class)->setAttribute(key: 'number', value: $creation->record->getKey());
    }
}
