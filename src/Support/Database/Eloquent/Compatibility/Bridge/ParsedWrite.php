<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

final readonly class ParsedWrite
{
    private const string IDENTIFIER = '"(?:[^"]|"")+"|[a-z_][a-z0-9_]*';

    private function __construct(
        public Operation $operation,
        public string $relation,
    ) {}

    public static function of(string $sql): null|self
    {
        if (preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+(?:(?:'.self::IDENTIFIER.')\.)?('.self::IDENTIFIER.')/i', $sql, $matches) !== 1) {
            return null;
        }

        return new self(Operation::from(strtolower(substr($matches[1], 0, 6))), self::identifier($matches[2]));
    }

    private static function identifier(string $identifier): string
    {
        return str_starts_with($identifier, '"') ? str_replace('""', '"', substr($identifier, 1, -1)) : strtolower($identifier);
    }
}
