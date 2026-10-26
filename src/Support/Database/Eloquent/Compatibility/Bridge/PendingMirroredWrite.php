<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PendingMirroredWrite
{
    public bool $pending = true;

    public bool $started = false;

    /** @var array<string, RecordSnapshot> */
    public array $before = [];

    public function __construct(
        public readonly Model $model,
        public readonly Operation $operation,
        public readonly int $level,
        public readonly null|string $savepoint,
    ) {}

    public function expects(ParsedWrite $parsedWrite): bool
    {
        if ($parsedWrite->relation !== Str::afterLast($this->model->getTable(), '.')) {
            return false;
        }

        return $this->operation->accepts($parsedWrite->operation);
    }
}
