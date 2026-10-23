<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

final readonly class Finding
{
    public function __construct(
        public string $map,
        public string $check,
        public string $record,
        public string $attribute,
        public string $detail,
    ) {}
}
