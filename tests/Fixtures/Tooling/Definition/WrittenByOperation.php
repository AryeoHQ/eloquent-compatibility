<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Definition;

use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Tests\Fixtures\Tooling\Operations\WriteOrderCustomer;

final class WrittenByOperation
{
    public function attribute(): Attribute
    {
        return (new Attribute(map: 'orders', name: 'customer'))->from('customer_name')->writtenBy(WriteOrderCustomer::class);
    }
}
