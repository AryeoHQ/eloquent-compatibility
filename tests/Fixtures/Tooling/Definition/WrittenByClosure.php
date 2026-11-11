<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Definition;

use Support\Database\Eloquent\Compatibility\Definition\Attribute;

final class WrittenByClosure
{
    public function attribute(): Attribute
    {
        return (new Attribute(map: 'orders', name: 'customer'))->from('customer_name')->writtenBy(fn () => null);
    }
}
