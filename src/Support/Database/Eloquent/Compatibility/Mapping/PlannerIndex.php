<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

final readonly class PlannerIndex
{
    /**
     * @param  list<array{attribute: string, descending: bool}>  $columns
     * @param  list<array{filter: PlannerIndexFilter, attribute: string}>  $filters
     */
    private function __construct(
        public string $name,
        public array $columns,
        public array $filters,
    ) {}

    public static function named(string $name): self
    {
        return new self($name, [], []);
    }

    public function on(string $attribute, bool $descending = false): self
    {
        return new self($this->name, [...$this->columns, ['attribute' => $attribute, 'descending' => $descending]], $this->filters);
    }

    public function whereNull(string $attribute): self
    {
        return $this->filtered(PlannerIndexFilter::WhereNull, $attribute);
    }

    public function whereNotNull(string $attribute): self
    {
        return $this->filtered(PlannerIndexFilter::WhereNotNull, $attribute);
    }

    private function filtered(PlannerIndexFilter $filter, string $attribute): self
    {
        return new self($this->name, $this->columns, [...$this->filters, ['filter' => $filter, 'attribute' => $attribute]]);
    }
}
