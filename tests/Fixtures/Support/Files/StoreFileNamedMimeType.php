<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

final class StoreFileNamedMimeType implements WritesAttribute
{
    /**
     * @param  Successors<File>  $successors
     */
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $file = $successors->root();
        FakeMediaLibrary::bound()->put((string) $file->filename, new FileMetadata(is_string($value) ? $value : null));
    }
}
