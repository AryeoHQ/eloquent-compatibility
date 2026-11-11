<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $name
 * @property null|array<string, mixed> $settings
 */
final class ConfiguredJob extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'configured_jobs';

    protected $guarded = [];

    /** @return HasOne<ConfiguredSite, $this> */
    public function site(): HasOne
    {
        return $this->hasOne(ConfiguredSite::class);
    }

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
