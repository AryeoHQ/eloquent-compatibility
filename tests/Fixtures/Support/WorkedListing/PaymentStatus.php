<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Illuminate\Support\Collection;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';

    /**
     * @param  Collection<int, ListingOrder>  $orders
     */
    public static function fromOrders(Collection $orders): self
    {
        return $orders->isEmpty() ? self::Unpaid : self::Paid;
    }
}
