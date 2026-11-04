<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $url
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class AssetLink extends Model
{
    use HasUuids;

    protected $table = 'asset_links';

    protected $guarded = [];

    protected static function booted(): void
    {
        Relation::morphMap(['place_asset_link' => self::class]);
    }
}
