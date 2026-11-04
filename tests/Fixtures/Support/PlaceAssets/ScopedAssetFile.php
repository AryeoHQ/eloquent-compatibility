<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ScopedAssetFile extends Model
{
    use HasUuids;

    protected $table = 'scoped_asset_files';

    protected static function booted(): void
    {
        self::addGlobalScope('visible', fn (Builder $files) => $files->whereNull('hidden_at'));
    }
}
