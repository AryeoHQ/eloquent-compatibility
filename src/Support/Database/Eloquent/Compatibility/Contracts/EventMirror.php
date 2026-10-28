<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;

interface EventMirror
{
    public function compatibilityMap(): CompatibilityMap;

    /** @return list<ExcludedListener> */
    public function excludedListeners(): array;
}
