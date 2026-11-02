<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings\Lifecycle;

final readonly class Launched
{
    public function __construct(public StagedJob $model) {}
}
