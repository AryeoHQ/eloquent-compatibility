<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;

final readonly class MirroredMap
{
    public function __construct(
        public ResolvedMap $resolvedMap,
        public EventMirror $mirror,
        public KeyTranslator $keyTranslator,
    ) {}
}
