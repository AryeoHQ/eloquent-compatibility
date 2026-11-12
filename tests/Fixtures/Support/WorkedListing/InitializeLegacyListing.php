<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Support\Database\Eloquent\Compatibility\Contracts\InitializesLegacy;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements InitializesLegacy<Job>
 */
final class InitializeLegacyListing implements InitializesLegacy
{
    public function initialize(Successors $successors, Creation $creation): void
    {
        $creation->record->uuid = Listing::makeUuid();
        $creation->record->title = $successors->root()->name;
    }
}
