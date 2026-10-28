<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

final class SqlFragments
{
    public static function identifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    public static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    public static function like(string $prefix): string
    {
        return self::literal(addcslashes($prefix, '\\_%').'%');
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $comparedAsText
     */
    public static function changed(array $columns, array $comparedAsText = []): string
    {
        $operand = fn (string $row, string $column) => in_array($column, $comparedAsText, true)
            ? '('.$row.'.'.self::identifier($column).')::text'
            : $row.'.'.self::identifier($column);
        $new = implode(', ', array_map(fn (string $column) => $operand('NEW', $column), $columns));
        $old = implode(', ', array_map(fn (string $column) => $operand('OLD', $column), $columns));

        return "ROW({$new}) IS DISTINCT FROM ROW({$old})";
    }

    public static function refuse(string $message, string $detail, string $hint): string
    {
        return self::raise(SqlState::Refused, $message, $detail, $hint);
    }

    public static function raise(SqlState $code, string $message, string $detail, string $hint): string
    {
        return 'RAISE EXCEPTION USING ERRCODE = '.self::literal($code->value)
            .', MESSAGE = '.$message
            .', DETAIL = '.self::literal($detail)
            .', HINT = '.self::literal($hint).';';
    }
}
