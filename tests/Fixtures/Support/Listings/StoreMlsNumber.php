<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\FileMetadata;

/**
 * @implements WritesAttribute<Job>
 */
final class StoreMlsNumber implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        FakeMediaLibrary::bound()->put('mls-'.$successors->root()->getKey(), new FileMetadata(is_string($value) ? $value : null));
    }
}
