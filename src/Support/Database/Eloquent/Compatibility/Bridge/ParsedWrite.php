<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

final readonly class ParsedWrite
{
    private const string IDENTIFIER = '(?:"(?:[^"]|"")+"|[a-z_][a-z0-9_]*)';

    private const string NOT_A_PLACEHOLDER = '/(?<quoted>"(?:[^"]|"")*")|--[^\n]*|(?<comment>\/\*(?:[^\/*]|\*(?!\/)|\/(?!\*)|(?&comment))*\*\/)|(?<![\w$])[e]\'(?:[^\'\\\\]|\\\\.|\'\')*\'|\'(?:[^\']|\'\')*\'|(?<![\w$])\$(?<tag>(?:[a-z_\x80-\xff][\w\x80-\xff]*)?)\$.*?\$\k<tag>\$|\?\?/is';

    /**
     * @param  null|list<string>  $columns
     * @param  array<string, list<int>>  $wherePlaceholders
     */
    private function __construct(
        public Operation $operation,
        public string $relation,
        public null|array $columns,
        public array $wherePlaceholders,
    ) {}

    public static function of(string $sql): null|self
    {
        if (preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+(?:'.self::IDENTIFIER.'\.)?('.self::IDENTIFIER.')/i', $sql, $matches) !== 1) {
            return null;
        }

        $operation = Operation::from(strtolower(substr($matches[1], 0, 6)));

        return new self(
            $operation,
            self::identifier($matches[2]),
            $operation === Operation::Insert ? self::columns(substr($sql, strlen($matches[0]))) : null,
            self::wherePlaceholders($sql),
        );
    }

    /**
     * @return null|list<string>
     */
    private static function columns(string $rest): null|array
    {
        if (preg_match('/^\s*default\s+values\b/i', $rest) === 1) {
            return [];
        }

        if (preg_match('/^\s*\(\s*('.self::IDENTIFIER.'(?:\s*,\s*'.self::IDENTIFIER.')*)\s*\)/i', $rest, $list) !== 1) {
            return null;
        }

        preg_match_all('/'.self::IDENTIFIER.'/i', $list[1], $names);

        return array_map(self::identifier(...), $names[0]);
    }

    /**
     * @return array<string, list<int>>
     */
    private static function wherePlaceholders(string $sql): array
    {
        $tokens = self::tokens($sql);

        if (preg_match('/\swhere\s/i', $tokens, $where, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        preg_match_all('/(?:'.self::IDENTIFIER.'\.)?('.self::IDENTIFIER.')\s*=\s*\?/i', $tokens, $equalities, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $where[0][1]);
        $placeholders = [];

        foreach ($equalities as $equality) {
            $placeholders[self::identifier(substr($sql, $equality[1][1], strlen($equality[1][0])))][] = substr_count($tokens, '?', 0, $equality[0][1] + strlen($equality[0][0]) - 1);
        }

        return $placeholders;
    }

    private static function tokens(string $sql): string
    {
        return (string) preg_replace_callback(
            self::NOT_A_PLACEHOLDER,
            fn (array $matches) => ($matches['quoted'] ?? '') !== '' ? '"'.str_repeat('x', strlen($matches[0]) - 2).'"' : str_repeat(' ', strlen($matches[0])),
            $sql,
        );
    }

    private static function identifier(string $identifier): string
    {
        return str_starts_with($identifier, '"') ? str_replace('""', '"', substr($identifier, 1, -1)) : strtolower($identifier);
    }
}
