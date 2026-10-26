<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

final readonly class Assignment
{
    public function __construct(
        public Attribute $attribute,
        public TargetColumn $targetColumn,
    ) {}
}
