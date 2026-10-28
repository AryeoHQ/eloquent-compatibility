<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

final readonly class MapMember
{
    public function __construct(
        public string $map,
        public string $alias,
    ) {}
}
