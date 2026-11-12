<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $job_id
 * @property null|bool $downloads_enabled_fallback
 */
final class ListingCompatibilityState extends Model
{
    use HasUuids;

    protected $table = 'listing_compatibility_states';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['downloads_enabled_fallback' => 'boolean'];
    }
}
