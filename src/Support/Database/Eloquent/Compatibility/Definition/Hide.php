<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;

/**
 * @internal
 */
final readonly class Hide
{
    public function __construct(public Closure $scope, public string $because) {}
}
