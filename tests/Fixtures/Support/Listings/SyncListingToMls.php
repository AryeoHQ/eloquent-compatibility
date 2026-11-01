<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

/**
 * A listener that must run only for edits made through the Listing itself.
 */
final class SyncListingToMls
{
    /** @var list<int> */
    public static array $synced = [];

    public function handle(Listing $listing): void
    {
        self::$synced[] = $listing->id;
    }
}
