<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

enum LifecycleStatus: string
{
    case Drafting = 'drafting';
    case Live = 'live';
    case Closed = 'closed';
}
