<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Database\Eloquent\Model;

final readonly class DependentCurrentModel
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $fillWhenCreatedFromPrevious
     */
    private function __construct(
        public string $model,
        public null|string $alias,
        public null|string $primaryForeignKey,
        public string $readableWhen,
        public array $fillWhenCreatedFromPrevious,
        public null|string $createdWhen,
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
     * @param  array<string, string>  $values
     */
    public function fillWhenCreatedFromPrevious(array $values): self
    {
        return new self($this->model, $this->alias, $this->primaryForeignKey, $this->readableWhen, $values, $this->createdWhen, $this->designationColumn);
    }

    public function createdWhen(string $condition): self
    {
        return new self($this->model, $this->alias, $this->primaryForeignKey, $this->readableWhen, $this->fillWhenCreatedFromPrevious, $condition, $this->designationColumn);
    }

    public function designatedBy(string $column): self
    {
        return new self($this->model, $this->alias, $this->primaryForeignKey, $this->readableWhen, $this->fillWhenCreatedFromPrevious, $this->createdWhen, $column);
    }
}
