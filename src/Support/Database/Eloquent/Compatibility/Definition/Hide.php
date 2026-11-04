<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;

final readonly class Hide
{
    public function __construct(public Closure $scope, public string $because) {}
}
