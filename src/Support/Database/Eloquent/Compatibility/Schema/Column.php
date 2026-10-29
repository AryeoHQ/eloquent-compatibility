<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

final readonly class Column
{
    public function __construct(
        public string $type,
        public null|string $default,
        public bool $notNull,
        public bool $generated = false,
        public bool $alwaysGenerated = false,
        public null|string $baseType = null,
        public bool $defaultReferencesSource = false,
        public bool $typeReferencesSource = false,
    ) {}

    public function needsAValue(): bool
    {
        return $this->notNull && $this->default === null && ! $this->generated;
    }
}
