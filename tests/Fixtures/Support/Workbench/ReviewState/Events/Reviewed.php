<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\ReviewState\Events;

use Tests\Fixtures\Support\Workbench\Publication;

final readonly class Reviewed
{
    public function __construct(public Publication $publication) {}
}
