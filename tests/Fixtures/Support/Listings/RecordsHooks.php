<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

interface RecordsHooks
{
    /**
     * @param  class-string  $hook
     */
    public function record(string $hook): void;
}
