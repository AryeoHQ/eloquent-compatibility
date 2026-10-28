<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

final readonly class UnrepresentableState
{
    private function __construct(
        public UnrepresentableStateKind $kind,
        public string $name,
        public string $description,
        public string $because,
        public string $instead,
        public null|string $checkedOn,
        public null|string $checkCondition,
        public null|string $previousUpdateCondition,
    ) {}

    public static function refuse(string $name, string $description, string $because, string $instead): self
    {
        return new self(UnrepresentableStateKind::Refuse, $name, $description, $because, $instead, null, null, null);
    }

    public static function degrade(string $name, string $description, string $rule): self
    {
        return new self(UnrepresentableStateKind::Degrade, $name, $description, $rule, '', null, null, null);
    }

    public static function hide(string $name, string $description, string $because, string $when): self
    {
        return new self(UnrepresentableStateKind::Hide, $name, $description, $because, '', null, $when, null);
    }

    public function checkedOn(string $alias, string $condition): self
    {
        return new self($this->kind, $this->name, $this->description, $this->because, $this->instead, $alias, $condition, $this->previousUpdateCondition);
    }

    public function checkedOnPreviousUpdate(string $condition): self
    {
        return new self($this->kind, $this->name, $this->description, $this->because, $this->instead, $this->checkedOn, $this->checkCondition, $condition);
    }
}
