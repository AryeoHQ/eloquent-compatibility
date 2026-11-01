<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

enum ListingStatus: string
{
    case Draft = 'Draft';
    case Active = 'Active';
    case Sold = 'Sold';
}
