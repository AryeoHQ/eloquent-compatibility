<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

/**
 * @internal
 */
final class CompatibilityQueryRefused extends CompatibilityException
{
    public static function untranslatable(string $model, string $attribute, string $operation, string $because): self
    {
        return new self("{$model}::\${$attribute} cannot take {$operation}. Why: {$because} Instead: filter or sort on the stored column through the successor model.");
    }
}
