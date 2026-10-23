<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

final class InvalidCompatibilityMap extends CompatibilityException
{
    public static function because(string $map, string $problem): self
    {
        return new self("Compatibility map '{$map}' is declared wrongly: {$problem}.");
    }
}
