<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

final class ArchiveLegacyFile
{
    /** @var list<int> */
    public static array $archived = [];

    public function __invoke(LegacyFileUpdated $event): void
    {
        self::$archived[] = $event->legacyFile->id;
    }
}
