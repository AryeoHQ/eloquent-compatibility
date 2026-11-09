<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\InitializesLegacy;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Successors;

final class InitializeLegacyListing implements InitializesLegacy
{
    /**
     * @param  Successors<Job>  $successors
     */
    public function initialize(Successors $successors, Creation $creation): void
    {
        $creation->record->setAttribute('mls_number', 'JOB-'.$successors->root()->name);
    }
}
