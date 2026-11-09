<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Operations;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

final class WriteOrderTotal implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->deleteRelated(Order::class);
    }
}
