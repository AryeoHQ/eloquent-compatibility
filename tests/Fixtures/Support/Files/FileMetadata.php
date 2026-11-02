<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

final class FileMetadata
{
    public function __construct(public null|string $mimeType = null) {}
}
