<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

final class Sql
{
    public static function id(string $identifier): string
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
     */
    public static function changed(array $columns): string
    {
        $new = implode(', ', array_map(fn (string $column) => 'NEW.'.self::id($column), $columns));
        $old = implode(', ', array_map(fn (string $column) => 'OLD.'.self::id($column), $columns));

        return "ROW({$new}) IS DISTINCT FROM ROW({$old})";
    }

    public static function refuse(string $message, string $detail, string $hint): string
    {
        return self::raise('CM001', $message, $detail, $hint);
    }

    public static function raise(string $code, string $message, string $detail, string $hint): string
    {
        return 'RAISE EXCEPTION USING ERRCODE = '.self::literal($code)
            .', MESSAGE = '.$message
            .', DETAIL = '.self::literal($detail)
            .', HINT = '.self::literal($hint).';';
    }
}
