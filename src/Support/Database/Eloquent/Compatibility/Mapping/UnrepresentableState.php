<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

final readonly class UnrepresentableState
{
    private function __construct(
        public UnrepresentableStateKind $kind,
        public string $name,
        public string $state,
        public string $because,
        public string $instead,
        public null|string $alias,
        public null|string $condition,
        public null|string $previousUpdate,
    ) {}

    public static function refuse(string $name, string $state, string $because, string $instead): self
    {
        return new self(UnrepresentableStateKind::Refuse, $name, $state, $because, $instead, null, null, null);
    }

    public static function degrade(string $name, string $state, string $rule): self
    {
        return new self(UnrepresentableStateKind::Degrade, $name, $state, $rule, '', null, null, null);
    }

    public static function hide(string $name, string $state, string $because, string $when): self
    {
        return new self(UnrepresentableStateKind::Hide, $name, $state, $because, '', null, $when, null);
    }

    public function checkedOn(string $alias, string $condition): self
    {
        return new self($this->kind, $this->name, $this->state, $this->because, $this->instead, $alias, $condition, $this->previousUpdate);
    }

    public function checkedOnPreviousUpdate(string $condition): self
    {
        return new self($this->kind, $this->name, $this->state, $this->because, $this->instead, $this->alias, $this->condition, $condition);
    }
}
