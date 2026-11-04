<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Closure;
use Illuminate\Support\ServiceProvider;

final class WritesWhileBooting extends ServiceProvider
{
    public static null|Closure $writes = null;

    public function boot(): void
    {
        if (self::$writes !== null) {
            (self::$writes)();
        }
    }
}
