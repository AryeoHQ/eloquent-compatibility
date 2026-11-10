<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $place_asset_id
 * @property null|string $body
 */
final class AssetNote extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'asset_notes';

    protected $guarded = [];
}
