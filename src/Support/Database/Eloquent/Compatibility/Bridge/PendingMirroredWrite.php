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

    public int $transactionLevel = 0;

    public null|string $savepoint = null;

    public function __construct(
        public readonly Model $source,
        public readonly Operation $operation,
    ) {}

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    public function expects(ParsedWrite $parsedWrite, array $bindings): bool
    {
        if ($parsedWrite->relation !== Str::afterLast($this->source->getTable(), '.') || ! $this->operation->accepts($parsedWrite->operation)) {
            return false;
        }

        if ($parsedWrite->operation === Operation::Insert) {
            return $parsedWrite->columns === array_keys($this->source->getAttributes());
        }

        $key = $this->source->getRawOriginal($this->source->getKeyName()) ?? $this->source->getKey();
        $bindings = array_values($bindings);

        return array_any(
            $parsedWrite->wherePlaceholders[$this->source->getKeyName()] ?? [],
            fn (int $placeholder) => array_key_exists($placeholder, $bindings) && $bindings[$placeholder] === $key,
        );
    }
}
