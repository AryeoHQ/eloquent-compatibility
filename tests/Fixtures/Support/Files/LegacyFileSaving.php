<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

final readonly class LegacyFileSaving
{
    public function __construct(public LegacyFile $legacyFile) {}
}
