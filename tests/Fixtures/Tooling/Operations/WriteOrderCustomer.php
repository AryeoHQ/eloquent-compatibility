<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Operations;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

final class WriteOrderCustomer implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->related(Customer::class)?->setAttribute(key: 'name', value: $value);
        $successors->related(Receipt::class)?->setAttribute(key: 'customer', value: $value);
    }
}
