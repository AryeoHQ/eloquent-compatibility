<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

final readonly class Declined
{
    public function __construct(public string $because) {}
}
