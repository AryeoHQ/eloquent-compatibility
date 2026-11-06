<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property null|string $legacy_type
 * @property string $asset_type
 * @property string $asset_id
 * @property null|string $title
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class PlaceAsset extends Model
{
    use HasUuids;

    protected $table = 'place_assets';

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function asset(): MorphTo
    {
        return $this->morphTo('asset', 'asset_type', 'asset_id');
    }
}
