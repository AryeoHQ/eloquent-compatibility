<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Shows jobs.published_at as a boolean. A timestamp cannot be recovered from a boolean, so filters on it go through
 * ListingBuilder's filter translation instead.
 *
 * @implements CastsAttributes<bool, bool>
 */
final class PublishedFlag implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): bool
    {
        return $value !== null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): null|string
    {
        return match (true) {
            ! $value => null,
            isset($attributes[$key]) && is_string($attributes[$key]) => $attributes[$key],
            default => $model->fromDateTime(now()),
        };
    }
}
