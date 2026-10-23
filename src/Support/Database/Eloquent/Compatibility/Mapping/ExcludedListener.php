<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Support\Str;

final readonly class ExcludedListener
{
    private function __construct(
        public string $listener,
        public null|string $event,
        public string $because,
    ) {}

    public static function of(string $listener, string $because): self
    {
        return new self($listener, null, $because);
    }

    public function forEvent(string $event): self
    {
        return new self($this->listener, $event, $this->because);
    }

    public function appliesTo(string $event): bool
    {
        return $this->event === null || $this->event === $event;
    }

    public function matches(string $event, string $class, string $method): bool
    {
        if (! $this->appliesTo($event)) {
            return false;
        }

        [$excludedClass, $excludedMethod] = Str::parseCallback($this->listener);

        return ltrim($excludedClass, '\\') === ltrim($class, '\\')
            && ($excludedMethod === null || $excludedMethod === $method);
    }
}
