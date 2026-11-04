<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final class Identifier
{
    public const string SHARED_SEQUENCE = 'compatibility_legacy_id_seq';

    private null|string $type = null;

    private null|string $column = null;

    public function __construct(private readonly string $map, public readonly string $name) {}

    public function integer(): self
    {
        return $this->typed('integer');
    }

    public function uuid(): self
    {
        return $this->typed('uuid');
    }

    public function from(string $column): self
    {
        if ($this->column !== null) {
            throw $this->invalid("reads from both '{$this->column}' and '{$column}'");
        }

        if ($column === '' || str_contains($column, '.')) {
            throw $this->invalid("reads from '{$column}', which names no column of the primary successor's own table");
        }

        $this->column = $column;

        return $this;
    }

    public function column(): null|string
    {
        return $this->column;
    }

    public function isInteger(): bool
    {
        return $this->type === 'integer';
    }

    public function isUuid(): bool
    {
        return $this->type === 'uuid';
    }

    public static function sharedDefault(): string
    {
        return 'nextval('.SqlFragments::literal(self::SHARED_SEQUENCE).'::regclass)';
    }

    public function resolve(Successor $primary, null|string $discriminator = null): self
    {
        $problem = match (true) {
            $this->type === null => 'says neither integer() nor uuid()',
            $this->column === null => 'says no from(), so it names no successor column',
            $this->column === $primary->key => "reads {$primary->table}.{$primary->key}, the successor's own key; a legacy identifier lives in a column of its own",
            $this->column === $discriminator => "reads {$primary->table}.{$discriminator}, which discriminatedBy() names; a legacy identifier lives in a column of its own",
            default => null,
        };

        if ($problem !== null) {
            throw $this->invalid($problem);
        }

        return $this;
    }

    private function typed(string $type): self
    {
        if ($this->type !== null) {
            throw $this->invalid("is typed twice, as {$this->type}() and as {$type}()");
        }

        $this->type = $type;

        return $this;
    }

    private function invalid(string $problem): InvalidCompatibilityMap
    {
        return InvalidCompatibilityMap::because($this->map, "identifier '{$this->name}' {$problem}");
    }
}
