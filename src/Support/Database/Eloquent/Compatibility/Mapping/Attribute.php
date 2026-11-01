<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Closure;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class Attribute
{
    /**
     * @param  list<TargetColumn>  $writesTo
     */
    private function __construct(
        public string $column,
        public bool $retained,
        public null|string $type,
        public null|string $alias,
        public null|string $readAs,
        public array $writesTo,
        public null|string $readOnlyBecause,
        public null|string $storedAs = null,
    ) {}

    public static function retained(string $column): self
    {
        return new self($column, true, null, null, null, [], null);
    }

    public static function mapped(string $column): self
    {
        return new self($column, false, null, null, null, [], null);
    }

    public function asType(string $type): self
    {
        return new self($this->column, $this->retained, $type, $this->alias, $this->readAs, $this->writesTo, $this->readOnlyBecause, $this->storedAs);
    }

    /**
     * Reads the attribute from one column of a current model, and writes it back there unchanged.
     */
    public function storedAs(string $alias, string $column): self
    {
        return new self(
            $this->column,
            $this->retained,
            $this->type,
            $alias,
            $alias.'.'.SqlFragments::identifier($column),
            [TargetColumn::copy($alias, $column, $this->column)],
            $this->readOnlyBecause,
            $column,
        );
    }

    /**
     * Reads the attribute through a SQL expression over one current model's row. The compatibility view runs it.
     */
    public function readAs(string $alias, string $expression): self
    {
        return new self($this->column, $this->retained, $this->type, $alias, $expression, [], $this->readOnlyBecause);
    }

    /**
     * Writes a column of a current model from the previous model's attributes. The write router calls $value in PHP.
     *
     * @param  Closure(array<string, mixed>): mixed  $value  receives the previous model's raw attributes after the write
     */
    public function writesTo(string $alias, string $column, Closure $value): self
    {
        return new self(
            $this->column,
            $this->retained,
            $this->type,
            $this->alias,
            $this->readAs,
            [...$this->writesTo, new TargetColumn($alias, $column, $value)],
            $this->readOnlyBecause,
            $this->storedAs,
        );
    }

    public function readOnlyBecause(string $reason): self
    {
        return new self($this->column, $this->retained, $this->type, $this->alias, $this->readAs, [], $reason, $this->storedAs);
    }

    public function resolve(string $previousAlias): self
    {
        if (! $this->retained) {
            return $this;
        }

        return new self(
            $this->column,
            true,
            null,
            $previousAlias,
            $previousAlias.'.'.SqlFragments::identifier($this->column),
            $this->readOnlyBecause === null ? [TargetColumn::copy($previousAlias, $this->column, $this->column)] : [],
            $this->readOnlyBecause,
            $this->column,
        );
    }
}
