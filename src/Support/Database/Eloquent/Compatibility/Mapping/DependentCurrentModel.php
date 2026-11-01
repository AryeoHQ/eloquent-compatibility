<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Closure;
use Illuminate\Database\Eloquent\Model;

final readonly class DependentCurrentModel
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, Closure(array<string, mixed>): mixed>  $fillWhenCreatedFromPrevious
     * @param  list<Closure(Model, array<string, mixed>): void>  $creating
     */
    private function __construct(
        public string $model,
        public null|string $alias,
        public null|string $primaryForeignKey,
        public string $readableWhen,
        public array $fillWhenCreatedFromPrevious,
        public null|Closure $createdWhen,
        public null|string $designationColumn,
        public null|string $orderedBy = null,
        public array $creating = [],
    ) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function for(string $model): self
    {
        return new self(ModelClass::canonical($model), null, null, 'true', [], null, null);
    }

    public function aliasedAs(string $alias): self
    {
        return $this->with(alias: $alias);
    }

    public function primaryForeignKey(string $column): self
    {
        return $this->with(primaryForeignKey: $column);
    }

    public function readableWhen(string $condition): self
    {
        return $this->with(readableWhen: $condition);
    }

    /**
     * Fills columns when a write through the previous model creates this row. Each closure receives the previous model's
     * raw attributes; a closure that returns null leaves its column to the database's default.
     *
     * @param  array<string, Closure(array<string, mixed>): mixed>  $values
     */
    public function fillWhenCreatedFromPrevious(array $values): self
    {
        return $this->with(fillWhenCreatedFromPrevious: $values);
    }

    /**
     * Decides whether a write through the previous model creates this dependent row when the record has none.
     * Without it, a write creates the row when any attribute stored in it is not null.
     *
     * @param  Closure(array<string, mixed>): bool  $condition  receives the previous model's raw attributes after the write
     */
    public function createdWhen(Closure $condition): self
    {
        return $this->with(createdWhen: $condition);
    }

    public function designatedBy(string $column): self
    {
        return $this->with(designationColumn: $column);
    }

    /**
     * Orders the readable rows, as SQL over this alias, so the previous model reads the first. Without it, the key orders them.
     */
    public function orderedBy(string $order): self
    {
        return $this->with(orderedBy: $order);
    }

    /**
     * Runs before a write through the previous model saves a new row of this model. The hook receives the unsaved
     * model and the previous model's raw attributes, and may set more attributes on the model.
     *
     * @param  Closure(Model, array<string, mixed>): void  $hook
     */
    public function onCreate(Closure $hook): self
    {
        return $this->with(creating: [...$this->creating, $hook]);
    }

    /**
     * @param  null|array<string, Closure(array<string, mixed>): mixed>  $fillWhenCreatedFromPrevious
     * @param  null|list<Closure(Model, array<string, mixed>): void>  $creating
     */
    private function with(
        null|string $alias = null,
        null|string $primaryForeignKey = null,
        null|string $readableWhen = null,
        null|array $fillWhenCreatedFromPrevious = null,
        null|Closure $createdWhen = null,
        null|string $designationColumn = null,
        null|string $orderedBy = null,
        null|array $creating = null,
    ): self {
        return new self(
            $this->model,
            $alias ?? $this->alias,
            $primaryForeignKey ?? $this->primaryForeignKey,
            $readableWhen ?? $this->readableWhen,
            $fillWhenCreatedFromPrevious ?? $this->fillWhenCreatedFromPrevious,
            $createdWhen ?? $this->createdWhen,
            $designationColumn ?? $this->designationColumn,
            $orderedBy ?? $this->orderedBy,
            $creating ?? $this->creating,
        );
    }
}
