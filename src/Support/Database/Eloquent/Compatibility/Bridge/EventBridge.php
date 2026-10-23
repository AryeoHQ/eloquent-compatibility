<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Testing\Fakes\EventFake;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityEventMirrorBypassed;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Throwable;
use WeakMap;

final class EventBridge
{
    private const array ARMING = ['creating' => 'insert', 'updating' => 'update', 'deleting' => 'delete'];

    private readonly MirroredCompatibilityMaps $mirroredMaps;

    private readonly Footprint $footprint;

    private readonly Replay $replay;

    /** @var array<string, list<Arm>> */
    private array $arms = [];

    /** @var array<string, true> */
    private array $mirrored = [];

    /** @var WeakMap<EventFake, int> */
    private WeakMap $faked;

    /** @var WeakMap<Connection, true> */
    private WeakMap $watched;

    private int $internal = 0;

    private int $disabled = 0;

    private int $savepoints = 0;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly CompatibilityMaps $compatibilityMaps,
    ) {
        $this->mirroredMaps = new MirroredCompatibilityMaps;
        $this->footprint = new Footprint($this->mirroredMaps);
        $this->replay = new Replay($this->mirroredMaps);
        $this->faked = new WeakMap;
        $this->watched = new WeakMap;
    }

    public function mirror(EventMirror $mirror): void
    {
        $declaration = $this->compatibilityMaps->register($mirror->compatibilityMap());

        if (array_key_exists($declaration->name, $this->mirrored)) {
            throw InvalidCompatibilityMap::because($declaration->name, 'its event mirror is registered twice');
        }

        if ($this->mirrored === []) {
            $this->events->listen(QueryExecuted::class, $this->handleQuery(...));
        }

        $this->mirrored[$declaration->name] = true;

        foreach ($this->mirroredMaps->add($declaration, $mirror) as $model) {
            $this->listen($model);
        }
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function withoutMirroring(callable $callback): mixed
    {
        $this->disabled++;

        try {
            return $callback();
        } finally {
            $this->disabled--;
        }
    }

    public function handleQuery(QueryExecuted $query): void
    {
        if ($this->internal > 0) {
            return;
        }

        $statement = Statement::write($query->sql);

        if ($statement === null) {
            return;
        }

        $arm = $this->top($query->connectionName);

        if ($arm !== null && $arm->pending && $arm->expects($statement)) {
            $this->complete($query->connection, $arm);

            return;
        }

        $this->guard($statement);
    }

    public function releaseAbandoned(): void
    {
        foreach ($this->arms as $arms) {
            $arm = $arms[0] ?? null;

            if ($arm !== null) {
                $this->settleAbandoned($arm->model->getConnection());
            }
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function listen(string $model): void
    {
        foreach (self::ARMING as $event => $operation) {
            $name = "eloquent.{$event}: {$model}";

            $this->events->listen($name.'*', function (string $fired, array $payload) use ($name, $operation): void {
                $target = $payload[0] ?? null;

                if ($fired === $name && $target instanceof Model) {
                    $this->arm($target, $operation);
                }
            });
        }

        foreach (['saved', 'updated', 'deleted'] as $event) {
            $this->events->listen("eloquent.{$event}: {$model}", $this->settle(...));
        }
    }

    private function arm(Model $model, string $operation): void
    {
        if ($this->disabled > 0 || $this->replay->isReplaying($model)) {
            return;
        }

        $connection = $model->getConnection();
        $this->watch($connection);
        $this->settleAbandoned($connection);
        $level = $connection->transactionLevel();
        $arm = new Arm($model, $operation, $level, $level > 0 ? 'compatibility_'.++$this->savepoints : null);

        $this->internally(function () use ($connection, $arm): void {
            if ($arm->savepoint === null) {
                $connection->beginTransaction();
            } else {
                $connection->unprepared('SAVEPOINT '.$arm->savepoint);
            }
        });

        try {
            $arm->before = $this->internally(fn () => $this->footprint->rows(
                $connection,
                $this->footprint->of($connection, $model, $operation, false),
                $this->footprint->native($model),
                true,
            ));
        } catch (Throwable $e) {
            $this->close($connection, $arm, false);

            throw $e;
        }

        $this->arms[$connection->getName()][] = $arm;
    }

    private function watch(Connection $connection): void
    {
        if ($this->watched[$connection] ?? false) {
            return;
        }

        $this->watched[$connection] = true;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            if ($this->internal > 0) {
                return;
            }

            $arm = $this->top($connection->getName());

            if ($arm === null || ! $arm->pending || $arm->savepoint !== null) {
                return;
            }

            if ($arm->started) {
                $this->close($connection, $arm, false);

                return;
            }

            $statement = Statement::write($query);
            $arm->started = $statement !== null && $arm->expects($statement);
        });
    }

    private function complete(Connection $connection, Arm $arm): void
    {
        $arm->pending = false;

        try {
            $entries = $arm->before;

            foreach ($this->internally(fn () => $this->footprint->of($connection, $arm->model, $arm->operation, true)) as $id => $record) {
                $entries[$id] ??= ['record' => $record, 'row' => null];
            }

            $after = $this->internally(fn () => $this->footprint->rows(
                $connection,
                array_map(fn (array $entry) => $entry['record'], $entries),
                null,
                false,
            ));

            $replay = function () use ($entries, $after, $arm, $connection): void {
                foreach ($entries as $id => $entry) {
                    $this->replay->record($entry['record'], $entry['row'], $after[$id]['row'] ?? null, $arm->model, (string) $connection->getName());
                }
            };

            if ($arm->savepoint === null) {
                $replay();
            } else {
                $connection->transaction($replay);
            }
        } catch (Throwable $e) {
            $this->close($connection, $arm, false);

            throw $e;
        }

        $this->close($connection, $arm, true);
    }

    private function settle(Model $model): void
    {
        $connection = $model->getConnection();
        $arm = $this->top($connection->getName());

        if ($arm !== null && $arm->pending && $arm->model === $model) {
            $this->close($connection, $arm, true);
        }
    }

    private function settleAbandoned(Connection $connection): void
    {
        while (($arm = $this->top($connection->getName())) !== null && $arm->pending) {
            if ($arm->savepoint !== null) {
                $this->forget($connection, $arm);

                continue;
            }

            if ($connection->transactionLevel() !== $arm->level + 1) {
                return;
            }

            $healthy = $this->internally(function () use ($connection): bool {
                try {
                    $connection->select('select 1');

                    return true;
                } catch (Throwable) {
                    return false;
                }
            });

            $this->close($connection, $arm, $healthy);
        }
    }

    private function forget(Connection $connection, Arm $arm): void
    {
        $name = (string) $connection->getName();
        $this->arms[$name] = array_values(array_filter($this->arms[$name] ?? [], fn (Arm $open) => $open !== $arm));
    }

    private function close(Connection $connection, Arm $arm, bool $commit): void
    {
        $this->forget($connection, $arm);

        $this->internally(function () use ($connection, $arm, $commit): void {
            if ($arm->savepoint !== null) {
                if (! $commit) {
                    $connection->unprepared('ROLLBACK TO SAVEPOINT '.$arm->savepoint);
                }

                $connection->unprepared('RELEASE SAVEPOINT '.$arm->savepoint);

                return;
            }

            if ($commit) {
                $connection->commit();
            } else {
                $connection->rollBack($arm->level);
            }
        });
    }

    private function guard(Statement $statement): void
    {
        $models = $this->mirroredMaps->modelsOf($statement->relation);
        $dispatcher = Model::getEventDispatcher();

        if ($this->disabled > 0 || $models === [] || ! $dispatcher instanceof EventFake) {
            return;
        }

        $count = 0;

        foreach ($models as $model) {
            foreach (array_keys(self::ARMING) as $event) {
                $count += $dispatcher->dispatched("eloquent.{$event}: {$model}")->count();
            }
        }

        $seen = $this->faked[$dispatcher] ?? 0;
        $this->faked[$dispatcher] = $count;

        if ($count > $seen) {
            throw CompatibilityEventMirrorBypassed::byFakedDispatcher($statement->relation, $this->mirroredMaps->mapsOf($statement->relation));
        }
    }

    private function top(null|string $connection): null|Arm
    {
        $arms = $this->arms[(string) $connection] ?? [];

        return $arms === [] ? null : $arms[array_key_last($arms)];
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function internally(callable $callback): mixed
    {
        $this->internal++;

        try {
            return $callback();
        } finally {
            $this->internal--;
        }
    }
}
