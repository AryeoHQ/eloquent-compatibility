<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

/**
 * @internal
 */
final class InvalidCompatibilityMap extends CompatibilityException
{
    public static function because(string $map, string $problem): self
    {
        return new self("Compatibility map '{$map}' is declared wrongly: {$problem}.");
    }

    public static function cannot(string $map, string $operation, string $because): self
    {
        return new self("Compatibility map '{$map}' cannot {$operation}: {$because}.");
    }
}
