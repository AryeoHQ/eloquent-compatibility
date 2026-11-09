<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;

final readonly class Installer
{
    public const int THRESHOLD = 1000;

    public const int PASSES = 5;

    public const int RETRIES = 3;

    public const int LOCK_TIMEOUT = 5000;

    private const array RETRIED_STATES = ['55P03', '40P01'];

    private const string CANCELED = '57014';

    public function __construct(private ConnectionInterface $connection) {}

    public static function lockTimeout(): string
    {
        return "SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."ms'";
    }

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
        $this->runAfterwards($this->transaction(
            tables: $tables,
            compile: $compile,
            outside: $this->connection->transactionLevel() === 0,
        ));
    }

    /**
     * @param  Closure(): Statements  $compile
     */
    public function rollback(ResolvedMap $map, Closure $compile): void
    {
        $outside = $this->connection->transactionLevel() === 0;

        $this->runAfterwards($this->restoring(
            outside: $outside,
            settings: ['lock_timeout'],
            run: fn () => $this->retrying(
                map: $map,
                operation: 'rollback',
                attempt: fn () => $this->transaction(
                    tables: $map->tables(),
                    compile: $compile,
                    outside: $outside,
                ),
            ),
        ));
    }

    /**
     * @param  Closure(): Statements  $compile
     */
    public function install(ResolvedMap $map, CarbonInterval $statementTimeout, Closure $compile): void
    {
        $outside = $this->connection->transactionLevel() === 0;
        $budget = (int) round($statementTimeout->totalMilliseconds);

        if ($budget < 1) {
            throw new LogicException('Compatibility::install() takes a statementTimeout of at least one millisecond, which bounds how long it holds its lock.');
        }

        Statements::refuseUndeclaredDependents(
            map: $map,
            connection: $this->connection,
        );
        $backfiller = new Backfiller($map, $this->connection);
        $watermark = $backfiller->remembered();

        if ($watermark !== null && ! $outside) {
            throw new LogicException("Compatibility::install() catches up {$map->table} in transactions of its own before it takes the lock, and the connection is already inside a transaction. Run it from a migration that sets public \$withinTransaction = false.");
        }

        $fullPass = $watermark === null && Statements::kind(
            connection: $this->connection,
            table: $map->table,
        ) === 'r';

        if ($watermark !== null || $fullPass) {
            $backfiller->refuseUnconvergeable();
        }

        if ($fullPass) {
            $watermark = $backfiller->run()['watermark'];
        }

        if ($watermark !== null) {
            $watermark = $this->converge(
                map: $map,
                backfiller: $backfiller,
                watermark: $watermark,
            );
        }

        $hidden = Statements::hiddenBeforeTheLock(
            map: $map,
            connection: $this->connection,
        );

        if ($hidden !== null) {
            Log::info("Compatibility map '{$map->table}' install: {$hidden}");
        }

        $this->restoring(
            outside: $outside,
            settings: ['statement_timeout', 'lock_timeout'],
            run: fn () => $this->locked(
                map: $map,
                budget: $budget,
                install: function (int|float $started) use ($map, $backfiller, $watermark, $compile, $budget): bool {
                    $this->lockSchema($map->tables());
                    $install = $compile();

                    if ($install->transaction === []) {
                        return false;
                    }

                    $this->connection->statement('LOCK TABLE '.SqlFragments::identifier($map->table).' IN SHARE ROW EXCLUSIVE MODE');

                    if ($watermark !== null) {
                        $backfiller->catchUp($watermark);
                    }

                    $this->apply(
                        statements: $install,
                        before: fn (string $statement) => self::refuseSpent(
                            map: $map,
                            started: $started,
                            budget: $budget,
                            moment: 'before',
                            statement: $statement,
                        ),
                    );
                    self::refuseSpent(
                        map: $map,
                        started: $started,
                        budget: $budget,
                        moment: 'after',
                        statement: $install->transaction[array_key_last($install->transaction)],
                    );

                    return true;
                },
            ),
        );
    }

    private static function refuseSpent(ResolvedMap $map, int|float $started, int $budget, string $moment, string $statement): void
    {
        if ((hrtime(true) - $started) / 1e6 >= $budget) {
            throw self::spent(
                map: $map,
                started: $started,
                budget: $budget,
                moment: $moment,
                statement: $statement,
            );
        }
    }

    private static function spent(ResolvedMap $map, int|float $started, int $budget, string $moment, string $statement): InvalidCompatibilityMap
    {
        return InvalidCompatibilityMap::cannot(
            map: $map->table,
            operation: 'install',
            because: 'it held its lock for '.(int) ((hrtime(true) - $started) / 1e6)." ms of its {$budget} ms statementTimeout {$moment} the step ".self::firstLine($statement).', so it rolled back. Raise statementTimeout, or shorten the final catch-up, then install again',
        );
    }

    private static function firstLine(string $statement): string
    {
        return (string) strtok(
            string: $statement,
            token: "\n",
        );
    }

    private function converge(ResolvedMap $map, Backfiller $backfiller, string $watermark): string
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
     * @param  Closure(int|float): bool  $install
     */
    private function locked(ResolvedMap $map, int $budget, Closure $install): bool
    {
        return $this->retrying(
            map: $map,
            operation: 'install',
            attempt: fn (): bool => $this->connection->transaction(function () use ($map, $budget, $install): bool {
                $this->connection->statement("SET LOCAL lock_timeout = '".min(self::LOCK_TIMEOUT, max(1, intdiv(
                    num1: $budget,
                    num2: 2,
                )))."ms'");
                $this->connection->statement("SET LOCAL statement_timeout = '{$budget}ms'");
                $started = hrtime(true);

                try {
                    return $install($started);
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== self::CANCELED) {
                        throw $exception;
                    }

                    throw self::spent(
                        map: $map,
                        started: $started,
                        budget: $budget,
                        moment: 'during',
                        statement: $exception->getSql(),
                    );
                }
            }),
        );
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $attempt
     * @return TResult
     */
    private function retrying(ResolvedMap $map, string $operation, Closure $attempt): mixed
    {
        $attempts = self::RETRIES + 1;

        for ($tried = 1; ; $tried++) {
            $started = hrtime(true);

            try {
                $result = $attempt();
            } catch (QueryException $exception) {
                if (! in_array(
                    needle: $exception->getCode(),
                    haystack: self::RETRIED_STATES,
                    strict: true,
                ) || $tried === $attempts) {
                    throw $exception;
                }

                Log::warning("Compatibility map '{$map->table}' {$operation}: attempt {$tried} of {$attempts} could not take a lock, SQLSTATE {$exception->getCode()} on ".self::firstLine($exception->getSql()).' after '.(int) ((hrtime(true) - $started) / 1e6).' ms, so it rolled back and retries');

                continue;
            }

            if ($tried > 1) {
                Log::info("Compatibility map '{$map->table}' {$operation}: took its locks on attempt {$tried} of {$attempts}");
            }

            return $result;
        }
    }

    /**
     * @template TResult
     *
     * @param  list<string>  $settings
     * @param  Closure(): TResult  $run
     * @return TResult
     */
    private function restoring(bool $outside, array $settings, Closure $run): mixed
    {
        if ($outside) {
            return $run();
        }

        $previous = array_combine(
            keys: $settings,
            values: array_map(
                callback: fn (string $setting) => (string) $this->connection->scalar(
                    query: 'select current_setting(?)',
                    bindings: [$setting],
                    useReadPdo: false,
                ),
                array: $settings,
            ),
        );
        $result = $run();

        foreach ($previous as $setting => $value) {
            $this->connection->statement(
                query: 'select set_config(?, ?, true)',
                bindings: [$setting, $value],
            );
        }

        return $result;
    }

    /**
     * @param  list<string>  $tables
     * @param  Closure(): Statements  $compile
     */
    private function transaction(array $tables, Closure $compile, bool $outside): Statements
    {
        return $this->connection->transaction(function () use ($tables, $compile, $outside): Statements {
            $this->lockSchema($tables);
            $statements = $compile();

            if ($statements->afterwards !== [] && ! $outside) {
                throw new LogicException("Compatibility::{$statements->operation}() runs ".count($statements->afterwards).' statements after its own transaction commits, and the connection is already inside a transaction. Run it from a migration that sets public $withinTransaction = false.');
            }

            $this->apply($statements);

            return $statements;
        });
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
