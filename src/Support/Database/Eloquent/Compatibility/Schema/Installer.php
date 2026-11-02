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

final readonly class Installer
{
    public const int THRESHOLD = 1000;

    public const int PASSES = 5;

    public const int RETRIES = 3;

    public const string LOCK_TIMEOUT = '5s';

    private const string LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(private ConnectionInterface $connection) {}

    public function run(Statements $statements): void
    {
        $this->refuseTransaction($statements);

        $this->connection->transaction(fn () => $this->apply($statements));

        foreach ($statements->afterwards as $statement) {
            $this->connection->unprepared($statement);
        }
    }

    /**
     * @param  Closure(): Statements  $statements
     */
    public function install(ResolvedMap $map, Closure $statements): void
    {
        $backfiller = new Backfiller($map, $this->connection);
        $watermark = $backfiller->remembered();

        if ($watermark !== null && $this->connection->transactionLevel() > 0) {
            throw new LogicException("Compatibility::install() catches up {$map->table} in transactions of its own before it takes the lock, and the connection is already inside a transaction. Run it from a migration that sets public \$withinTransaction = false.");
        }

        try {
            if ($watermark !== null) {
                $watermark = $this->converge($map, $backfiller, $watermark);
            }

            $install = $statements();
            $this->refuseTransaction($install);

            for ($attempt = 0; ; $attempt++) {
                try {
                    $this->connection->transaction(function () use ($map, $backfiller, $watermark, $install): void {
                        $this->connection->statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");
                        $this->connection->statement('LOCK TABLE '.SqlFragments::identifier($map->table).' IN SHARE ROW EXCLUSIVE MODE');

                        if ($watermark !== null) {
                            $backfiller->catchUp($watermark);
                        }

                        $this->apply($install);
                    });

                    break;
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== self::LOCK_NOT_AVAILABLE || $attempt === self::RETRIES) {
                        throw $exception;
                    }
                }
            }

            foreach ($install->afterwards as $statement) {
                $this->connection->unprepared($statement);
            }
        } finally {
            if ($watermark !== null) {
                $backfiller->untrack();
            }
        }
    }

    /**
     * @param  array{key: null|int|string, updatedAt: string}  $watermark
     * @return array{key: null|int|string, updatedAt: string}
     */
    private function converge(ResolvedMap $map, Backfiller $backfiller, array $watermark): array
    {
        $backfiller->track();

        for ($pass = 1; ; $pass++) {
            [$changed, $watermark] = $backfiller->catchUp($watermark);
            $backfiller->remember($watermark);

            if ($changed < self::THRESHOLD) {
                return $watermark;
            }

            if ($pass === self::PASSES) {
                throw InvalidCompatibilityMap::cannot($map->table, 'install', "its catch-up pass {$pass} still changed {$changed} successor rows, and install() takes the lock only once a pass changes fewer than ".self::THRESHOLD.'. Run php artisan compatibility:backfill '.$map->model." --since={$watermark['updatedAt']} again, then install()");
            }
        }
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

    private function refuseTransaction(Statements $statements): void
    {
        if ($statements->afterwards !== [] && $this->connection->transactionLevel() > 0) {
            throw new LogicException("Compatibility::{$statements->operation}() runs ".count($statements->afterwards).' statements after its own transaction commits, and the connection is already inside a transaction. Run it from a migration that sets public $withinTransaction = false.');
        }
    }
}
