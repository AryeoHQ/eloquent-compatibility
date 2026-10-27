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

    /** @var null|list<string> */
    public null|array $incrementColumns = null;

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

        if ($parsedWrite->operation === Operation::Update && ! $this->setsItsOwnChange($parsedWrite->columns ?? [])) {
            return false;
        }

        $key = $this->source->getRawOriginal($this->source->getKeyName()) ?? $this->source->getKey();
        $bindings = array_values($bindings);

        return array_any(
            $parsedWrite->wherePlaceholders[$this->source->getKeyName()] ?? [],
            fn (int $placeholder) => array_key_exists($placeholder, $bindings) && $bindings[$placeholder] === $key,
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private function setsItsOwnChange(array $columns): bool
    {
        $updatedAt = $this->source->usesTimestamps() ? $this->source->getUpdatedAtColumn() : null;
        $touched = $updatedAt === null ? [] : [$updatedAt];
        $set = array_values(array_unique([...$columns, ...$touched]));
        $dirty = array_values(array_unique([...array_map(strval(...), array_keys($this->source->getDirty())), ...$touched]));
        sort($set);
        sort($dirty);

        if ($this->operation === Operation::Update && $this->incrementColumns !== null) {
            $sent = array_values(array_unique($columns));
            $incremented = array_values(array_unique([...$this->incrementColumns, ...$touched]));
            sort($sent);
            sort($incremented);

            return $sent === $incremented;
        }

        if ($this->operation === Operation::Update) {
            return $columns !== [] && $set === $dirty;
        }

        return method_exists($this->source, 'getDeletedAtColumn') && in_array($this->source->getDeletedAtColumn(), $columns, true) && array_diff($set, $dirty) === [];
    }
}
