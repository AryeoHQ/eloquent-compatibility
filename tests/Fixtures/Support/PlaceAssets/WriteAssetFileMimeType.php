<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<PlaceAsset>
 */
final class WriteAssetFileMimeType implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $file = $successors->relatedOrNew(AssetFile::class);
        $mimeType = is_string($value) ? $value : null;
        $file->metadata()->mimeType = $mimeType;
        $file->mime_type = $mimeType;
    }
}
