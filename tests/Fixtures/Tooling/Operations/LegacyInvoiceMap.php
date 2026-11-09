<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Operations;

use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;

final class LegacyInvoiceMap
{
    public static function define(Compatibility $invoice): Compatibility
    {
        return $invoice
            ->retainedIn('legacy_invoices_retained')
            ->servedFrom(root: Order::class, key: 'order_id', successor: fn (Successor $order) => $order
                ->attribute(
                    name: 'customer',
                    definition: fn (Attribute $attribute) => $attribute->readAs('NULL')->writtenBy(WriteInvoiceCustomer::class),
                )
                ->attribute(
                    name: 'note',
                    definition: fn (Attribute $attribute) => $attribute->readAs('NULL')->writtenBy(WriteOrderNote::class),
                )
                ->related(relation: fn (Order $order) => $order->invoice()));
    }
}
