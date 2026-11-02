<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class TrashableAssetFile extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'trashable_asset_files';
}
