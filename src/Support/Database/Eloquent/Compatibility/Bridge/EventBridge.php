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
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Throwable;
use WeakMap;

final class EventBridge
{
    private readonly MirrorIndex $mirrorIndex;

    private readonly AffectedRecords $affectedRecords;

    private readonly EventReplayer $eventReplayer;

    /** @var array<string, list<PendingMirroredWrite>> */
    private array $pendingWrites = [];

    /** @var array<string, true> */
    private array $mirrored = [];

    /** @var WeakMap<EventFake, array<string, int>> */
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
        $this->mirrorIndex = new MirrorIndex;
        $this->affectedRecords = new AffectedRecords($this->mirrorIndex);
        $this->eventReplayer = new EventReplayer($this->mirrorIndex);
        $this->faked = new WeakMap;
        $this->watched = new WeakMap;
    }

    public function mirror(EventMirror $mirror): void
    {
        $resolvedMap = $this->compatibilityMaps->register($mirror->compatibilityMap());

        if (array_key_exists($resolvedMap->name, $this->mirrored)) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, 'its event mirror is registered twice');
        }

        if ($this->mirrored === []) {
            $this->events->listen(QueryExecuted::class, $this->handleQuery(...));
        }

        $this->mirrored[$resolvedMap->name] = true;

        foreach ($this->mirrorIndex->add($resolvedMap, $mirror) as $model) {
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

        $parsedWrite = ParsedWrite::of($query->sql);

        if ($parsedWrite === null) {
            return;
        }

        $pendingWrite = $this->top($query->connectionName);

        if ($pendingWrite !== null && $pendingWrite->pending && $pendingWrite->expects($parsedWrite)) {
            $this->complete($query->connection, $pendingWrite);

            return;
        }

        $this->guard($parsedWrite);
    }

    public function releaseFailed(): void
    {
        foreach ($this->pendingWrites as $pendingWrites) {
            $pendingWrite = $pendingWrites[0] ?? null;

            if ($pendingWrite !== null) {
                $this->finishFailed($pendingWrite->source->getConnection());
            }
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function listen(string $model): void
    {
        foreach (ModelEvent::beginning() as $event) {
            $name = $event->for($model);
            $operation = $event->operation();

            $this->events->listen($name.'*', function (string $fired, array $payload) use ($name, $operation): void {
                $target = $payload[0] ?? null;

                if ($fired === $name && $target instanceof Model && $operation !== null) {
                    $this->begin($target, $operation);
                }
            });
        }

        foreach (ModelEvent::finishing() as $event) {
            $this->events->listen($event->for($model), $this->finish(...));
        }
    }

    private function begin(Model $model, Operation $operation): void
    {
        if ($this->disabled > 0 || $this->eventReplayer->isReplaying($model)) {
            return;
        }

        $connection = $model->getConnection();
        $this->watch($connection);
        $this->finishFailed($connection);
        $level = $connection->transactionLevel();
        $pendingWrite = new PendingMirroredWrite($model, $operation, $level, $level > 0 ? 'compatibility_'.++$this->savepoints : null);

        $this->internally(function () use ($connection, $pendingWrite): void {
            if ($pendingWrite->savepoint === null) {
                $connection->beginTransaction();
            } else {
                $connection->unprepared('SAVEPOINT '.$pendingWrite->savepoint);
            }
        });

        try {
            $pendingWrite->snapshotsBefore = $this->internally(fn () => $this->affectedRecords->before($connection, $model, $operation));
        } catch (Throwable $e) {
            $this->close($connection, $pendingWrite, false);

            throw $e;
        }

        $this->pendingWrites[$connection->getName()][] = $pendingWrite;
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

            $pendingWrite = $this->top($connection->getName());

            if ($pendingWrite === null || ! $pendingWrite->pending || $pendingWrite->savepoint !== null) {
                return;
            }

            if ($pendingWrite->statementSent) {
                $this->close($connection, $pendingWrite, false);

                return;
            }

            $parsedWrite = ParsedWrite::of($query);
            $pendingWrite->statementSent = $parsedWrite !== null && $pendingWrite->expects($parsedWrite);
        });
    }

    private function complete(Connection $connection, PendingMirroredWrite $pendingWrite): void
    {
        $pendingWrite->pending = false;

        try {
            $entries = $pendingWrite->snapshotsBefore;

            foreach ($this->internally(fn () => $this->affectedRecords->of($connection, $pendingWrite->source, $pendingWrite->operation, true)) as $id => $record) {
                $entries[$id] ??= new RecordSnapshot($record, null);
            }

            $after = $this->internally(fn () => $this->affectedRecords->snapshots(
                $connection,
                array_map(fn (RecordSnapshot $entry) => $entry->record, $entries),
            ));

            $replay = function () use ($entries, $after, $pendingWrite, $connection): void {
                foreach ($entries as $id => $entry) {
                    $this->eventReplayer->replay($entry->record, $entry->row, $after[$id]->row ?? null, $pendingWrite->source, (string) $connection->getName());
                }
            };

            if ($pendingWrite->savepoint === null) {
                $replay();
            } else {
                $connection->transaction($replay);
            }
        } catch (Throwable $e) {
            $this->close($connection, $pendingWrite, false);

            throw $e;
        }

        $this->close($connection, $pendingWrite, true);
    }

    private function finish(Model $model): void
    {
        $connection = $model->getConnection();
        $pendingWrite = $this->top($connection->getName());

        if ($pendingWrite !== null && $pendingWrite->pending && $pendingWrite->source === $model) {
            $this->close($connection, $pendingWrite, true);
        }
    }

    private function finishFailed(Connection $connection): void
    {
        while (($pendingWrite = $this->top($connection->getName())) !== null && $pendingWrite->pending) {
            if ($pendingWrite->savepoint !== null) {
                $this->forget($connection, $pendingWrite);

                continue;
            }

            if ($connection->transactionLevel() !== $pendingWrite->transactionLevel + 1) {
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

            $this->close($connection, $pendingWrite, $healthy);
        }
    }

    private function forget(Connection $connection, PendingMirroredWrite $pendingWrite): void
    {
        $name = (string) $connection->getName();
        $this->pendingWrites[$name] = array_values(array_filter($this->pendingWrites[$name] ?? [], fn (PendingMirroredWrite $open) => $open !== $pendingWrite));
    }

    private function close(Connection $connection, PendingMirroredWrite $pendingWrite, bool $commit): void
    {
        $this->forget($connection, $pendingWrite);

        $this->internally(function () use ($connection, $pendingWrite, $commit): void {
            if ($pendingWrite->savepoint !== null) {
                if (! $commit) {
                    $connection->unprepared('ROLLBACK TO SAVEPOINT '.$pendingWrite->savepoint);
                }

                $connection->unprepared('RELEASE SAVEPOINT '.$pendingWrite->savepoint);

                return;
            }

            if ($commit) {
                $connection->commit();
            } else {
                $connection->rollBack($pendingWrite->transactionLevel);
            }
        });
    }

    private function guard(ParsedWrite $parsedWrite): void
    {
        $models = $this->mirrorIndex->modelsOf($parsedWrite->relation);
        $dispatcher = Model::getEventDispatcher();

        if ($models === [] || ! $dispatcher instanceof EventFake) {
            return;
        }

        $count = 0;

        foreach ($models as $model) {
            foreach (ModelEvent::beginning() as $event) {
                $count += $dispatcher->dispatched($event->for($model))->count();
            }
        }

        $counts = $this->faked[$dispatcher] ?? [];
        $seen = $counts[$parsedWrite->relation] ?? 0;
        $counts[$parsedWrite->relation] = $count;
        $this->faked[$dispatcher] = $counts;

        if ($count > $seen && $this->disabled === 0) {
            throw CompatibilityEventMirrorBypassed::byFakedDispatcher($parsedWrite->relation, $this->mirrorIndex->mapsOf($parsedWrite->relation));
        }
    }

    private function top(null|string $connection): null|PendingMirroredWrite
    {
        $pendingWrites = $this->pendingWrites[(string) $connection] ?? [];

        return $pendingWrites === [] ? null : $pendingWrites[array_key_last($pendingWrites)];
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
