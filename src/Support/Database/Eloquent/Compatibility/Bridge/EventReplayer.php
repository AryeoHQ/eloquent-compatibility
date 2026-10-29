<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use BackedEnum;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\BusFake;
use Illuminate\Support\Testing\Fakes\EventFake;
use InvalidArgumentException;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Support\Database\Eloquent\Compatibility\Bridge\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityEventMirrorBypassed;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\Assignment;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Exceptions\Invalid;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Transition;
use Support\Database\Eloquent\StateMachines\Contracts\StateMachineable;
use Support\Database\Eloquent\StateMachines\Triggers\Exceptions\Duplicate;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Throwable;
use TypeError;
use WeakMap;

final class EventReplayer
{
    private const array NUMBER_CASTS = ['int', 'integer', 'real', 'float', 'double', 'decimal'];

    private const array NUMBER_TYPES = ['smallint', 'integer', 'bigint', 'real', 'double precision'];

    private const array JSON_CASTS = ['array', 'json', 'json:unicode', 'object', 'collection'];

    private const array ENCRYPTED_CASTS = ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object'];

    private const string SAVING = 'saving';

    private const string WRITING = 'writing';

    private const string WRITTEN = 'written';

    private const string OWN_WRITE = 'own write';

    private const string OTHER_WRITE = 'other write';

    /** @var list<ModelEvent> */
    private array $fired = [];

    /** @var WeakMap<object, Exclusion> */
    private WeakMap $excluded;

    /** @var WeakMap<Model, list<ExcludedListener>> */
    private WeakMap $transitioning;

    /** @var WeakMap<Model, list<string>> */
    private WeakMap $openSaves;

    /** @var WeakMap<Model, bool> */
    private WeakMap $saves;

    public function __construct(private readonly MirrorIndex $mirrorIndex)
    {
        $this->excluded = new WeakMap;
        $this->transitioning = new WeakMap;
        $this->openSaves = new WeakMap;
        $this->saves = new WeakMap;
    }

    /**
     * @param  null|array<string, mixed>  $rowBefore
     * @param  null|array<string, mixed>  $rowAfter
     */
    public function replay(AffectedRecord $record, null|array $rowBefore, null|array $rowAfter, Model $source, string $connectionName): void
    {
        $class = $record->model;
        $template = new $class;
        $deletedAt = method_exists($template, 'getDeletedAtColumn') ? (string) $template->getDeletedAtColumn() : null;
        $deletedBefore = $deletedAt === null ? null : ($rowBefore[$deletedAt] ?? null);
        $deletedAfter = $deletedAt === null ? null : ($rowAfter[$deletedAt] ?? null);

        if ($rowBefore === $rowAfter || ($rowBefore === null && $rowAfter === null)) {
            return;
        }

        $model = $template->newInstance([], $rowBefore !== null);
        $model->setConnection($connectionName);
        $outer = $this->fired;
        $this->fired = [];

        try {
            match (true) {
                $rowBefore === null => $this->created($record, $model, (array) $rowAfter),
                $rowAfter === null => $this->deleted($record, $model, $rowBefore, $deletedAt !== null),
                $deletedBefore === null && $deletedAfter !== null => $this->trashed($record, $model, $rowBefore, $rowAfter),
                $deletedBefore !== null && $deletedAfter === null => $this->restored($record, $model, $rowBefore, $rowAfter),
                default => $this->updated($record, $model, $rowBefore, $rowAfter),
            };

            event(new RecordMirrored($record->map, $source, $model, $this->fired, $rowBefore, $rowAfter));
        } finally {
            $this->fired = $outer;
        }
    }

    /**
     * @param  null|array<string, mixed>  $rowBefore
     * @param  null|array<string, mixed>  $rowAfter
     * @return list<string>
     */
    public function states(AffectedRecord $record, null|array $rowBefore, null|array $rowAfter, string $connectionName): array
    {
        if ($rowBefore === null || $rowAfter === null || ! method_exists(Trigger::class, 'withoutTransition')) { // @phpstan-ignore function.alreadyNarrowedType
            return [];
        }

        $class = $record->model;
        $model = (new $class)->setConnection($connectionName);
        $casts = array_filter($model->getCasts(), fn (mixed $cast) => is_string($cast) && is_a($cast, StateMachineable::class, true));
        $changed = $this->changed($record, $model, array_intersect_key($rowBefore, $casts), array_intersect_key($rowAfter, $casts));

        return array_values(array_filter(array_map(strval(...), array_keys($casts)), fn (string $column) => in_array($column, $changed, true)));
    }

    /**
     * @param  array<string, mixed>  $rowBefore
     * @param  array<string, mixed>  $rowAfter
     * @param  list<string>  $states
     */
    public function transition(AffectedRecord $record, array $rowBefore, array $rowAfter, string $connectionName, array $states): void
    {
        $class = $record->model;
        $template = (new $class)->setConnection($connectionName);
        $excludedListeners = $this->mirrorIndex->excludedListeners($record->map);
        $unmask = $this->maskModelEvents($template, $excludedListeners);

        try {
            foreach ($states as $state) {
                $model = $template->newInstance([], true);
                $model->setRawAttributes([...($this->read($record, $template) ?? $rowAfter), $state => $rowBefore[$state] ?? null], true);
                $trigger = $this->trigger($record, $model, $state, $rowBefore[$state] ?? null, $rowAfter[$state] ?? null);
                $this->transitioning[$model] = $excludedListeners;

                try {
                    $pendingTrigger = $model->getAttribute($state)->{$trigger}();

                    if ($this->fakes(Container::getInstance()->make(BusDispatcher::class), $pendingTrigger)) {
                        throw CompatibilityEventMirrorBypassed::byFakedBus($pendingTrigger::class, [$record->map]);
                    }

                    $pendingTrigger->withoutTransition()->now();
                    $saved = $this->saves[$model] ?? false;
                } catch (Invalid|BindingResolutionException $e) {
                    throw $this->refusal($record, $model, $state, $e);
                } finally {
                    unset($this->transitioning[$model], $this->openSaves[$model], $this->saves[$model]);
                }

                if (! $saved) {
                    throw $this->refusal($record, $model, $state, new RuntimeException("The trigger's save of ".class_basename($model).' was vetoed, as a saving or updating listener that returns false does.'));
                }
            }
        } finally {
            $unmask();
        }
    }

    public function trackSave(Model $model, string $event): void
    {
        if (! $this->transitioning->offsetExists($model)) {
            return;
        }

        $frames = $this->openSaves[$model] ?? [];
        $top = array_key_last($frames);
        $writing = $top !== null && $frames[$top] === self::WRITING;

        if ($event === ModelEvent::Saving->value) {
            $frames[] = self::SAVING;
        } elseif (in_array($event, [ModelEvent::Creating->value, ModelEvent::Updating->value], true)) {
            if ($writing) {
                $frames[$top] = self::WRITTEN;
            }

            $frames[] = $writing ? self::OWN_WRITE : self::OTHER_WRITE;
        } elseif ($event === ModelEvent::Saved->value && $writing) {
            $frames[$top] = self::WRITTEN;
        }

        $this->openSaves[$model] = $frames;
    }

    public function trackSaveResult(Model $model, string $event, mixed $result): mixed
    {
        if (! $this->transitioning->offsetExists($model)) {
            return $result;
        }

        $frames = $this->openSaves[$model] ?? [];
        $top = array_key_last($frames);
        $phase = $top === null ? null : $frames[$top];

        if ($event === ModelEvent::Saving->value && $phase === self::SAVING) {
            if ($result === false) {
                $this->closeSave($model, $frames, false);
            } else {
                $frames[$top] = self::WRITING;
                $this->openSaves[$model] = $frames;
            }
        } elseif (in_array($event, [ModelEvent::Creating->value, ModelEvent::Updating->value], true) && in_array($phase, [self::OWN_WRITE, self::OTHER_WRITE], true)) {
            array_pop($frames);

            if ($phase === self::OWN_WRITE && $result === false) {
                $this->closeSave($model, $frames, false);
            } else {
                $this->openSaves[$model] = $frames;
            }
        } elseif ($event === ModelEvent::Saved->value && $phase === self::WRITTEN) {
            $this->closeSave($model, $frames, true);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public function changed(AffectedRecord $record, Model $model, array $before, array $after): array
    {
        $changed = [];
        $columnCatalog = null;

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $column) {
            $column = (string) $column;

            if (($before[$column] ?? null) === ($after[$column] ?? null)) {
                continue;
            }

            $columnCatalog ??= new ColumnCatalog($model->getConnection());

            if (! $this->equivalent($model, $column, $columnCatalog->type($record->readFrom, $column), $before[$column] ?? null, $after[$column] ?? null)) {
                $changed[] = $column;
            }
        }

        return $changed;
    }

    /**
     * @param  list<string>  $frames
     */
    private function closeSave(Model $model, array $frames, bool $completed): void
    {
        array_pop($frames);
        $this->openSaves[$model] = $frames;

        if (array_intersect($frames, [self::SAVING, self::WRITING, self::WRITTEN]) === []) {
            $this->saves[$model] = $completed;
        }
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function created(AffectedRecord $record, Model $model, array $after): void
    {
        $model->setRawAttributes($after);

        if ($model->getIncrementing()) {
            $model->offsetUnset($model->getKeyName());
        }

        $this->ing($record, $model, ModelEvent::Saving);
        $this->ing($record, $model, ModelEvent::Creating);
        $model->setAttribute($model->getKeyName(), $after[$model->getKeyName()] ?? $model->getKey());
        $model->exists = true;
        $model->wasRecentlyCreated = true;
        $this->ed($record, $model, ModelEvent::Created);
        $this->ed($record, $model, ModelEvent::Saved);
        $model->syncOriginal();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function updated(AffectedRecord $record, Model $model, array $before, array $after): void
    {
        $model->setRawAttributes($before, true);
        $model->setRawAttributes($after);
        $this->ing($record, $model, ModelEvent::Saving);
        $this->ing($record, $model, ModelEvent::Updating);
        $model->syncChanges();
        $this->ed($record, $model, ModelEvent::Updated);
        $this->ed($record, $model, ModelEvent::Saved);
        $model->syncOriginal();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function trashed(AffectedRecord $record, Model $model, array $before, array $after): void
    {
        $model->setRawAttributes($before, true);
        $this->ing($record, $model, ModelEvent::Deleting);
        $model->setRawAttributes($after, true);
        $this->ed($record, $model, ModelEvent::Trashed);
        $this->ed($record, $model, ModelEvent::Deleted);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function restored(AffectedRecord $record, Model $model, array $before, array $after): void
    {
        $model->setRawAttributes($before, true);
        $this->ing($record, $model, ModelEvent::Restoring);
        $model->setRawAttributes($after);
        $this->ing($record, $model, ModelEvent::Saving);
        $this->ing($record, $model, ModelEvent::Updating);
        $model->syncChanges();
        $this->ed($record, $model, ModelEvent::Updated);
        $this->ed($record, $model, ModelEvent::Saved);
        $model->syncOriginal();
        $this->ed($record, $model, ModelEvent::Restored);
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function deleted(AffectedRecord $record, Model $model, array $before, bool $softDeletes): void
    {
        $model->setRawAttributes($before, true);

        if ($softDeletes) {
            $this->ing($record, $model, ModelEvent::ForceDeleting);
            $this->forceDeleting($model, true);
        }

        $this->ing($record, $model, ModelEvent::Deleting);
        $model->exists = false;
        $this->ed($record, $model, ModelEvent::Deleted);

        if ($softDeletes) {
            $this->forceDeleting($model, false);
            $this->ed($record, $model, ModelEvent::ForceDeleted);
        }
    }

    private function forceDeleting(Model $model, bool $forceDeleting): void
    {
        if (property_exists($model, 'forceDeleting')) {
            new ReflectionProperty($model, 'forceDeleting')->setValue($model, $forceDeleting);
        }
    }

    private function trigger(AffectedRecord $record, Model $model, string $state, mixed $before, mixed $after): string
    {
        $from = $this->state($model, $state, $before);
        $to = $this->state($model, $state, $after);

        if (! $from instanceof StateMachineable || ! $to instanceof StateMachineable) {
            $cast = (string) ($model->getCasts()[$state] ?? '');

            throw $this->refusal($record, $model, $state, new InvalidArgumentException(
                '['.var_export($to instanceof StateMachineable ? $before : $after, true).'] is not a case of ['.class_basename($cast).'].',
            ));
        }

        $transitions = $from->transitions()->filter(fn (Transition $transition) => $transition->to === $to);

        if ($transitions->count() !== 1) {
            throw $this->refusal($record, $model, $state, $transitions->isEmpty() ? new Invalid($model, $to, $state) : new Duplicate($to->name, $from));
        }

        return Str::camel(class_basename($transitions->sole()->using));
    }

    private function state(Model $model, string $state, mixed $value): null|BackedEnum
    {
        $cast = $model->getCasts()[$state] ?? null;

        if (! is_string($cast) || ! is_a($cast, BackedEnum::class, true) || (! is_int($value) && ! is_string($value))) {
            return null;
        }

        try {
            return $cast::tryFrom($value);
        } catch (TypeError) {
            return null;
        }
    }

    private function refusal(AffectedRecord $record, Model $model, string $state, Throwable $previous): CompatibilityWriteRefused
    {
        $resolvedMap = $this->mirrorIndex->resolvedMap($record->map);
        $alias = $resolvedMap->currentModels()[$record->model] ?? null;
        $assignment = $alias === null ? null : array_find($resolvedMap->assignmentsTo($alias), fn (Assignment $assignment) => $assignment->targetColumn->column === $state);

        return CompatibilityWriteRefused::byTransition($record->map, $model, $state, (string) $resolvedMap->previousModel->model, $assignment->attribute->column ?? $state, $previous);
    }

    /**
     * @return null|array<string, mixed>
     */
    private function read(AffectedRecord $record, Model $model): null|array
    {
        $row = $model->getConnection()->selectOne(
            'select * from '.SqlFragments::identifier($record->readFrom).' where '.SqlFragments::identifier($record->keyColumn).' = ? limit 1',
            [$record->keyValue],
        );

        return $row === null ? null : (array) $row;
    }

    private function fakes(BusDispatcher $bus, object $trigger): bool
    {
        return $bus instanceof BusFake && (fn (object $command): bool => $this->shouldFakeJob($command))->call($bus, $trigger);
    }

    /**
     * @param  list<ExcludedListener>  $excludedListeners
     * @return Closure(): void
     */
    private function maskModelEvents(Model $model, array $excludedListeners): Closure
    {
        $dispatcher = $model::getEventDispatcher();

        if ($dispatcher === null || $excludedListeners === []) {
            return static function (): void {};
        }

        $real = $dispatcher instanceof EventFake ? $dispatcher->dispatcher : $dispatcher;
        $masked = [];
        $maskedWildcards = [];

        foreach (ModelEvent::cases() as $event) {
            $applying = array_values(array_filter($excludedListeners, fn (ExcludedListener $excludedListener) => $excludedListener->appliesTo($event)));
            $custom = $model->dispatchesEvents()[$event->value] ?? null;

            foreach ($applying === [] ? [] : [$event->for($model::class), ...(is_string($custom) && class_exists($custom) ? [$custom] : [])] as $name) {
                $masked += $this->mask($real, $name, $applying, $event);
                $maskedWildcards += $this->maskWildcards($real, $name, $applying, $event);
            }
        }

        return function () use ($real, $masked, $maskedWildcards): void {
            $this->unmask($real, $masked);
            $this->unmaskWildcards($real, $maskedWildcards);
        };
    }

    private function ing(AffectedRecord $record, Model $model, ModelEvent $event): void
    {
        $attributes = $model->getAttributes();

        if ($this->fire($record->map, $model, $event, true) === false) {
            throw CompatibilityWriteRefused::byMirroredListener($record->map, $model, $event);
        }

        $changed = $this->changed($record, $model, $attributes, $model->getAttributes());

        if ($changed !== []) {
            throw CompatibilityWriteRefused::byMirroredChange($record->map, $model, $event, $changed);
        }
    }

    private function ed(AffectedRecord $record, Model $model, ModelEvent $event): void
    {
        $this->fire($record->map, $model, $event, false);
    }

    private function fire(string $map, Model $model, ModelEvent $event, bool $halt): mixed
    {
        $this->fired[] = $event;
        $dispatcher = $model::getEventDispatcher();

        if ($dispatcher === null) {
            return null;
        }

        $excludedListeners = array_values(array_filter($this->mirrorIndex->excludedListeners($map), fn (ExcludedListener $excludedListener) => $excludedListener->appliesTo($event)));
        $custom = $model->dispatchesEvents()[$event->value] ?? null;

        if (is_string($custom) && class_exists($custom)) {
            $result = $this->dispatch($dispatcher, new $custom($model), $model, $halt, $excludedListeners, $event);

            if (is_array($result)) {
                $result = array_filter($result, fn (mixed $response) => $response !== null);
            }

            if ($result === false) {
                return false;
            }

            if ($result !== null && $result !== []) {
                return $result;
            }
        }

        return $this->dispatch($dispatcher, $event->for($model::class), $model, $halt, $excludedListeners, $event);
    }

    /**
     * @param  list<ExcludedListener>  $excludedListeners
     */
    private function dispatch(Dispatcher $dispatcher, object|string $event, Model $model, bool $halt, array $excludedListeners, ModelEvent $modelEvent): mixed
    {
        $payload = is_object($event) ? [] : [$model];

        if ($excludedListeners === []) {
            return $halt ? $dispatcher->until($event, $payload) : $dispatcher->dispatch($event, $payload);
        }

        $real = $dispatcher instanceof EventFake ? $dispatcher->dispatcher : $dispatcher;
        $subject = is_object($event) ? $event : $model;
        $name = is_object($event) ? $event::class : $event;
        $masked = $this->mask($real, $name, $excludedListeners, $modelEvent);
        $maskedWildcards = $this->maskWildcards($real, $name, $excludedListeners, $modelEvent);
        $this->excluded[$subject] = new Exclusion($modelEvent, $excludedListeners);

        try {
            return $halt ? $dispatcher->until($event, $payload) : $dispatcher->dispatch($event, $payload);
        } finally {
            unset($this->excluded[$subject]);
            $this->unmask($real, $masked);
            $this->unmaskWildcards($real, $maskedWildcards);
        }
    }

    /**
     * @param  list<ExcludedListener>  $excludedListeners
     * @return array<string, list<mixed>>
     */
    private function mask(Dispatcher $events, string $name, array $excludedListeners, ModelEvent $modelEvent): array
    {
        $keys = [$name];

        if (class_exists($name, false)) {
            foreach (class_implements($name) as $interface) {
                $keys[] = $interface;
            }
        }

        $raw = $events->getRawListeners(); // @phpstan-ignore staticMethod.dynamicCall
        $masked = [];

        foreach ($keys as $key) {
            $listeners = array_values($raw[$key] ?? []);

            if (! array_any($listeners, fn (mixed $listener) => $this->excludes($excludedListeners, $modelEvent, $listener))) {
                continue;
            }

            $masked[$key] = $listeners;
            $events->forget($key);

            foreach ($listeners as $listener) {
                $events->listen($key, $this->guard($events, $key, $listener));
            }
        }

        return $masked;
    }

    /**
     * @param  array<string, list<mixed>>  $masked
     */
    private function unmask(Dispatcher $events, array $masked): void
    {
        foreach ($masked as $key => $listeners) {
            $events->forget($key);

            foreach ($listeners as $listener) {
                $events->listen($key, $listener);
            }
        }
    }

    /**
     * @param  list<ExcludedListener>  $excludedListeners
     * @return array<string, list<mixed>>
     */
    private function maskWildcards(Dispatcher $events, string $name, array $excludedListeners, ModelEvent $modelEvent): array
    {
        if (! $events instanceof EventDispatcher) {
            return [];
        }

        $wildcards = $this->wildcards($events);
        $masked = [];

        foreach ($wildcards as $pattern => $listeners) {
            if (Str::is($pattern, $name) && array_any($listeners, fn (mixed $listener) => $this->excludes($excludedListeners, $modelEvent, $listener))) {
                $masked[$pattern] = $listeners;
                $wildcards[$pattern] = array_map(fn (mixed $listener) => $this->guard($events, $pattern, $listener, true), $listeners);
            }
        }

        if ($masked !== []) {
            $this->setWildcards($events, $wildcards);
        }

        return $masked;
    }

    /**
     * @param  array<string, list<mixed>>  $masked
     */
    private function unmaskWildcards(Dispatcher $events, array $masked): void
    {
        if ($masked !== [] && $events instanceof EventDispatcher) {
            $this->setWildcards($events, [...$this->wildcards($events), ...$masked]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function wildcards(EventDispatcher $events): array
    {
        /** @var array<string, list<mixed>> */
        return new ReflectionProperty(EventDispatcher::class, 'wildcards')->getValue($events);
    }

    /**
     * @param  array<string, list<mixed>>  $wildcards
     */
    private function setWildcards(EventDispatcher $events, array $wildcards): void
    {
        new ReflectionProperty(EventDispatcher::class, 'wildcards')->setValue($events, $wildcards);
        new ReflectionProperty(EventDispatcher::class, 'wildcardsCache')->setValue($events, []);
    }

    private function guard(Dispatcher $events, string $key, mixed $listener, bool $wildcard = false): mixed
    {
        if ($this->identify($listener)[0] === null) {
            return $listener;
        }

        $prepared = $events->makeListener($listener, $wildcard); // @phpstan-ignore staticMethod.dynamicCall

        if ($wildcard) {
            return fn (string $event, array $payload): mixed => $this->skips($payload[0] ?? null, $listener, $event) ? null : $prepared($event, $payload);
        }

        return fn (mixed ...$payload): mixed => $this->skips($payload[0] ?? null, $listener, $key) ? null : $prepared($key, $payload);
    }

    private function skips(mixed $subject, mixed $listener, string $name): bool
    {
        $exclusion = is_object($subject) ? $this->excluded[$subject] ?? $this->transitioning($subject, $name) : null;

        return $exclusion !== null && $this->excludes($exclusion->listeners, $exclusion->event, $listener);
    }

    private function transitioning(object $subject, string $name): null|Exclusion
    {
        if (count($this->transitioning) === 0) {
            return null;
        }

        $model = $subject instanceof Model ? $subject : array_find((array) $subject, fn (mixed $value) => $value instanceof Model && $this->transitioning->offsetExists($value));

        if (! $model instanceof Model || ! $this->transitioning->offsetExists($model)) {
            return null;
        }

        $event = ModelEvent::tryFrom($subject instanceof Model ? Str::between($name, 'eloquent.', ': ') : (string) array_search($subject::class, $model->dispatchesEvents(), true));

        return $event === null ? null : new Exclusion($event, $this->transitioning[$model]);
    }

    /**
     * @param  list<ExcludedListener>  $excludedListeners
     */
    private function excludes(array $excludedListeners, ModelEvent $event, mixed $listener): bool
    {
        [$class, $method] = $this->identify($listener);

        return $class !== null && array_any($excludedListeners, fn (ExcludedListener $excludedListener) => $excludedListener->matches($event, $class, $method));
    }

    /**
     * @return array{0: null|string, 1: string}
     */
    private function identify(mixed $listener): array
    {
        if (is_string($listener)) {
            [$class, $method] = Str::parseCallback($listener, 'handle');

            return [$class, (string) $method];
        }

        if (is_array($listener) && count($listener) === 2 && is_string($listener[1] ?? null)) {
            $target = $listener[0] ?? null;
            $class = is_object($target) ? $target::class : (is_string($target) ? $target : null);

            return [$class, $listener[1]];
        }

        return [null, ''];
    }

    private function equivalent(Model $model, string $column, null|string $type, mixed $before, mixed $after): bool
    {
        if ($model->hasCast($column, self::NUMBER_CASTS) || in_array($type, self::NUMBER_TYPES, true) || str_starts_with((string) $type, 'numeric')) {
            if (! is_numeric($before) || ! is_numeric($after)) {
                return false;
            }

            $number = $this->number($before);

            return $number !== null && $number === $this->number($after);
        }

        if ($model->hasCast($column, self::JSON_CASTS) || in_array($type, ['json', 'jsonb'], true)) {
            if (! is_string($before) || ! is_string($after)) {
                return false;
            }

            $json = $this->json($before);

            return $json !== null && $json === $this->json($after);
        }

        if ($model->hasCast($column, self::ENCRYPTED_CASTS) || class_exists(Str::before((string) ($model->getCasts()[$column] ?? ''), ':'))) {
            $copy = $model->newInstance();
            $copy->setRawAttributes([$column => $before], true);
            $copy->setRawAttributes([$column => $after]);

            return $copy->originalIsEquivalent($column);
        }

        return false;
    }

    /**
     * @return null|array{0: mixed}
     */
    private function json(string $json): null|array
    {
        if (! json_validate($json)) {
            return null;
        }

        $exact = true;
        $typed = preg_replace_callback(
            '/"(?:[^"\\\\]++|\\\\.)*+"|-?\d++(?:\.\d++)?(?:[eE][+-]?\d++)?/',
            function (array $token) use (&$exact): string {
                if ($token[0][0] === '"') {
                    return '"s'.substr($token[0], 1);
                }

                $number = $this->number($token[0]);
                $exact = $exact && $number !== null;

                return '"n'.$number.'"';
            },
            $json,
        );

        return $exact && is_string($typed) ? [$this->tree(json_decode($typed))] : null;
    }

    private function tree(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = array_map($this->tree(...), get_object_vars($value));
            ksort($members, SORT_STRING);

            return ['object' => $members];
        }

        return is_array($value) ? ['array' => array_map($this->tree(...), $value)] : $value;
    }

    private function number(float|int|string $value): null|string
    {
        if (preg_match('/^\s*([+-]?)(\d*)(?:\.(\d*))?(?:e([+-]?\d{1,9}))?\s*$/i', is_float($value) ? var_export($value, true) : (string) $value, $parts) !== 1) {
            return null;
        }

        $digits = $parts[2].($parts[3] ?? '');
        $significant = ltrim($digits, '0');
        $exponent = (int) ($parts[4] ?? 0) + strlen($parts[2]) - strlen($digits) + strlen($significant);
        $significant = rtrim($significant, '0');

        return $significant === '' ? '0' : ($parts[1] === '-' ? '-' : '').$significant.'e'.$exponent;
    }
}
