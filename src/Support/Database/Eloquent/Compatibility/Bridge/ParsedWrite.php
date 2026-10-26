<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

final readonly class ParsedWrite
{
    private function __construct(
        public Operation $operation,
        public string $relation,
    ) {}

    public static function write(string $sql): null|self
    {
        if (preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+(?:"?[a-z_][a-z0-9_]*"?\.)?"?([a-z_][a-z0-9_]*)"?/i', $sql, $matches) !== 1) {
            return null;
        }

        return new self(Operation::from(strtolower(substr($matches[1], 0, 6))), strtolower($matches[2]));
    }
}
