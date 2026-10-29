<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;

final class PendingMirroredWrite
{
    public bool $pending = true;

    public bool $claimed = false;

    public bool $statementSent = false;

    public bool $failed = false;

    public null|string $table = null;

    public null|string $statement = null;

    /** @var array<array-key, mixed> */
    public array $bindings = [];

    /** @var array<string, RecordSnapshot> */
    public array $snapshotsBefore = [];

    public int $transactionLevel = 0;

    public null|string $savepoint = null;

    public function __construct(
        public readonly Model $source,
        public readonly Operation $operation,
        public readonly null|Dispatcher $dispatcher,
        public readonly int $depth,
    ) {}
}
