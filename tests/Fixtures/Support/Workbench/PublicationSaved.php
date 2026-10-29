<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

final readonly class PublicationSaved
{
    public function __construct(public Publication $publication) {}
}
