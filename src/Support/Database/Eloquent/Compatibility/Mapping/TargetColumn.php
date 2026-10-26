<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

final readonly class TargetColumn
{
    public function __construct(
        public string $alias,
        public string $column,
        public string $expression,
    ) {}
}
