<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;

final readonly class Guard
{
    public function __construct(public string $name, public Closure $when, public string $because, public string $instead) {}
}
