<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\QueryableCast;

/**
 * @implements CastsAttributes<null|string, null|float|int|string>
 */
final class CentsAsDollars implements CastsAttributes, QueryableCast
{
    public function get(Model $model, string $key, mixed $value, array $attributes): null|string
    {
        return $value === null ? null : number_format(((int) $value) / 100, 2, '.', '');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): null|int
    {
        return $value === null ? null : (int) round(((float) $value) * 100);
    }

    public function preservesOrder(): bool
    {
        return true;
    }

    public function isProportional(): bool
    {
        return true;
    }
}
