<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

abstract class SubclassRedeclaresBuilder extends ValidModel
{
    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        return $this->getAttributes();
    }
}
