<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\InitializesLegacy;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Refusal;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements InitializesLegacy<Job>
 */
final class InitializeNativeListing implements InitializesLegacy
{
    public static int $pause = 0;

    public function initialize(Successors $successors, Creation $creation): void
    {
        usleep(self::$pause);
        $name = $successors->root()->name;

        if ($name === 'Refused job') {
            throw Refusal::because('A refused job has no listing.');
        }

        $creation->record->setAttribute('mls_number', 'NATIVE-'.$name);
    }
}
