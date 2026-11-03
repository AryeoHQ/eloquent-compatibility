<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class TrashablePlaceAsset extends Model
{
    use HasUuids;
    use SoftDeletes;

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
