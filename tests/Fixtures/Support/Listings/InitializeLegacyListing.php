<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\InitializesLegacy;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements InitializesLegacy<Job>
 */
final class InitializeLegacyListing implements InitializesLegacy
{
    /** @var list<array{mixed, mixed}> */
    public static array $seen = [];

    public function initialize(Successors $successors, Creation $creation): void
    {
        self::$seen[] = [$creation->record->getAttribute('id'), $creation->record->getAttribute('job_id')];
        $creation->record->setAttribute('mls_number', 'JOB-'.$successors->root()->name);
    }
}
