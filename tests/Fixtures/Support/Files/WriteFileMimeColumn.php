<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

final class WriteFileMimeColumn implements WritesAttribute
{
    /**
     * @param  Successors<File>  $successors
     */
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->root()->mime_type = is_string($value) ? $value : '';
    }
}
