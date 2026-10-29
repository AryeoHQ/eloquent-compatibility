<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

final readonly class PlannerIndex
{
    /**
     * @param  array<string, bool>  $columns
     */
    private function __construct(
        public string $name,
        public array $columns,
        public null|string $whereNull,
        public null|string $whereNotNull,
    ) {}

    public static function named(string $name): self
    {
        return new self($name, [], null, null);
    }

    public function on(string $attribute, bool $descending = false): self
    {
        return new self($this->name, [...$this->columns, $attribute => $descending], $this->whereNull, $this->whereNotNull);
    }

    public function whereNull(string $attribute): self
    {
        return new self($this->name, $this->columns, $attribute, null);
    }

    public function whereNotNull(string $attribute): self
    {
        return new self($this->name, $this->columns, null, $attribute);
    }
}
