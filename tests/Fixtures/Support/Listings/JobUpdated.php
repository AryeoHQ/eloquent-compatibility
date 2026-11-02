<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

final readonly class JobUpdated
{
    public function __construct(public Job $job) {}
}
