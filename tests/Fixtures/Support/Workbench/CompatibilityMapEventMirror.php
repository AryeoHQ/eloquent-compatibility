<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;

final readonly class CompatibilityMapEventMirror implements EventMirror
{
    /**
     * @param  list<ExcludedListener>  $excludedListeners
     */
    public function __construct(
        private CompatibilityMap $compatibilityMap,
        private array $excludedListeners = [],
    ) {}

    public function compatibilityMap(): CompatibilityMap
    {
        return $this->compatibilityMap;
    }

    public function excludedListeners(): array
    {
        return $this->excludedListeners;
    }
}
