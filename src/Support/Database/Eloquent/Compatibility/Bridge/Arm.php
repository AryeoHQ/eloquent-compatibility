<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class Arm
{
    public bool $pending = true;

    public bool $started = false;

    /** @var array<string, array{record: Record, row: null|array<string, mixed>}> */
    public array $before = [];

    public function __construct(
        public readonly Model $model,
        public readonly string $operation,
        public readonly int $level,
        public readonly null|string $savepoint,
    ) {}

    public function expects(Statement $statement): bool
    {
        if ($statement->relation !== strtolower(Str::afterLast($this->model->getTable(), '.'))) {
            return false;
        }

        return $statement->operation === $this->operation
            || ($this->operation === 'delete' && $statement->operation === 'update');
    }
}
