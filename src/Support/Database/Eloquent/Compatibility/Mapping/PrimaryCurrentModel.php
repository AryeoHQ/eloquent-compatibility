<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Closure;
use Illuminate\Database\Eloquent\Model;

final readonly class PrimaryCurrentModel
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $fillWhenCreatedFromPrevious
     * @param  array<class-string, array<string, Closure>>  $triggerArguments
     * @param  list<PlannerIndex>  $indexes
     */
    private function __construct(
        public string $model,
        public null|string $alias,
        public array $fillWhenCreatedFromPrevious,
        public array $triggerArguments,
        public array $indexes,
    ) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function for(string $model): self
    {
        return new self(ModelClass::canonical($model), null, [], [], []);
    }

    public function aliasedAs(string $alias): self
    {
        return new self($this->model, $alias, $this->fillWhenCreatedFromPrevious, $this->triggerArguments, $this->indexes);
    }

    /**
     * @param  array<string, string>  $values
     */
    public function fillWhenCreatedFromPrevious(array $values): self
    {
        return new self($this->model, $this->alias, $values, $this->triggerArguments, $this->indexes);
    }

    /**
     * @param  class-string  $trigger
     * @param  array<string, Closure>  $arguments
     */
    public function triggerArguments(string $trigger, array $arguments): self
    {
        return new self($this->model, $this->alias, $this->fillWhenCreatedFromPrevious, [...$this->triggerArguments, $trigger => $arguments], $this->indexes);
    }

    public function indexedBy(PlannerIndex ...$indexes): self
    {
        return new self($this->model, $this->alias, $this->fillWhenCreatedFromPrevious, $this->triggerArguments, [...$this->indexes, ...array_values($indexes)]);
    }
}
