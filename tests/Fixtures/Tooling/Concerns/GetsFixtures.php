<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Concerns;

trait GetsFixtures
{
    protected function getFixturePath(string $filename): string
    {
        return __DIR__.'/../'.$filename;
    }
}
