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
     * @param  null|Closure(Model): array<string, mixed>  $fillingWhenCreatedFromCurrent
     * @param  null|class-string<Model>  $retainedModel
     */
    private function __construct(
        public string $view,
        public null|string $table,
        public null|string $model,
        public null|string $keyColumn,
        public null|string $alias,
        public null|string $primaryForeignKey,
        public array $fillWhenCreatedFromCurrent,
        public null|Closure $fillingWhenCreatedFromCurrent = null,
        public null|string $retainedModel = null,
    ) {}

    public static function servedAsView(string $view): self
    {
        return new self($view, null, null, null, null, null, []);
    }

    public function retainedInTable(string $table): self
    {
        return $this->with(table: $table);
    }

    /**
     * Names a model over the retained table. A refused state checked on the retained table by a predicate loads its rows through it.
     *
     * @param  class-string<Model>  $model
     */
    public function retainedAs(string $model): self
    {
        return $this->with(retainedModel: ModelClass::canonical($model));
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function forModel(string $model): self
    {
        return $this->with(model: ModelClass::canonical($model));
    }

    public function keyedBy(string $column): self
    {
        return $this->with(keyColumn: $column);
    }

    public function aliasedAs(string $alias): self
    {
        return $this->with(alias: $alias);
    }

    public function primaryForeignKey(string $column): self
    {
        return $this->with(primaryForeignKey: $column);
    }

    /**
     * Fills retained columns when a primary current model is created through its own model. Each closure receives that model.
     *
     * @param  array<string, Closure(Model): mixed>  $values
     */
    public function fillWhenCreatedFromCurrent(array $values): self
    {
        return $this->with(fillWhenCreatedFromCurrent: $values);
    }

    /**
     * Fills retained columns when a primary current model is created through its own model, from one closure that
     * receives that model and returns the columns. Its columns are known only when it runs, so the compile-time check
     * that every NOT NULL retained column is filled leaves this path out.
     *
     * @param  Closure(Model): array<string, mixed>  $fill
     */
    public function fillWhenCreatedFromCurrentUsing(Closure $fill): self
    {
        return $this->with(fillingWhenCreatedFromCurrent: $fill);
    }

    /**
     * @param  null|array<string, Closure(Model): mixed>  $fillWhenCreatedFromCurrent
     */
    private function with(
        null|string $table = null,
        null|string $model = null,
        null|string $keyColumn = null,
        null|string $alias = null,
        null|string $primaryForeignKey = null,
        null|array $fillWhenCreatedFromCurrent = null,
        null|Closure $fillingWhenCreatedFromCurrent = null,
        null|string $retainedModel = null,
    ): self {
        return new self(
            $this->view,
            $table ?? $this->table,
            $model ?? $this->model,
            $keyColumn ?? $this->keyColumn,
            $alias ?? $this->alias,
            $primaryForeignKey ?? $this->primaryForeignKey,
            $fillWhenCreatedFromCurrent ?? $this->fillWhenCreatedFromCurrent,
            $fillingWhenCreatedFromCurrent ?? $this->fillingWhenCreatedFromCurrent,
            $retainedModel ?? $this->retainedModel,
        );
    }
}
