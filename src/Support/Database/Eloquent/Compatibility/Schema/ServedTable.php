<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use WeakMap;

final class ServedTable
{
    private const array VIEW_WRITE_STATES = ['55000', '42809', '42703'];

    private const string SESSION_CONTEXT = "/^\\s*(?:(?:set|reset)\\s+(?:(?:local|session)\\s+)?(?:\"(?:search_path|role|session_authorization)\"|(?:search_path|schema|role|session_authorization|session\\s+authorization|all)\\b)|discard\\s+all\\b)|set_config\\s*\\(\\s*(?:'(?:search_path|role|session_authorization)'|\\?)/i";

    /** @var null|WeakMap<Connection, array<string, true>> */
    private static null|WeakMap $tables = null;

    /** @var null|WeakMap<Connection, int> */
    private static null|WeakMap $transactions = null;

    /** @var null|WeakMap<Connection, int> */
    private static null|WeakMap $answered = null;

    /** @var null|WeakMap<Connection, array<string, false|int>> */
    private static null|WeakMap $views = null;

    /** @var null|WeakMap<Connection, array<string, array{path: string, view: bool, oid: int, unbound: list<string>, readable: bool}>> */
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
     * @return list<string>
     */
    public static function successorsOf(ResolvedMap $map): array
    {
        if ($map->branches !== []) {
            return array_values(array_unique(array_merge(...array_map(self::successorsOf(...), array_values($map->branches)))));
        }

        return [$map->primary->table, ...array_map(fn (Related $related) => $related->table, array_values($map->related))];
    }

    /**
     * @return list<string>
     */
    public static function readsOf(ResolvedMap $map): array
    {
        return [...self::successorsOf($map), ...($map->retainedTable === null ? [] : [$map->retainedTable])];
    }

    /**
     * @param  list<string>  $successors
     * @return array{path: string, view: bool, oid: int, unbound: list<string>, readable: bool}
     */
    public static function reading(Model $model, array $successors, bool $fresh = false): array
    {
        return self::relation($model->getConnection(), $model->getTable(), $successors, fresh: $fresh);
    }

    /**
     * @param  list<string>  $successors
     * @return array{path: string, view: bool, oid: int, unbound: list<string>, readable: bool}
     */
    public static function relation(Connection $connection, string $table, array $successors, bool $writing = false, bool $fresh = false): array
    {
        $key = implode(' ', [$writing ? 'writing' : 'reading', $table, ...$successors]);
        $relations = self::answering($connection) ? self::$relations[$connection] ?? [] : [];

        if (! $fresh && array_key_exists($key, $relations)) {
            return $relations[$key];
        }

        $successorTables = 'jsonb_array_elements_text(cast(? as jsonb)) as successor cross join lateral to_regclass(quote_ident(successor)) as successor_table';
        $row = (array) $connection->selectOne(
            "select array_to_string(current_schemas(true), ',') as path,
                    (select cast(oid as bigint) from pg_class where oid = legacy.oid and relkind = 'v') as view,
                    (select cast(coalesce(json_agg(successor order by successor), '[]') as text) from {$successorTables} where not coalesce(successor_table = any(legacy.reads), false) and exists (select from pg_class read where read.oid = any(legacy.reads) and read.relname = successor)) as unbound,
                    coalesce((select bool_and(coalesce(has_table_privilege(successor_table, 'SELECT') and not row_security_active(successor_table) and successor_table = any(legacy.reads), false)) from {$successorTables}), false) as readable
             from (select to_regclass(?) as oid) as relation
             cross join lateral (select relation.oid, array(select dependency.refobjid from pg_rewrite rewrite join pg_depend dependency on dependency.classid = 'pg_rewrite'::regclass and dependency.objid = rewrite.oid and dependency.refclassid = 'pg_class'::regclass where rewrite.ev_class = relation.oid) as reads) as legacy",
            [json_encode($successors, JSON_THROW_ON_ERROR), json_encode($successors, JSON_THROW_ON_ERROR), SqlFragments::identifier($table)],
            ! $writing,
        );
        $oid = is_int($row['view'] ?? null) ? $row['view'] : 0;
        $unbound = array_values(array_filter((array) json_decode(is_string($row['unbound'] ?? null) ? $row['unbound'] : '[]', true, flags: JSON_THROW_ON_ERROR), is_string(...)));
        $relations[$key] = ['path' => (string) ($row['path'] ?? ''), 'view' => $oid !== 0, 'oid' => $oid, 'unbound' => $unbound, 'readable' => $oid !== 0 && ($row['readable'] ?? null) === true];

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
    public static function write(Model $model, ResolvedMap $map, Closure $native, Closure $translated): mixed
    {
        $successors = self::readsOf($map);
        $connection = $model->getConnection();
        $table = $model->getTable();
        $nested = $connection->transactionLevel() > 0;
        $known = ! $nested && array_key_exists($table, self::$tables[$connection] ?? []);

        if (! $known && self::ask($connection, $table) !== null) {
            return self::bound($model, $successors, $translated);
        }

        try {
            return $nested ? $connection->transaction($native) : $native();
        } catch (QueryException $exception) {
            if (! in_array($exception->errorInfo[0] ?? null, self::VIEW_WRITE_STATES, true) || self::ask($connection, $table) === null) {
                throw $exception;
            }
        }

        return self::bound($model, $successors, $translated);
    }

    /**
     * @template TResult
     *
     * @param  list<string>  $successors
     * @param  Closure(): TResult  $translated
     * @return TResult
     */
    private static function bound(Model $model, array $successors, Closure $translated): mixed
    {
        $unbound = self::relation($model->getConnection(), $model->getTable(), $successors, writing: true)['unbound'];

        if ($unbound !== []) {
            throw new CompatibilityWriteRefused(
                $model::class." records cannot be written through the view {$model->getTable()}",
                'The search path resolves '.implode(' and ', $unbound).(count($unbound) === 1 ? ' to another table' : ' to other tables')." than the view reads, so the write would change rows that {$model->getTable()} does not serve.",
                'Set a search path that resolves each successor to the table the view reads.',
            );
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
            $connection->beforeExecuting(static function (string $query, array $bindings, Connection $connection): void {
                if (preg_match(self::SESSION_CONTEXT, $query) === 1) {
                    self::$answered?->offsetUnset($connection);
                }
            });
            $connection->getEventDispatcher()?->listen(TransactionRolledBack::class, static function (TransactionRolledBack $event): void {
                self::$answered?->offsetUnset($event->connection);
            });
        }

        return $transactions[$connection];
    }
}
