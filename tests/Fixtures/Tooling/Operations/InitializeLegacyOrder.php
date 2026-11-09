<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Operations;

use Support\Database\Eloquent\Compatibility\Contracts\InitializesLegacy;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Successors;

final class InitializeLegacyOrder implements InitializesLegacy
{
    public function initialize(Successors $successors, Creation $creation): void
    {
        $successors->deleteRelated(Shipment::class);
    }
}
