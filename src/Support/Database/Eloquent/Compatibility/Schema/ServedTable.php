<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use WeakMap;

final class ServedTable
{
    private const array VIEW_WRITE_STATES = ['55000', '42809'];

    /** @var null|WeakMap<Connection, array<string, true>> */
    private static null|WeakMap $tables = null;

    /** @var null|WeakMap<Connection, int> */
    private static null|WeakMap $transactions = null;

    /** @var null|WeakMap<Connection, int> */
    private static null|WeakMap $answered = null;

    /** @var null|WeakMap<Connection, array<string, false|int>> */
    private static null|WeakMap $views = null;

    /** @var null|WeakMap<Connection, array<string, array{schema: string, view: bool, readable: bool}>> */
    private static null|WeakMap $relations = null;

    public static function forget(): void
    {
        self::$tables = null;
        self::$answered = null;
        self::$views = null;
        self::$relations = null;
    }

    public static function serves(Model $model): bool
    {
        return self::view($model->getConnection(), $model->getTable()) !== null;
    }

    public static function view(Connection $connection, string $table): null|int
    {
        $views = self::answering($connection) ? self::$views[$connection] ?? [] : [];

        return array_key_exists($table, $views) ? ($views[$table] === false ? null : $views[$table]) : self::ask($connection, $table);
    }

    /**
     * @param  list<string>  $successors
     * @return array{schema: string, view: bool, readable: bool}
     */
    public static function reading(Model $model, array $successors): array
    {
        $connection = $model->getConnection();
        $table = $model->getTable();
        $key = implode(' ', [$table, ...$successors]);
        $relations = self::answering($connection) ? self::$relations[$connection] ?? [] : [];

        if (array_key_exists($key, $relations)) {
            return $relations[$key];
        }

        $row = (array) $connection->selectOne(
            "select coalesce((select relnamespace::regnamespace::text from pg_class where oid = to_regclass(?)), current_schema()) as schema,
                    coalesce((select relkind = 'v' from pg_class where oid = to_regclass(?)), false) as view,
                    coalesce((select bool_and(coalesce(has_table_privilege(successor_table, 'SELECT') and not row_security_active(successor_table), false)) from jsonb_array_elements_text(cast(? as jsonb)) as successor cross join lateral to_regclass(quote_ident(successor)) as successor_table), false) as readable",
            [SqlFragments::identifier($table), SqlFragments::identifier($table), json_encode($successors, JSON_THROW_ON_ERROR)],
        );
        $relations[$key] = ['schema' => (string) ($row['schema'] ?? ''), 'view' => ($row['view'] ?? null) === true, 'readable' => ($row['readable'] ?? null) === true];

        if (self::answer($connection)) {
            $answers = self::$relations ??= new WeakMap;
            $answers[$connection] = $relations;
        }

        return $relations[$key];
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $native
     * @param  Closure(): TResult  $translated
     * @return TResult
     */
    public static function write(Model $model, Closure $native, Closure $translated): mixed
    {
        $connection = $model->getConnection();
        $table = $model->getTable();
        $nested = $connection->transactionLevel() > 0;
        $known = ! $nested && array_key_exists($table, self::$tables[$connection] ?? []);

        if (! $known && self::ask($connection, $table) !== null) {
            return $translated();
        }

        try {
            return $nested ? $connection->transaction($native) : $native();
        } catch (QueryException $exception) {
            if (! in_array($exception->errorInfo[0] ?? null, self::VIEW_WRITE_STATES, true) || self::ask($connection, $table) === null) {
                throw $exception;
            }
        }

        return $translated();
    }

    private static function ask(Connection $connection, string $table): null|int
    {
        $view = $connection->scalar(
            "select cast(oid as bigint) from pg_class where oid = to_regclass(?) and relkind = 'v'",
            [SqlFragments::identifier($table)],
            false,
        );
        $view = is_int($view) ? $view : null;
        $tables = self::$tables ??= new WeakMap;
        $known = $tables[$connection] ?? [];

        if ($view === null) {
            $known[$table] = true;
        } else {
            unset($known[$table]);
        }

        $tables[$connection] = $known;

        if (self::answer($connection)) {
            $views = self::$views ??= new WeakMap;
            $answers = $views[$connection] ?? [];
            $answers[$table] = $view ?? false;
            $views[$connection] = $answers;
        }

        return $view;
    }

    private static function answering(Connection $connection): bool
    {
        return $connection->transactionLevel() > 0 && (self::$answered[$connection] ?? null) === self::transaction($connection);
    }

    private static function answer(Connection $connection): bool
    {
        if ($connection->transactionLevel() === 0) {
            return false;
        }

        if (! self::answering($connection)) {
            $answered = self::$answered ??= new WeakMap;
            $answered[$connection] = self::transaction($connection);
            self::$views?->offsetUnset($connection);
            self::$relations?->offsetUnset($connection);
        }

        return true;
    }

    private static function transaction(Connection $connection): int
    {
        $transactions = self::$transactions ??= new WeakMap;

        if (! $transactions->offsetExists($connection)) {
            $transactions[$connection] = 0;
            $connection->beforeStartingTransaction(static function (Connection $connection): void {
                if ($connection->transactionLevel() === 0 && self::$transactions !== null) {
                    self::$transactions[$connection] = (self::$transactions[$connection] ?? 0) + 1;
                }
            });
        }

        return $transactions[$connection];
    }
}
