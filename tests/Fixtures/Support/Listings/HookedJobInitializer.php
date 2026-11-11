<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\InitializesSuccessors;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements InitializesSuccessors<Job>
 */
final readonly class HookedJobInitializer implements InitializesSuccessors
{
    public function __construct(private RecordsHooks $hooks) {}

    public function initialize(Successors $successors, Creation $creation): void
    {
        $this->hooks->record(self::class);
    }
}
