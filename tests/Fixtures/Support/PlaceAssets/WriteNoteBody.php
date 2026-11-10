<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<PlaceAsset>
 */
final class WriteNoteBody implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $body = is_string($value) ? $value : null;
        ($body === null ? $successors->related(AssetNote::class) : $successors->relatedOrNew(AssetNote::class))?->setAttribute(
            key: 'body',
            value: $body,
        );
    }
}
