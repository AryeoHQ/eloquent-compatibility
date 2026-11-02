<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $configured_job_id
 * @property null|array<string, mixed> $options
 */
final class ConfiguredSite extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'configured_sites';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}
