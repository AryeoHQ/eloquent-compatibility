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
     */
    private function __construct(
        public string $model,
        public null|string $alias,
        public null|string $primaryForeignKey,
        public string $readableWhen,
        public array $fillWhenCreatedFromPrevious,
        public null|Closure $createdWhen,
        public null|string $designationColumn,
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
        return new self($this->model, $alias, $this->primaryForeignKey, $this->readableWhen, $this->fillWhenCreatedFromPrevious, $this->createdWhen, $this->designationColumn);
    }

    public function primaryForeignKey(string $column): self
    {
        return new self($this->model, $this->alias, $column, $this->readableWhen, $this->fillWhenCreatedFromPrevious, $this->createdWhen, $this->designationColumn);
    }

    public function readableWhen(string $condition): self
    {
        return new self($this->model, $this->alias, $this->primaryForeignKey, $condition, $this->fillWhenCreatedFromPrevious, $this->createdWhen, $this->designationColumn);
    }

    /**
     * Fills columns when a write through the previous model creates this row. Each closure receives the previous model's
     * raw attributes; a closure that returns null leaves its column to the database's default.
     *
     * @param  array<string, Closure(array<string, mixed>): mixed>  $values
     */
    public function fillWhenCreatedFromPrevious(array $values): self
    {
        return new self($this->model, $this->alias, $this->primaryForeignKey, $this->readableWhen, $values, $this->createdWhen, $this->designationColumn);
    }

    /**
     * Decides whether a write through the previous model creates this dependent row when the record has none.
     * Without it, a write creates the row when any attribute stored in it is not null.
     *
     * @param  Closure(array<string, mixed>): bool  $condition  receives the previous model's raw attributes after the write
     */
    public function createdWhen(Closure $condition): self
    {
        return new self($this->model, $this->alias, $this->primaryForeignKey, $this->readableWhen, $this->fillWhenCreatedFromPrevious, $condition, $this->designationColumn);
    }

    public function designatedBy(string $column): self
    {
        return new self($this->model, $this->alias, $this->primaryForeignKey, $this->readableWhen, $this->fillWhenCreatedFromPrevious, $this->createdWhen, $column);
    }
}
