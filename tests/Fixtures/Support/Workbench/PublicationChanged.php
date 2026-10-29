<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

final readonly class PublicationChanged
{
    public function __construct(public Publication $publication) {}
}
