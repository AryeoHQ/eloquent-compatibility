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

    /**
     * @param  array<int, mixed>  $models
     */
    public function every(string $event, array $models): void
    {
        foreach ($models as $model) {
            if ($model instanceof Listing) {
                $this->handle($model);
            }
        }
    }
}
