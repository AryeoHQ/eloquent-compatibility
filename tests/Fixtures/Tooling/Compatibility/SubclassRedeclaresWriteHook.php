<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

final class SubclassRedeclaresWriteHook extends ValidModel
{
    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        return [];
    }
}
