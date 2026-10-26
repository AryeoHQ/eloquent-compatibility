<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PendingMirroredWrite
{
    public bool $pending = true;

    public bool $statementSent = false;

    /** @var array<string, RecordSnapshot> */
    public array $snapshotsBefore = [];

    public function __construct(
        public readonly Model $source,
        public readonly Operation $operation,
        public readonly int $transactionLevel,
        public readonly null|string $savepoint,
    ) {}

    public function expects(ParsedWrite $parsedWrite): bool
    {
        if ($parsedWrite->relation !== Str::afterLast($this->source->getTable(), '.')) {
            return false;
        }

        return $this->operation->accepts($parsedWrite->operation);
    }
}
