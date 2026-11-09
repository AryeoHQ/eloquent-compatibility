<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\FileMetadata;

final class StoreAssetCaption implements WritesAttribute
{
    /**
     * @param  Successors<PlaceAsset>  $successors
     */
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $asset = $successors->root();
        FakeMediaLibrary::bound()->put('caption-'.$asset->getKey(), new FileMetadata(is_string($value) ? $value : null));
        FakeMediaLibrary::bound()->put('caption-title-'.$asset->getKey(), new FileMetadata($asset->title));
    }
}
