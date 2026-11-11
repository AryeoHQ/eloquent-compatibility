<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

final class HookLog implements RecordsHooks
{
    /** @var list<class-string> */
    public array $hooks = [];

    public function record(string $hook): void
    {
        $this->hooks[] = $hook;
    }
}
