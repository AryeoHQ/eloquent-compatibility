<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<File>
 */
final class WriteLowercaseFileMimeType implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $file = $successors->root();
        $mimeType = is_string($value) ? strtolower($value) : null;
        $file->metadata()->mimeType = $mimeType;
        $file->mime_type = $mimeType;
    }
}
