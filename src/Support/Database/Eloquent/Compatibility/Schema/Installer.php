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
use Support\Database\Eloquent\Compatibility\Console\Backfill;
use Support\Database\Eloquent\Compatibility\Console\CheckDrift;
use Support\Database\Eloquent\Compatibility\Console\DriftQueries;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Support\Database\Eloquent\Compatibility\Write\Ledger;
use Support\Database\Eloquent\Compatibility\Write\Outcome;
use Support\Database\Eloquent\Compatibility\Write\RetainedRows;
use Support\Database\Eloquent\Compatibility\Write\Watermark;

final readonly class Installer
{
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
     * @param  Closure(null|Closure(ConnectionInterface): void): Statements  $compile
     */
    public function install(ResolvedMap $map, CarbonInterval $statementTimeout, int $passSize, CarbonInterval $margin, Closure $compile): void
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

        if (Statements::kind(
            connection: $this->connection,
            table: $map->table,
        ) !== 'r') {
            $this->locked(
                map: $map,
                budget: $budget,
                install: function () use ($map, $compile): bool {
                    $this->lockSchema($map->tables());

                    return $compile(null)->transaction !== [];
                },
            );

            return;
        }

        $ledger = new Ledger(
            map: $map,
            connection: $this->connection,
        );
        $watermark = new Watermark(
            legacyType: $map->table,
            connection: $this->connection,
        );

        if (! $ledger->exists() || ! $watermark->tracked()) {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'install',
                because: 'Compatibility::prepare() creates '.Ledger::table($map->table).' and inserts the '.Watermark::TABLE." row of {$map->table}, and install() records its passes in both; run Compatibility::prepare() first",
            );
        }

        $watermark->hold('install');

        try {
            $this->beforeTheLock(
                map: $map,
                budget: $budget,
                passSize: $passSize,
                margin: $margin,
                outside: $outside,
                compile: $compile,
                ledger: $ledger,
                watermark: $watermark,
            );
        } finally {
            $watermark->release();
        }
    }

    /**
     * @param  Closure(null|Closure(ConnectionInterface): void): Statements  $compile
     */
    private function beforeTheLock(ResolvedMap $map, int $budget, int $passSize, CarbonInterval $margin, bool $outside, Closure $compile, Ledger $ledger, Watermark $watermark): void
    {
        $backfiller = new Backfiller(
            map: $map,
            connection: $this->connection,
        );
        $remembered = $watermark->remembered();

        if ($remembered !== null && ! $outside) {
            throw new LogicException("Compatibility::install() catches up {$map->table} in transactions of its own before it takes the lock, and the connection is already inside a transaction. Run it from a migration that sets public \$withinTransaction = false.");
        }

        $backfiller->refuseUnconvergeable($passSize);
        $this->refuseUnwindowed($map);

        if ($remembered === null) {
            $backfiller->run(margin: $margin);
        }

        $this->converge(
            map: $map,
            backfiller: $backfiller,
            passSize: $passSize,
            margin: $margin,
        );
        $retained = new RetainedRows(
            map: $map,
            connection: $this->connection,
        );
        $read = (string) $this->connection->scalar(
            query: "select to_char(now() at time zone 'UTC', '".Watermark::MICROSECONDS."')",
            useReadPdo: false,
        );
        $native = $retained->unserved($map->table);

        if ($native !== []) {
            Log::info("Compatibility map '{$map->table}' install: ".count($native).' natively created '.class_basename($map->primary->model).' '.(count($native) === 1 ? 'root has' : 'roots have')." no {$map->table} row, so install inserts one for each under its lock");
        }

        $this->prove(
            map: $map,
            backfiller: $backfiller,
            ledger: $ledger,
        );
        $backfiller->deletions();
        $ledger->refuseBlocked('install');
        $hidden = Statements::hiddenBeforeTheLock(
            map: $map,
            connection: $this->connection,
        );

        if ($hidden !== null) {
            Log::info("Compatibility map '{$map->table}' install: {$hidden}");
        }

        $since = Watermark::before(
            moment: $read,
            margin: $margin,
        );

        $this->restoring(
            outside: $outside,
            settings: ['statement_timeout', 'lock_timeout'],
            run: fn () => $this->locked(
                map: $map,
                budget: $budget,
                install: function (int|float $started) use ($map, $backfiller, $ledger, $retained, $native, $since, $margin, $compile, $budget): bool {
                    $this->lockSchema($map->tables());
                    $install = $compile(fn (ConnectionInterface $connection) => $retained->carryNative(
                        keys: $retained->unserved(
                            table: ViewCompiler::retainedTable($map),
                            among: $native,
                            since: $since,
                        ),
                        before: fn () => self::refuseSpent(
                            map: $map,
                            started: $started,
                            budget: $budget,
                            moment: 'before',
                            statement: 'the insert of a natively created root\'s legacy row',
                        ),
                    ));

                    if ($install->transaction === []) {
                        return false;
                    }

                    $this->connection->statement('LOCK TABLE '.SqlFragments::identifier($map->table).' IN SHARE ROW EXCLUSIVE MODE');
                    $backfiller->finalPass(
                        margin: $margin,
                        before: fn (int|string $key) => self::refuseSpent(
                            map: $map,
                            started: $started,
                            budget: $budget,
                            moment: 'before',
                            statement: "the final pass's write of {$map->table} ".(new $map->model)->getKeyName()." {$key}",
                        ),
                    );
                    $ledger->refuseBlocked('install');
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

    private function refuseUnwindowed(ResolvedMap $map): void
    {
        $root = new $map->primary->model;
        $catalog = new ColumnCatalog(
            connection: $this->connection,
            throughWriteConnection: true,
        );
        $created = $root->usesTimestamps() ? $root->getCreatedAtColumn() : null;

        if ($created === null || $catalog->type(
            table: $map->primary->table,
            column: $created,
        ) === null) {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'install',
                because: class_basename($map->primary->model)." stamps no created_at column on {$map->primary->table}, so install() cannot find a root created natively between its read of native roots and its lock, and the view would serve no record for that root. Give ".class_basename($map->primary->model).' timestamps, then install again',
            );
        }

        if (! $catalog->leadsAnIndex(
            table: $map->primary->table,
            column: $created,
        )) {
            Log::warning("Compatibility map '{$map->table}' install: no index leads with {$map->primary->table}.{$created}, so the read of natively created roots under the lock scans {$map->primary->table}. Create one before install with CREATE INDEX CONCURRENTLY ON ".SqlFragments::identifier($map->primary->table).' ('.SqlFragments::identifier($created).')');
        }
    }

    private function prove(ResolvedMap $map, Backfiller $backfiller, Ledger $ledger): void
    {
        $ledger->refuseBlocked('install');
        $proof = new DriftQueries($this->connection);
        [$missing, $differing] = $proof->proof($map);
        $flagged = array_values(array_unique(
            array: [...$missing, ...$differing],
            flags: SORT_STRING,
        ));

        if ($flagged === []) {
            return;
        }

        sort($flagged);
        Log::info("Compatibility map '{$map->table}' install: its proofs flagged ".count($flagged)." {$map->table} rows, which it catches up before it proves them again");
        $backfiller->catchUp($flagged);
        $ledger->refuseBlocked('install');
        [$missing, $differing] = $proof->proof(
            map: $map,
            among: $flagged,
        );
        $key = (new $map->model)->getKeyName();

        if ($missing !== []) {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'install',
                because: count($missing)." {$map->table} rows still have no {$map->primary->table} row that their {$map->successorKey} names after install caught them up; the first are {$key} ".self::first($missing).'. Backfill them before install()',
            );
        }

        if ($differing !== []) {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'install',
                because: count($differing)." {$map->table} rows still hold other values than their successors serve after install caught them up; the first are {$key} ".self::first($differing).'. Run '.CheckDrift::command($map->model).' to see each value, fix it, then install again',
            );
        }
    }

    /**
     * @param  list<int|string>  $keys
     */
    private static function first(array $keys): string
    {
        return implode(
            separator: ', ',
            array: array_slice(
                array: $keys,
                offset: 0,
                length: Ledger::FIRST_KEYS,
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

    private function converge(ResolvedMap $map, Backfiller $backfiller, int $passSize, CarbonInterval $margin): void
    {
        for ($pass = 1; ; $pass++) {
            $caughtUp = $backfiller->run(margin: $margin);
            Log::info("Compatibility map '{$map->table}' install: catch-up pass {$pass} walked {$caughtUp->walked} {$map->table} rows and {$caughtUp->gone} whose legacy row is gone, and changed the outcome of {$caughtUp->changed}: {$caughtUp->count(Outcome::Refused)} refused, {$caughtUp->count(Outcome::Failed)} failed");

            if ($caughtUp->changed === 0 || $caughtUp->changed < $passSize) {
                return;
            }

            if ($pass === self::PASSES) {
                throw InvalidCompatibilityMap::cannot(
                    map: $map->table,
                    operation: 'install',
                    because: "its catch-up pass {$pass} still changed the outcome of {$caughtUp->changed} legacy rows, and install() takes the lock only once a pass changes fewer than {$passSize}. Run ".Backfill::command($map->model).' again, then install()',
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
