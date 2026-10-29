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
        public null|string $timeZone = null,
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
        return new self($this->column, $this->retained, $type, $this->alias, $this->readAs, $this->writesTo, $this->readOnlyBecause, $this->defaultsTo, $this->storedAs, $this->timeZone);
    }

    public function storedAs(string $alias, string $column): self
    {
        return new self(
            $this->column,
            $this->retained,
            $this->type,
            $alias,
            $this->storedRead($alias, $column, $this->timeZone),
            [$this->storedWrite($alias, $column, $this->timeZone)],
            $this->readOnlyBecause,
            $this->defaultsTo,
            $column,
            $this->timeZone,
        );
    }

    public function inTimeZone(string $zone): self
    {
        if ($this->storedAs === null) {
            return new self($this->column, $this->retained, $this->type, $this->alias, $this->readAs, $this->writesTo, $this->readOnlyBecause, $this->defaultsTo, $this->storedAs, $zone);
        }

        $alias = (string) $this->alias;
        $column = $this->storedAs;
        $stored = get_object_vars($this->storedWrite($alias, $column, $this->timeZone));

        return new self(
            $this->column,
            $this->retained,
            $this->type,
            $alias,
            $this->storedRead($alias, $column, $zone),
            array_map(fn (TargetColumn $targetColumn) => get_object_vars($targetColumn) === $stored ? $this->storedWrite($alias, $column, $zone) : $targetColumn, $this->writesTo),
            $this->readOnlyBecause,
            $this->defaultsTo,
            $column,
            $zone,
        );
    }

    public function readAs(string $alias, string $expression): self
    {
        return new self($this->column, $this->retained, $this->type, $alias, $expression, [], $this->readOnlyBecause, $this->defaultsTo, null, $this->timeZone);
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
            $this->timeZone,
        );
    }

    public function readOnlyBecause(string $reason): self
    {
        return new self($this->column, $this->retained, $this->type, $this->alias, $this->readAs, [], $reason, $this->defaultsTo, $this->storedAs, $this->timeZone);
    }

    public function defaultsTo(string $expression): self
    {
        return new self($this->column, $this->retained, $this->type, $this->alias, $this->readAs, $this->writesTo, $this->readOnlyBecause, $expression, $this->storedAs, $this->timeZone);
    }

    public function plannerExpression(): null|string
    {
        if ($this->retained || $this->storedAs === null || $this->type === null) {
            return null;
        }

        return 'CAST(('.$this->inZone(SqlFragments::identifier($this->storedAs), $this->timeZone).') AS '.$this->type.')';
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
            $this->timeZone,
        );
    }

    private function storedRead(string $alias, string $column, null|string $zone): string
    {
        return $this->inZone($alias.'.'.SqlFragments::identifier($column), $zone);
    }

    private function storedWrite(string $alias, string $column, null|string $zone): TargetColumn
    {
        return new TargetColumn($alias, $column, $this->inZone('NEW.'.SqlFragments::identifier($this->column), $zone));
    }

    private function inZone(string $expression, null|string $zone): string
    {
        return $zone === null ? $expression : $expression.' AT TIME ZONE '.SqlFragments::literal($zone);
    }
}
