<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

trait ReplacementInsertHook
{
    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        return $this->getAttributes();
    }
}
