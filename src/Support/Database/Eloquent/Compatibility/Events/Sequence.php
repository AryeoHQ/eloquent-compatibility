<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

final class Sequence
{
    /** @var array<array-key, true> */
    public array $forwarding = [];

    public bool $vetoed = false;

    /**
     * @param  null|array{int, string}  $frame
     */
    public function __construct(public readonly string $start, public string $last, public readonly null|LegacyRecord $record, public readonly null|array $frame) {}
}
