<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

final class Sequence
{
    public bool $forwarding = false;

    public function __construct(public readonly string $start, public string $last, public readonly null|LegacyRecord $record) {}
}
