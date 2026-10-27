<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Events;

use Tests\Fixtures\Support\Workbench\Publication;

final readonly class Transitioning
{
    public function __construct(public Publication $publication) {}
}
