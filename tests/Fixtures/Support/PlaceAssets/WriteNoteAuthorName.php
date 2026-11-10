<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<PlaceAsset>
 */
final class WriteNoteAuthorName implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $name = is_string($value) ? $value : null;
        ($name === null ? $successors->related(NoteAuthor::class) : $successors->relatedOrNew(NoteAuthor::class))?->setAttribute(
            key: 'name',
            value: $name,
        );
    }
}
