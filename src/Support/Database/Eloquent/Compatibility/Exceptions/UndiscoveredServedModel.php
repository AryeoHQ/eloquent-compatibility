<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

/**
 * @internal
 */
final class UndiscoveredServedModel extends CompatibilityException
{
    public static function named(string $model): self
    {
        return new self("{$model} serves compatibility, but discovery did not find it. Why: discovery reads the composer classmap outside the vendor directory, and in production the cache that php artisan optimize writes. Instead: run composer dump-autoload --optimize, then php artisan optimize.");
    }

    public static function withoutCache(string $path): self
    {
        return new self("Compatibility cannot list the served models: {$path} does not exist. Why: outside local and testing, the package reads the served models from that cache instead of scanning the classmap. Instead: run php artisan optimize.");
    }
}
