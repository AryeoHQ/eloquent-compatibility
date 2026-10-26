<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

final readonly class DriftFinding
{
    public function __construct(
        public string $map,
        public DriftCheck $check,
        public string $recordId,
        public string $attribute,
        public string $detail,
    ) {}
}
