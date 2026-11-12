<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $listing_id
 */
final class ListingOrder extends Model
{
    public $timestamps = false;

    protected $table = 'listing_orders';

    protected $guarded = [];
}
