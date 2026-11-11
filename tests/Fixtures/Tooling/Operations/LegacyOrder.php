<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Operations;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Targets;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

final class LegacyOrder extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'legacy_orders';

    public static function compatibility(Compatibility $order): Compatibility
    {
        return $order
            ->retainedIn('legacy_orders_retained')
            ->servedFrom(root: Order::class, key: 'order_id', successor: fn (Successor $order) => $order
                ->attribute(
                    name: 'shipment',
                    definition: fn (Attribute $attribute) => $attribute->readAs('NULL')->writtenBy(WriteOrderShipment::class),
                )
                ->attribute(
                    name: 'customer',
                    definition: fn (Attribute $attribute) => $attribute->readAs('NULL')->writtenBy(WriteOrderCustomer::class),
                )
                ->attribute(
                    name: 'total',
                    definition: fn (Attribute $attribute) => $attribute->readAs('NULL')->writtenBy(WriteOrderTotal::class),
                )
                ->attribute(
                    name: 'note',
                    definition: fn (Attribute $attribute) => $attribute->readAs('NULL')->writtenBy(WriteOrderNote::class),
                )
                ->related(relation: fn (Order $order) => $order->customer())
                ->relatedMorph(
                    relation: fn (Order $order) => $order->receipt(),
                    choose: fn (LegacyOrder $order): string => Receipt::class,
                    targets: fn (Targets $targets) => $targets->as(target: Receipt::class, successor: fn (Successor $receipt) => $receipt),
                ))
            ->successorsInitializedBy(InitializeOrder::class)
            ->legacyInitializedBy(InitializeLegacyOrder::class);
    }
}
