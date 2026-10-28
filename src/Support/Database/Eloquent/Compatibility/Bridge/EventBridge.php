<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\EventFake;
use ReflectionMethod;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityEventMirrorBypassed;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;
use Throwable;
use WeakMap;

final class EventBridge
{
    private const string PARENTAL = 'Tightenco\Parental\HasChildren';

    private readonly MirrorIndex $mirrorIndex;

    private readonly AffectedRecords $affectedRecords;

    private readonly EventReplayer $eventReplayer;

    /** @var array<string, list<PendingMirroredWrite>> */
    private array $pendingWrites = [];

    /** @var array<string, PendingMirroredWrite> */
    private array $armed = [];

    /** @var array<string, true> */
    private array $mirrored = [];

    /** @var WeakMap<Model, int> */
    private WeakMap $saving;

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
        $this->saving = new WeakMap;
        $this->watched = new WeakMap;
    }

    public function mirror(EventMirror $mirror): void
    {
        $map = $mirror->compatibilityMap();
        $resolvedMap = ResolvedMap::of($map);

        if (array_key_exists($resolvedMap->name, $this->mirrored)) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, 'its event mirror is registered twice');
        }

        foreach ([(string) $resolvedMap->previousModel->model, ...array_keys($resolvedMap->currentModels())] as $model) {
            if (in_array(self::PARENTAL, class_uses_recursive($model), true)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} uses ".self::PARENTAL.', so its child classes fire model events under their own names and the event bridge would mirror none of their writes; leave Parental models out of compatibility maps');
            }

            if (! is_subclass_of($model, Compatible::class) || ! in_array(HasCompatibility::class, class_uses_recursive($model), true)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} does not implement ".Compatible::class.' and use '.HasCompatibility::class.", so the event bridge cannot tell the model's own statement from a listener's and would mirror none of its writes; implement the interface and use the trait in the model");
            }

            foreach (['setKeysForSaveQuery', 'getAttributesForInsert'] as $method) {
                $resolved = new ReflectionMethod($model, $method);
                $marking = new ReflectionMethod(HasCompatibility::class, $method);

                if ($resolved->getFileName() !== $marking->getFileName() || $resolved->getStartLine() !== $marking->getStartLine()) {
                    throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} resolves {$method}() to {$resolved->getDeclaringClass()->getName()}::{$method}(), not to the method of ".HasCompatibility::class.', so its own statement carries no mark and the event bridge would mirror none of its writes; remove that declaration');
                }
            }
        }

        $resolvedMap = $this->compatibilityMaps->register($map);

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

    public function markOwnStatement(Model&Compatible $model, null|Builder $query = null): void
    {
        if ($this->disabled > 0) {
            return;
        }

        $connection = $model->getConnection();
        $pendingWrite = $this->unclaimed($connection, $model);

        if ($pendingWrite === null) {
            $this->guard($model);

            return;
        }

        $pendingWrite->claimed = true;

        if ($query === null) {
            $this->arm($connection, $pendingWrite);
        } else {
            // Armed when the query runs: SoftDeletes::runSoftDelete() sets attributes after setKeysForSaveQuery().
            $query->beforeQuery(fn () => $this->arm($connection, $pendingWrite));
        }
    }

    public function handleQuery(QueryExecuted $query): void
    {
        if ($this->internal > 0) {
            return;
        }

        $pendingWrite = $this->top($query->connectionName);

        if ($pendingWrite !== null && $pendingWrite->pending && $pendingWrite->statementSent) {
            $this->complete($query->connection, $pendingWrite);
        }
    }

    public function releaseFailed(): void
    {
        $this->armed = [];
        $this->saving = new WeakMap;

        foreach ($this->pendingWrites as $name => $pendingWrites) {
            $pendingWrite = $pendingWrites[0] ?? null;

            if ($pendingWrite !== null) {
                $this->pendingWrites[$name] = array_values(array_filter($pendingWrites, fn (PendingMirroredWrite $open) => $open->pending && $open->statementSent));
                $this->finishFailed($pendingWrite->source->getConnection());
            }
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function listen(string $model): void
    {
        $saving = ModelEvent::Saving->for($model);

        $this->events->listen($saving.'*', function (string $fired, array $payload) use ($saving): void {
            $target = $payload[0] ?? null;

            if ($fired === $saving && $target instanceof Model && $this->disabled === 0 && ! $this->eventReplayer->isReplaying($target)) {
                $this->saving[$target] = ($this->saving[$target] ?? 0) + 1;
            }
        });

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

        foreach ([ModelEvent::Created, ...ModelEvent::finishing()] as $event) {
            $this->events->listen($event->for($model), fn (Model $target) => $this->finish($target, $event));
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
        unset($this->armed[(string) $connection->getName()]);
        // Only a save fires saving first; an increment fires no saved, so its write must leave at updated.
        $saves = $operation !== Operation::Delete && $this->takeSaving($model);
        $lastEvent = match (true) {
            $operation === Operation::Delete => ModelEvent::Deleted,
            $operation === Operation::Insert || $saves => ModelEvent::Saved,
            default => ModelEvent::Updated,
        };
        $this->pendingWrites[$connection->getName()][] = new PendingMirroredWrite($model, $operation, Model::getEventDispatcher(), $lastEvent);
    }

    private function takeSaving(Model $model): bool
    {
        $saving = $this->saving[$model] ?? 0;

        if ($saving === 0) {
            return false;
        }

        $this->saving[$model] = $saving - 1;

        return true;
    }

    private function unclaimed(Connection $connection, Model $model): null|PendingMirroredWrite
    {
        $dispatcher = Model::getEventDispatcher();

        foreach (array_reverse($this->pendingWrites[(string) $connection->getName()] ?? []) as $pendingWrite) {
            if ($pendingWrite->source === $model && ! $pendingWrite->claimed && $pendingWrite->dispatcher === $dispatcher) {
                return $pendingWrite;
            }
        }

        return null;
    }

    private function arm(Connection $connection, PendingMirroredWrite $pendingWrite): void
    {
        $name = (string) $connection->getName();

        if ($pendingWrite->pending && ! $pendingWrite->statementSent && in_array($pendingWrite, $this->pendingWrites[$name] ?? [], true)) {
            $this->armed[$name] = $pendingWrite;
        }
    }

    private function open(Connection $connection, PendingMirroredWrite $pendingWrite): void
    {
        $level = $connection->transactionLevel();
        $pendingWrite->statementSent = true;
        $pendingWrite->transactionLevel = $level;
        $pendingWrite->savepoint = $level > 0 ? 'compatibility_'.++$this->savepoints : null;

        $this->internally(function () use ($connection, $pendingWrite): void {
            if ($pendingWrite->savepoint === null) {
                $connection->beginTransaction();
            } else {
                $connection->unprepared('SAVEPOINT '.$pendingWrite->savepoint);
            }
        });

        try {
            $pendingWrite->snapshotsBefore = $this->internally(fn () => $this->affectedRecords->before($connection, $pendingWrite->source, $pendingWrite->operation));
        } catch (Throwable $e) {
            $this->close($connection, $pendingWrite, false);

            throw $e;
        }
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

            $name = (string) $connection->getName();
            $armed = $this->writes($query) ? $this->armed[$name] ?? null : null;

            if ($armed !== null) {
                unset($this->armed[$name]);
            }

            while (($pendingWrite = $this->top($name)) !== null && $pendingWrite !== $armed) {
                if ($pendingWrite->pending && $pendingWrite->statementSent) {
                    if ($pendingWrite->savepoint === null) {
                        $this->close($connection, $pendingWrite, false);
                    } else {
                        $this->forget($connection, $pendingWrite);
                    }
                } elseif ($armed !== null) {
                    $this->forget($connection, $pendingWrite);
                } else {
                    return;
                }
            }

            if ($armed !== null && $this->top($name) === $armed) {
                $this->open($connection, $armed);
            }
        });
    }

    private function writes(string $query): bool
    {
        return preg_match('/^\s*(?:insert|update|delete)\b/i', $query) === 1;
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

        $this->endTransaction($connection, $pendingWrite, true);
    }

    private function finish(Model $model, ModelEvent $event): void
    {
        if ($this->disabled > 0) {
            return;
        }

        $connection = $model->getConnection();
        $pendingWrite = $this->paired($connection, $model, $event);

        if ($pendingWrite === null) {
            return;
        }

        if (! $pendingWrite->claimed && $event !== ModelEvent::Saved) {
            $this->forget($connection, $pendingWrite);

            throw CompatibilityEventMirrorBypassed::byUnmarkedStatement($model::class, $event, $this->maps($model));
        }

        if ($pendingWrite->pending && $pendingWrite->statementSent) {
            $this->endTransaction($connection, $pendingWrite, true);
        }

        if ($event === $pendingWrite->lastEvent) {
            $this->forget($connection, $pendingWrite);
        }
    }

    private function paired(Connection $connection, Model $model, ModelEvent $event): null|PendingMirroredWrite
    {
        foreach (array_reverse($this->pendingWrites[(string) $connection->getName()] ?? []) as $pendingWrite) {
            $completedBy = match ($pendingWrite->operation) {
                Operation::Insert => ModelEvent::Created,
                Operation::Update => ModelEvent::Updated,
                Operation::Delete => ModelEvent::Deleted,
            };

            if ($pendingWrite->source === $model && ($event === $pendingWrite->lastEvent || $event === $completedBy)) {
                return $pendingWrite;
            }
        }

        return null;
    }

    private function finishFailed(Connection $connection): void
    {
        while (($pendingWrite = $this->top($connection->getName())) !== null && $pendingWrite->pending && $pendingWrite->statementSent) {
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

        if (($this->armed[$name] ?? null) === $pendingWrite) {
            unset($this->armed[$name]);
        }
    }

    private function close(Connection $connection, PendingMirroredWrite $pendingWrite, bool $commit): void
    {
        $this->endTransaction($connection, $pendingWrite, $commit);
        $this->forget($connection, $pendingWrite);
    }

    private function endTransaction(Connection $connection, PendingMirroredWrite $pendingWrite, bool $commit): void
    {
        $pendingWrite->pending = false;

        try {
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
        } catch (Throwable $e) {
            try {
                if ($pendingWrite->savepoint === null && $connection->transactionLevel() > $pendingWrite->transactionLevel) {
                    $this->internally(fn () => $connection->rollBack($pendingWrite->transactionLevel));
                }
            } finally {
                $this->forget($connection, $pendingWrite);
            }

            throw $e;
        }
    }

    private function guard(Model $model): void
    {
        $dispatcher = Model::getEventDispatcher();
        $maps = $this->maps($model);

        if ($maps === [] || ! $dispatcher instanceof EventFake) {
            return;
        }

        foreach (ModelEvent::beginning() as $event) {
            if ($dispatcher->dispatched($event->for($model::class), fn (string $fired, mixed $payload) => $payload === $model)->isNotEmpty()) {
                throw CompatibilityEventMirrorBypassed::byFakedDispatcher(Str::afterLast($model->getTable(), '.'), $maps);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function maps(Model $model): array
    {
        return array_values(array_unique(array_map(fn (MapMember $member) => $member->map, $this->mirrorIndex->forModel($model::class))));
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
