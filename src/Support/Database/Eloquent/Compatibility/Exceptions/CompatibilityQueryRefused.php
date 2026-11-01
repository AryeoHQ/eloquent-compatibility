<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

/**
 * A query on a converted attribute that the routing builder cannot translate to the stored column.
 */
final class CompatibilityQueryRefused extends CompatibilityException
{
    public static function untranslatable(string $model, string $attribute, string $operation, string $because): self
    {
        return new self("{$model}::\${$attribute} cannot take {$operation}. Why: {$because} Instead: filter through a named builder method, or on the stored column of the current model.");
    }
}
