<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

final class SyncListingToMls
{
    /** @var list<int> */
    public static array $synced = [];

    public function __invoke(Listing $listing): void
    {
        $this->handle($listing);
    }

    public function handle(Listing $listing): void
    {
        self::$synced[] = $listing->id;
    }
}
