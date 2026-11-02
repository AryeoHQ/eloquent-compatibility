<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings\Lifecycle;

use Tests\Fixtures\Support\Listings\Job;

final readonly class EnteringLive
{
    public function __construct(public Job $model) {}
}
