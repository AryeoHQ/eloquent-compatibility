<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Throwable;

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
    public function install(ResolvedMap $map, Closure $compile): void
    {
        $outside = $this->connection->transactionLevel() === 0;
        $backfiller = new Backfiller($map, $this->connection);
        $watermark = $backfiller->remembered();

        if ($watermark !== null && ! $outside) {
            throw new LogicException("Compatibility::install() catches up {$map->table} in transactions of its own before it takes the lock, and the connection is already inside a transaction. Run it from a migration that sets public \$withinTransaction = false.");
        }

        $retaining = false;

        try {
            if ($watermark !== null) {
                $backfiller->refuseUnconvergeable($watermark);
                $watermark = $this->converge($map, $backfiller, $watermark);

                if ($map->branches === []) {
                    $retaining = true;
                    $this->retain(Statements::retain($map, $this->connection));
                }
            }

            $install = $this->locked(function () use ($map, $backfiller, $watermark, $compile, $outside): Statements {
                $this->lockSchema($map->tables());
                $install = $this->compile($compile, $outside);
                $this->connection->statement('LOCK TABLE '.SqlFragments::identifier($map->table).' IN SHARE ROW EXCLUSIVE MODE');

                if ($watermark !== null) {
                    $backfiller->catchUp($watermark);
                }

                $this->apply($install);
                $backfiller->forget();

                return $install;
            });

            $retaining = false;
            $this->runAfterwards($install);
        } catch (Throwable $exception) {
            if ($retaining) {
                $this->unretain($map);
            }

            throw $exception;
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
            $backfiller->remember($watermark);

            if ($changed < self::THRESHOLD && $read < self::THRESHOLD) {
                return $watermark;
            }

            if ($pass === self::PASSES) {
                throw InvalidCompatibilityMap::cannot($map->table, 'install', "its catch-up pass {$pass} still read {$read} legacy rows and changed {$changed} successor rows, and install() takes the lock only once a pass reads and changes fewer than ".self::THRESHOLD.' rows each. Run php artisan compatibility:backfill '.$map->model." --since={$watermark['updatedAt']} again, then install()");
            }
        }
    }

    private function retain(Statements $retain): void
    {
        $this->locked(function () use ($retain): void {
            foreach ($retain->transaction as $statement) {
                $this->connection->unprepared($statement);
            }
        });

        $this->runAfterwards($retain);
    }

    private function unretain(ResolvedMap $map): void
    {
        try {
            $this->locked(function () use ($map): void {
                $this->connection->unprepared(Statements::unretain($map));
            });
        } catch (Throwable) {
            return;
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

    private function apply(Statements $statements): void
    {
        foreach ($statements->transaction as $index => $statement) {
            $this->connection->unprepared($statement);

            foreach ($statements->steps[$index] ?? [] as $step) {
                $step($this->connection);
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
