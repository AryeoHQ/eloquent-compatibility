<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Origin;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<File>
 */
final class WriteFileMimeType implements WritesAttribute
{
    /** @var list<Origin> */
    public static array $origins = [];

    public function write(Successors $successors, mixed $value, Change $change): void
    {
        self::$origins[] = $change->origin;
        $file = $successors->root();
        $mimeType = is_string($value) ? $value : null;
        $file->metadata()->mimeType = $mimeType;
        $file->mime_type = $mimeType;
    }
}
