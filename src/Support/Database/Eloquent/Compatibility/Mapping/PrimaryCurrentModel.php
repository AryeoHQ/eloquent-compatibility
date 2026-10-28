<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Database\Eloquent\Model;

final readonly class PrimaryCurrentModel
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $fillWhenCreatedFromPrevious
     */
    private function __construct(
        public string $model,
        public null|string $alias,
        public array $fillWhenCreatedFromPrevious,
    ) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function for(string $model): self
    {
        return new self(ModelClass::canonical($model), null, []);
    }

    public function aliasedAs(string $alias): self
    {
        return new self($this->model, $alias, $this->fillWhenCreatedFromPrevious);
    }

    /**
     * @param  array<string, string>  $values
     */
    public function fillWhenCreatedFromPrevious(array $values): self
    {
        return new self($this->model, $this->alias, $values);
    }
}
