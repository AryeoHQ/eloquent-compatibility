<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A model whose global scope reads application state, which a compatibility view must not freeze.
 */
final class TenantPlace extends Model
{
    use HasUuids;

    protected $table = 'places';

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn (Builder $query) => $query->where('city', config('app.tenant_city')));
    }
}
