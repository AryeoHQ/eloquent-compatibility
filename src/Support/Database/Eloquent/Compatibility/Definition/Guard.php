<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;

final readonly class Guard
{
    public function __construct(public string $name, public Closure $when, public string $because, public string $instead) {}

    public function refuses(Model $after, Model $before): bool
    {
        return ($this->when)($after, $before) === true;
    }
}
