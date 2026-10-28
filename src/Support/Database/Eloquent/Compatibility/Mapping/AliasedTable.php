<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

final readonly class AliasedTable
{
    public function __construct(
        public string $table,
        public string $keyColumn,
    ) {}
}
