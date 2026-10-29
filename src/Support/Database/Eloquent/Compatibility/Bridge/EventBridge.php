<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Events\NullDispatcher;
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

    /** @var WeakMap<Connection, true> */
    private WeakMap $watched;

    private int $internal = 0;

    private int $disabled = 0;

    private int $savepoints = 0;

    private int $depth = 0;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly CompatibilityMaps $compatibilityMaps,
    ) {
        $this->mirrorIndex = new MirrorIndex;
        $this->affectedRecords = new AffectedRecords($this->mirrorIndex);
        $this->eventReplayer = new EventReplayer($this->mirrorIndex);
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

            if (in_array(AsPivot::class, class_uses_recursive($model), true)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} uses ".AsPivot::class.', as every Pivot does, so its delete without its key loaded sends a statement HasCompatibility never marks, and the event bridge would refuse that delete only after it reached the database; map a plain Model over the pivot table instead');
            }

            if (! is_subclass_of($model, Compatible::class) || ! in_array(HasCompatibility::class, class_uses_recursive($model), true)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} does not implement ".Compatible::class.' and use '.HasCompatibility::class.", so the event bridge cannot tell the model's own statement from a listener's and would mirror none of its writes; implement the interface and use the trait in the model");
            }

            foreach (['setKeysForSaveQuery', 'getAttributesForInsert', 'fireModelEvent'] as $method) {
                $resolved = new ReflectionMethod($model, $method);
                $marking = new ReflectionMethod(HasCompatibility::class, $method);
                $consequence = $method === 'fireModelEvent' ? 'the event bridge never sees its model events and' : 'its own statement carries no mark and the event bridge';

                if ($resolved->getFileName() !== $marking->getFileName() || $resolved->getStartLine() !== $marking->getStartLine()) {
                    throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} resolves {$method}() to {$resolved->getDeclaringClass()->getName()}::{$method}(), not to the method of ".HasCompatibility::class.", so {$consequence} would mirror none of its writes; remove that declaration");
                }
            }
        }

        foreach ($resolvedMap->currentModels() as $model => $alias) {
            $primary = $alias === $resolvedMap->primaryCurrentModel->alias;

            foreach ($this->mirrorIndex->forModel($model) as $member) {
                $other = $this->mirrorIndex->resolvedMap($member->map);

                if ($primary ? $other->dependent($member->alias) !== null : $member->alias === $other->primaryCurrentModel->alias) {
                    [$primaryMap, $dependentMap] = $primary ? [$resolvedMap->name, $member->map] : [$member->map, $resolvedMap->name];

                    throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} is the primary current model of compatibility map '{$primaryMap}' and a dependent current model of compatibility map '{$dependentMap}', so the event bridge cannot lock its row in the order the triggers of both maps lock it, and a write through either view can deadlock a bridged write; map {$model} in one of those roles only");
                }
            }
        }

        $resolvedMap = $this->compatibilityMaps->register($map);

        if ($this->mirrored === []) {
            $this->events->listen(QueryExecuted::class, $this->handleQuery(...));
        }

        $this->mirrored[$resolvedMap->name] = true;
        $this->mirrorIndex->add($resolvedMap, $mirror);
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

    /**
     * @param  callable(): mixed  $fire
     */
    public function fireModelEvent(Model&Compatible $model, string $event, callable $fire): mixed
    {
        $this->eventReplayer->trackSave($model, $event);
        $modelEvent = ModelEvent::tryFrom($event);

        if ($modelEvent === null || ! $this->sees($model)) {
            return $this->eventReplayer->trackSaveResult($model, $event, $fire());
        }

        $connection = $model->getConnection();
        $operation = $modelEvent->operation();
        $pendingWrite = $operation === null ? null : $this->begin($connection, $model, $operation);
        $recorded = $pendingWrite === null ? 0 : $this->recorded($model, $modelEvent);

        if ($operation === null) {
            $this->finish($connection, $model, $modelEvent);
        }

        $this->depth++;

        try {
            $result = $fire();
        } catch (Throwable $e) {
            if ($pendingWrite !== null) {
                $this->forget($connection, $pendingWrite);
            }

            throw $e;
        } finally {
            $this->depth--;
        }

        if ($pendingWrite !== null && ($result === false || $this->recorded($model, $modelEvent) > $recorded)) {
            $this->forget($connection, $pendingWrite);
        }

        return $this->eventReplayer->trackSaveResult($model, $event, $result);
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
            $this->arm($connection, $pendingWrite, $connection->getQueryGrammar()->wrapTable($model->getTable()));
        } else {
            // Armed when the query runs: SoftDeletes::runSoftDelete() sets attributes after setKeysForSaveQuery().
            $query->beforeQuery(fn (Builder $query) => $this->arm($connection, $pendingWrite, $query->getGrammar()->wrapTable($query->from)));
        }
    }

    public function handleQuery(QueryExecuted $query): void
    {
        if ($this->internal > 0) {
            return;
        }

        $pendingWrite = $this->top($query->connectionName);

        if ($pendingWrite !== null && $pendingWrite->pending && $pendingWrite->statementSent && $pendingWrite->statement === $query->sql && $pendingWrite->bindings === $query->bindings) {
            $this->complete($query->connection, $pendingWrite);
        }
    }

    public function releaseFailed(): void
    {
        $this->armed = [];

        foreach ($this->pendingWrites as $name => $pendingWrites) {
            $pendingWrite = $pendingWrites[0] ?? null;

            if ($pendingWrite !== null) {
                $this->pendingWrites[$name] = array_values(array_filter($pendingWrites, fn (PendingMirroredWrite $open) => $open->pending && $open->statementSent));
                $this->finishFailed($pendingWrite->source->getConnection(), true);
            }
        }
    }

    private function sees(Model $model): bool
    {
        $dispatcher = Model::getEventDispatcher();

        return $this->disabled === 0
            && $dispatcher !== null
            && ! $dispatcher instanceof NullDispatcher
            && $this->mirrorIndex->forModel($model::class) !== [];
    }

    private function begin(Connection $connection, Model $model, Operation $operation): PendingMirroredWrite
    {
        $this->watch($connection);
        $this->finishFailed($connection);
        unset($this->armed[(string) $connection->getName()]);
        $pendingWrite = new PendingMirroredWrite($model, $operation, Model::getEventDispatcher(), $this->depth);
        $this->pendingWrites[$connection->getName()][] = $pendingWrite;

        return $pendingWrite;
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

    private function arm(Connection $connection, PendingMirroredWrite $pendingWrite, string $table): void
    {
        $name = (string) $connection->getName();

        if ($pendingWrite->pending && ! $pendingWrite->statementSent && in_array($pendingWrite, $this->pendingWrites[$name] ?? [], true)) {
            $pendingWrite->table = $table;
            $this->armed[$name] = $pendingWrite;
        }
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    private function open(Connection $connection, PendingMirroredWrite $pendingWrite, string $statement, array $bindings): void
    {
        $level = $connection->transactionLevel();
        $pendingWrite->statementSent = true;
        $pendingWrite->statement = $statement;
        $pendingWrite->bindings = $bindings;
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
        $connection->beforeStartingTransaction(function (Connection $connection): void {
            if ($this->internal === 0) {
                $this->finishFailed($connection);
            }
        });
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            if ($this->internal > 0) {
                return;
            }

            $name = (string) $connection->getName();
            $armed = $this->armed[$name] ?? null;
            $armed = $armed !== null && $this->writesTo($query, $armed) ? $armed : null;

            if ($armed !== null) {
                unset($this->armed[$name]);
            }

            while (($pendingWrite = $this->top($name)) !== null && $pendingWrite !== $armed) {
                if ($pendingWrite->pending && $pendingWrite->statementSent) {
                    if (! $this->closeFailed($connection, $pendingWrite, false)) {
                        break;
                    }
                } elseif ($armed !== null) {
                    $this->forget($connection, $pendingWrite);
                } else {
                    return;
                }
            }

            if ($armed !== null && $this->top($name) === $armed) {
                foreach ($this->pendingWrites[$name] ?? [] as $stale) {
                    if ($stale !== $armed && $stale->source === $armed->source && $stale->operation === $armed->operation && $stale->depth === $armed->depth && ! $stale->claimed && ! $stale->statementSent) {
                        $this->forget($connection, $stale);
                    }
                }

                $this->open($connection, $armed, $query, $bindings);
            }
        });
    }

    private function writesTo(string $query, PendingMirroredWrite $armed): bool
    {
        return $armed->table !== null && array_any(['update ', 'insert into ', 'delete from '], fn (string $keyword) => str_starts_with($query, $keyword.$armed->table.' '));
    }

    private function complete(Connection $connection, PendingMirroredWrite $pendingWrite): void
    {
        $pendingWrite->pending = false;

        try {
            $entries = $pendingWrite->snapshotsBefore;

            foreach ($this->internally(fn () => $this->affectedRecords->of($connection, $pendingWrite->source, $pendingWrite->operation, true)) as $id => $record) {
                $entries[$id] ??= new RecordSnapshot($record, null);
            }

            $records = array_map(fn (RecordSnapshot $entry) => $entry->record, $entries);
            $after = $this->internally(fn () => $this->affectedRecords->snapshots($connection, $records));

            $replay = function () use ($entries, $records, $after, $pendingWrite, $connection): void {
                [$entries, $after] = $this->transitions($connection, $pendingWrite, $entries, $records, $after);

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

    /**
     * @param  array<string, RecordSnapshot>  $entries
     * @param  array<string, AffectedRecord>  $records
     * @param  array<string, RecordSnapshot>  $after
     * @return array{array<string, RecordSnapshot>, array<string, RecordSnapshot>}
     */
    private function transitions(Connection $connection, PendingMirroredWrite $pendingWrite, array $entries, array $records, array $after): array
    {
        $states = [];

        foreach ($entries as $id => $entry) {
            $changed = $entry->record->role === RecordRole::Previous ? [] : $this->eventReplayer->states($entry->record, $entry->row, $after[$id]->row ?? null, (string) $connection->getName());

            if ($changed !== []) {
                $states[$id] = $changed;
            }
        }

        if ($states === []) {
            return [$entries, $after];
        }

        $native = $this->internally(fn () => $this->affectedRecords->native($connection, $pendingWrite->source));
        $natives = $native === null ? [] : [$native->id() => $native];
        $caller = $this->internally(fn () => $this->affectedRecords->snapshots($connection, $natives));

        foreach ($states as $id => $changed) {
            $this->eventReplayer->transition($entries[$id]->record, (array) $entries[$id]->row, (array) $after[$id]->row, (string) $connection->getName(), $changed);
        }

        $replayed = $this->internally(fn () => $this->affectedRecords->snapshots($connection, [...$records, ...$natives]));

        foreach ($entries as $id => $entry) {
            $entries[$id] = $entry->withRow($this->replayedBefore($connection, $entry->record, $entry->row, $after[$id]->row ?? null, $replayed[$id]->row));
        }

        if ($native !== null) {
            $this->copy($native, $pendingWrite->source, $caller[$native->id()]->row, $replayed[$native->id()]->row);
        }

        $replayed = array_diff_key($replayed, $natives);

        foreach ($replayed as $id => $snapshot) {
            if ($snapshot->row === null) {
                $replayed[$id] = $after[$id] ?? $snapshot;
            }
        }

        return [$entries, $replayed];
    }

    /**
     * @param  null|array<string, mixed>  $before
     * @param  null|array<string, mixed>  $after
     * @param  null|array<string, mixed>  $replayed
     * @return null|array<string, mixed>
     */
    private function replayedBefore(Connection $connection, AffectedRecord $record, null|array $before, null|array $after, null|array $replayed): null|array
    {
        if ($before === null || $after === null || $replayed === null) {
            return $before;
        }

        $class = $record->model;
        $changed = $this->eventReplayer->changed($record, (new $class)->setConnection((string) $connection->getName()), $after, $replayed);

        return [...$before, ...array_intersect_key($replayed, array_flip($changed))];
    }

    /**
     * @param  null|array<string, mixed>  $before
     * @param  null|array<string, mixed>  $after
     */
    private function copy(AffectedRecord $record, Model $model, null|array $before, null|array $after): void
    {
        if ($before === null || $after === null) {
            return;
        }

        $changes = array_intersect_key($after, array_flip($this->eventReplayer->changed($record, $model, $before, $after)));

        if ($changes === []) {
            return;
        }

        (function () use ($changes): void {
            $this->attributes = [...$this->attributes, ...$changes];
            $this->original = [...$this->original, ...$changes];
            $this->classCastCache = array_diff_key($this->classCastCache, $changes);
            $this->attributeCastCache = array_diff_key($this->attributeCastCache, $changes);
        })->call($model);
    }

    private function finish(Connection $connection, Model $model, ModelEvent $event): void
    {
        if (! in_array($event, [ModelEvent::Created, ...ModelEvent::finishing()], true)) {
            return;
        }

        $operation = match ($event) {
            ModelEvent::Created => Operation::Insert,
            ModelEvent::Updated => Operation::Update,
            ModelEvent::Deleted => Operation::Delete,
            default => null,
        };

        foreach ($this->pendingWrites[(string) $connection->getName()] ?? [] as $pendingWrite) {
            if ($pendingWrite->source !== $model || $pendingWrite->depth !== $this->depth || ($operation !== null && $pendingWrite->operation !== $operation) || ($operation === null && $pendingWrite->operation === Operation::Delete)) {
                continue;
            }

            if ($operation !== null && ! $pendingWrite->claimed) {
                $this->forget($connection, $pendingWrite);

                throw CompatibilityEventMirrorBypassed::byUnmarkedStatement($model::class, $event, $this->maps($model));
            }

            if ($pendingWrite->pending && $pendingWrite->statementSent) {
                $this->endTransaction($connection, $pendingWrite, true);
            }

            $this->forget($connection, $pendingWrite);
        }
    }

    private function finishFailed(Connection $connection, bool $releasing = false): void
    {
        while (($pendingWrite = $this->top($connection->getName())) !== null && $pendingWrite->pending && $pendingWrite->statementSent) {
            if (! $this->closeFailed($connection, $pendingWrite, $releasing)) {
                return;
            }
        }
    }

    private function closeFailed(Connection $connection, PendingMirroredWrite $pendingWrite, bool $releasing): bool
    {
        $level = $connection->transactionLevel();
        $held = $pendingWrite->savepoint === null ? $pendingWrite->transactionLevel + 1 : $pendingWrite->transactionLevel;

        if ($level < $held || ($releasing && $pendingWrite->savepoint !== null)) {
            $this->forget($connection, $pendingWrite);

            return true;
        }

        if ($pendingWrite->savepoint === null && $level > $held) {
            return false;
        }

        $healthy = $this->internally(function () use ($connection): bool {
            try {
                $connection->select('select 1');

                return true;
            } catch (Throwable) {
                return false;
            }
        });

        if ($healthy && ! $releasing) {
            return false;
        }

        if ($pendingWrite->savepoint === null) {
            $this->close($connection, $pendingWrite, $healthy);
        } else {
            $this->forget($connection, $pendingWrite);
        }

        return true;
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
            if ($pendingWrite->savepoint !== null) {
                $this->internally(function () use ($connection, $pendingWrite, $commit): void {
                    if (! $commit) {
                        $connection->unprepared('ROLLBACK TO SAVEPOINT '.$pendingWrite->savepoint);
                    }

                    $connection->unprepared('RELEASE SAVEPOINT '.$pendingWrite->savepoint);
                });
            } elseif ($commit) {
                $connection->commit();
            } else {
                $connection->rollBack($pendingWrite->transactionLevel);
            }
        } catch (Throwable $e) {
            try {
                if ($pendingWrite->savepoint === null && $connection->transactionLevel() > $pendingWrite->transactionLevel) {
                    $connection->rollBack($pendingWrite->transactionLevel);
                }
            } finally {
                $this->forget($connection, $pendingWrite);
            }

            throw $e;
        }
    }

    private function guard(Model $model): void
    {
        $maps = $this->maps($model);

        foreach ($maps === [] ? [] : ModelEvent::beginning() as $event) {
            if ($this->recorded($model, $event) > 0) {
                throw CompatibilityEventMirrorBypassed::byFakedDispatcher(Str::afterLast($model->getTable(), '.'), $maps);
            }
        }
    }

    private function recorded(Model $model, ModelEvent $event): int
    {
        $dispatcher = Model::getEventDispatcher();

        return $dispatcher instanceof EventFake ? $dispatcher->dispatched($event->for($model::class), fn (string $fired, mixed $payload) => $payload === $model)->count() : 0;
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
