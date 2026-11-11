<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

use Support\Database\Eloquent\Compatibility\Write\Change;

final readonly class WriteIgnored
{
    public function __construct(
        public Change $change,
        public mixed $value,
    ) {}
}
