<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Middleware;

use Closure;

final class Decline
{
    public function handle(object $trigger, Closure $next): null
    {
        return null;
    }
}
