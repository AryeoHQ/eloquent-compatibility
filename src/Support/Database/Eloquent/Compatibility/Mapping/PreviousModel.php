<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Closure;
use Illuminate\Database\Eloquent\Model;

final readonly class PreviousModel
{
    /**
     * @param  null|class-string<Model>  $model
     * @param  array<string, Closure(Model): mixed>  $fillWhenCreatedFromCurrent
     */
    private function __construct(
        public string $view,
        public null|string $table,
        public null|string $model,
        public null|string $keyColumn,
        public null|string $alias,
        public null|string $primaryForeignKey,
        public array $fillWhenCreatedFromCurrent,
    ) {}

    public static function servedAsView(string $view): self
    {
        return new self($view, null, null, null, null, null, []);
    }

    public function retainedInTable(string $table): self
    {
        return new self($this->view, $table, $this->model, $this->keyColumn, $this->alias, $this->primaryForeignKey, $this->fillWhenCreatedFromCurrent);
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function forModel(string $model): self
    {
        return new self($this->view, $this->table, ModelClass::canonical($model), $this->keyColumn, $this->alias, $this->primaryForeignKey, $this->fillWhenCreatedFromCurrent);
    }

    public function keyedBy(string $column): self
    {
        return new self($this->view, $this->table, $this->model, $column, $this->alias, $this->primaryForeignKey, $this->fillWhenCreatedFromCurrent);
    }

    public function aliasedAs(string $alias): self
    {
        return new self($this->view, $this->table, $this->model, $this->keyColumn, $alias, $this->primaryForeignKey, $this->fillWhenCreatedFromCurrent);
    }

    public function primaryForeignKey(string $column): self
    {
        return new self($this->view, $this->table, $this->model, $this->keyColumn, $this->alias, $column, $this->fillWhenCreatedFromCurrent);
    }

    /**
     * Fills retained columns when a primary current model is created through its own model. Each closure receives that model.
     *
     * @param  array<string, Closure(Model): mixed>  $values
     */
    public function fillWhenCreatedFromCurrent(array $values): self
    {
        return new self($this->view, $this->table, $this->model, $this->keyColumn, $this->alias, $this->primaryForeignKey, $values);
    }
}
