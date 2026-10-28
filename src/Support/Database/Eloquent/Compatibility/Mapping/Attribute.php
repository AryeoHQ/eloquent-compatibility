<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

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
        public null|string $defaultsTo,
        public null|string $storedAs = null,
    ) {}

    public static function retained(string $column): self
    {
        return new self($column, true, null, null, null, [], null, null);
    }

    public static function mapped(string $column): self
    {
        return new self($column, false, null, null, null, [], null, null);
    }

    public function asType(string $type): self
    {
        return new self($this->column, $this->retained, $type, $this->alias, $this->readAs, $this->writesTo, $this->readOnlyBecause, $this->defaultsTo, $this->storedAs);
    }

    public function storedAs(string $alias, string $column): self
    {
        return new self(
            $this->column,
            $this->retained,
            $this->type,
            $alias,
            $alias.'.'.SqlFragments::identifier($column),
            [new TargetColumn($alias, $column, 'NEW.'.SqlFragments::identifier($this->column))],
            $this->readOnlyBecause,
            $this->defaultsTo,
            $column,
        );
    }

    public function readAs(string $alias, string $expression): self
    {
        return new self($this->column, $this->retained, $this->type, $alias, $expression, [], $this->readOnlyBecause, $this->defaultsTo);
    }

    public function writesTo(string $alias, string $column, string $expression): self
    {
        return new self(
            $this->column,
            $this->retained,
            $this->type,
            $this->alias,
            $this->readAs,
            [...$this->writesTo, new TargetColumn($alias, $column, $expression)],
            $this->readOnlyBecause,
            $this->defaultsTo,
            $this->storedAs,
        );
    }

    public function readOnlyBecause(string $reason): self
    {
        return new self($this->column, $this->retained, $this->type, $this->alias, $this->readAs, [], $reason, $this->defaultsTo, $this->storedAs);
    }

    public function defaultsTo(string $expression): self
    {
        return new self($this->column, $this->retained, $this->type, $this->alias, $this->readAs, $this->writesTo, $this->readOnlyBecause, $expression, $this->storedAs);
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
            $this->readOnlyBecause === null ? [new TargetColumn($previousAlias, $this->column, 'NEW.'.SqlFragments::identifier($this->column))] : [],
            $this->readOnlyBecause,
            $this->defaultsTo,
            $this->column,
        );
    }
}
