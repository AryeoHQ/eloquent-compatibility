<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings\Lifecycle;

final readonly class Launching
{
    public function __construct(public StagedJob $model) {}
}
