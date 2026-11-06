<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Carbon\CarbonInterval;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;

final readonly class Installer
{
    public const int THRESHOLD = 1000;

    public const int PASSES = 5;

    public const int RETRIES = 3;

    public const string LOCK_TIMEOUT = '5s';

    private const array RETRIED_STATES = ['55P03', '40P01'];

    public function __construct(private ConnectionInterface $connection) {}

    public function run(Statements $statements): void
    {
        $this->compileAndRun([], fn () => $statements);
    }

    /**
     * @param  list<string>  $tables
     * @param  Closure(): Statements  $compile
     */
    public function compileAndRun(array $tables, Closure $compile): void
    {
        $outside = $this->connection->transactionLevel() === 0;

        $statements = $this->connection->transaction(function () use ($tables, $compile, $outside): Statements {
            $this->lockSchema($tables);
            $statements = $this->compile($compile, $outside);
            $this->apply($statements);

            return $statements;
        });

        $this->runAfterwards($statements);
    }

    /**
     * @param  Closure(): Statements  $compile
     */
    public function install(ResolvedMap $map, CarbonInterval $statementTimeout, Closure $compile): void
    {
        $outside = $this->connection->transactionLevel() === 0;
        $budget = self::milliseconds($statementTimeout);

        if ($budget < 1) {
            throw new LogicException('Compatibility::install() takes a statementTimeout of at least one millisecond, which bounds how long it holds its lock.');
        }

        Statements::refuseUndeclaredDependents(
            map: $map,
            connection: $this->connection,
        );
        $backfiller = new Backfiller($map, $this->connection);
        $watermark = $backfiller->remembered();
        $rolledBack = $watermark === null && $this->rolledBack($map);

        if (($watermark !== null || $rolledBack) && ! $outside) {
            throw new LogicException("Compatibility::install() catches up {$map->table} in transactions of its own before it takes the lock, and the connection is already inside a transaction. Run it from a migration that sets public \$withinTransaction = false.");
        }

        if ($rolledBack) {
            $watermark = $backfiller->run()['watermark'];
        }

        if ($watermark !== null) {
            $backfiller->refuseUnconvergeable($watermark);
            $watermark = $this->converge(
                map: $map,
                backfiller: $backfiller,
                watermark: $watermark,
            );
        }

        $install = $this->locked(function () use ($map, $backfiller, $watermark, $compile, $outside, $budget): Statements {
            $this->connection->statement("SET LOCAL statement_timeout = '{$budget}ms'");
            $started = hrtime(true);
            $this->lockSchema($map->tables());
            $install = $this->compile(
                compile: $compile,
                outside: $outside,
            );

            if ($install->transaction === []) {
                return $install;
            }

            $this->connection->statement('LOCK TABLE '.SqlFragments::identifier($map->table).' IN SHARE ROW EXCLUSIVE MODE');

            if ($watermark !== null) {
                $backfiller->catchUp($watermark);
            }

            $this->apply(
                statements: $install,
                before: fn (string $statement) => $this->refuseSpent(
                    map: $map,
                    started: $started,
                    budget: $budget,
                    statement: $statement,
                ),
            );

            return $install;
        });

        $this->runAfterwards($install);
    }

    private function rolledBack(ResolvedMap $map): bool
    {
        $relation = "to_regclass(quote_ident(current_schema()) || '.' || ?)";
        $ledger = Backfiller::ledger($map->table);

        if ($this->connection->scalar(
            query: "select relkind::text from pg_class where oid = {$relation}",
            bindings: [SqlFragments::identifier($map->table)],
        ) !== 'r' || $this->connection->scalar(
            query: "select {$relation} is not null",
            bindings: [SqlFragments::identifier($ledger)],
        ) !== true) {
            return false;
        }

        return $this->connection->scalar('select exists (select from '.SqlFragments::identifier($ledger).')') === true;
    }

    private static function milliseconds(DateInterval $interval): int
    {
        $start = new DateTimeImmutable('@0');

        return (int) round(((float) $start->add($interval)->format('U.u') - (float) $start->format('U.u')) * 1000);
    }

    private function refuseSpent(ResolvedMap $map, int|float $started, int $budget, string $statement): void
    {
        $elapsed = (hrtime(true) - $started) / 1e6;

        if ($elapsed >= $budget) {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'install',
                because: 'it held its lock for '.(int) $elapsed." ms of its {$budget} ms statementTimeout before the step ".strtok(
                    string: $statement,
                    token: "\n",
                ).', so it rolled back. Raise statementTimeout, or shorten the final catch-up, then install again',
            );
        }
    }

    /**
     * @param  array{key: null|int|string, updatedAt: string}  $watermark
     * @return array{key: null|int|string, updatedAt: string}
     */
    private function converge(ResolvedMap $map, Backfiller $backfiller, array $watermark): array
    {
        for ($pass = 1; ; $pass++) {
            [$changed, $read, $watermark] = $backfiller->catchUp($watermark);
            $backfiller->remember(
                watermark: $watermark,
                rows: $read,
            );

            if ($changed < self::THRESHOLD && $read < self::THRESHOLD) {
                return $watermark;
            }

            if ($pass === self::PASSES) {
                throw InvalidCompatibilityMap::cannot(
                    map: $map->table,
                    operation: 'install',
                    because: "its catch-up pass {$pass} still read {$read} legacy rows and changed {$changed} successor rows, and install() takes the lock only once a pass reads and changes fewer than ".self::THRESHOLD.' rows each. Run php artisan compatibility:backfill '.$map->model.' again, then install()',
                );
            }
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $locked
     * @return TResult
     */
    private function locked(Closure $locked): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->connection->transaction(function () use ($locked): mixed {
                    $this->connection->statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");

                    return $locked();
                });
            } catch (QueryException $exception) {
                if (! in_array($exception->getCode(), self::RETRIED_STATES, true) || $attempt === self::RETRIES) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function lockSchema(array $tables): void
    {
        $locked = array_values(array_filter(array_map(
            fn (string $table) => $this->connection->scalar("select cast(to_regclass(quote_ident(current_schema()) || '.' || ?) as text)", [SqlFragments::identifier($table)]),
            $tables,
        ), is_string(...)));

        if ($locked !== []) {
            $this->connection->unprepared('LOCK TABLE '.implode(', ', $locked).' IN SHARE UPDATE EXCLUSIVE MODE');
        }
    }

    /**
     * @param  Closure(): Statements  $compile
     */
    private function compile(Closure $compile, bool $outside): Statements
    {
        $statements = $compile();
        if ($statements->afterwards !== [] && ! $outside) {
            throw new LogicException("Compatibility::{$statements->operation}() runs ".count($statements->afterwards).' statements after its own transaction commits, and the connection is already inside a transaction. Run it from a migration that sets public $withinTransaction = false.');
        }

        return $statements;
    }

    /**
     * @param  null|Closure(string): void  $before
     */
    private function apply(Statements $statements, null|Closure $before = null): void
    {
        foreach ($statements->transaction as $index => $statement) {
            if ($before !== null) {
                $before($statement);
            }

            $this->connection->unprepared($statement);

            foreach ($statements->steps[$index] ?? [] as $step) {
                $step($this->connection);
            }

            foreach ($statements->hooks[$index] ?? [] as [, $hook]) {
                if (! $this->connection instanceof Connection) {
                    throw new LogicException('The hooks of dependents() take an Illuminate\\Database\\Connection.');
                }

                $hook($this->connection);
            }
        }
    }

    private function runAfterwards(Statements $statements): void
    {
        foreach ($statements->afterwards as $statement) {
            $this->connection->unprepared($statement);
        }
    }
}
