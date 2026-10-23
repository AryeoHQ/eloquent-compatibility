<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\EventFake;
use Support\Database\Eloquent\Compatibility\Bridge\Events\Mirrored;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use WeakMap;

final class Replay
{
    /** @var WeakMap<Model, true> */
    private WeakMap $replaying;

    /** @var list<string> */
    private array $fired = [];

    /** @var WeakMap<object, array{0: string, 1: list<ExcludedListener>}> */
    private WeakMap $excluded;

    public function __construct(private readonly MirroredCompatibilityMaps $mirroredMaps)
    {
        $this->replaying = new WeakMap;
        $this->excluded = new WeakMap;
    }

    public function isReplaying(Model $model): bool
    {
        return $this->replaying->offsetExists($model);
    }

    /**
     * @param  null|array<string, mixed>  $before
     * @param  null|array<string, mixed>  $after
     */
    public function record(Record $record, null|array $before, null|array $after, Model $source, string $connection): void
    {
        $class = $record->model;
        $template = new $class;
        $deletedAt = method_exists($template, 'getDeletedAtColumn') ? (string) $template->getDeletedAtColumn() : null;
        $deletedBefore = $deletedAt === null ? null : ($before[$deletedAt] ?? null);
        $deletedAfter = $deletedAt === null ? null : ($after[$deletedAt] ?? null);

        if ($before === $after || ($before === null && $after === null)) {
            return;
        }

        $model = $template->newInstance([], $before !== null);
        $model->setConnection($connection);
        $outer = $this->fired;
        $this->fired = [];
        $this->replaying[$model] = true;

        try {
            match (true) {
                $before === null => $this->created($record, $model, (array) $after),
                $after === null => $this->deleted($record, $model, $before, $deletedAt !== null),
                $deletedBefore === null && $deletedAfter !== null => $this->trashed($record, $model, $before, $after),
                $deletedBefore !== null && $deletedAfter === null => $this->restored($record, $model, $before, $after),
                default => $this->updated($record, $model, $before, $after),
            };

            event(new Mirrored($record->map, $source, $model, $this->fired, $before, $after));
        } finally {
            unset($this->replaying[$model]);
            $this->fired = $outer;
        }
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function created(Record $record, Model $model, array $after): void
    {
        $model->setRawAttributes($after);

        if ($model->getIncrementing()) {
            $model->offsetUnset($model->getKeyName());
        }

        $this->ing($record, $model, 'saving');
        $this->ing($record, $model, 'creating');
        $model->setAttribute($model->getKeyName(), $after[$model->getKeyName()] ?? $model->getKey());
        $model->exists = true;
        $model->wasRecentlyCreated = true;
        $this->ed($record, $model, 'created');
        $this->ed($record, $model, 'saved');
        $model->syncOriginal();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function updated(Record $record, Model $model, array $before, array $after): void
    {
        $model->setRawAttributes($before, true);
        $model->setRawAttributes($after);
        $this->ing($record, $model, 'saving');
        $this->ing($record, $model, 'updating');
        $model->syncChanges();
        $this->ed($record, $model, 'updated');
        $this->ed($record, $model, 'saved');
        $model->syncOriginal();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function trashed(Record $record, Model $model, array $before, array $after): void
    {
        $model->setRawAttributes($before, true);
        $this->ing($record, $model, 'deleting');
        $model->setRawAttributes($after, true);
        $this->ed($record, $model, 'trashed');
        $this->ed($record, $model, 'deleted');
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function restored(Record $record, Model $model, array $before, array $after): void
    {
        $model->setRawAttributes($before, true);
        $this->ing($record, $model, 'restoring');
        $model->setRawAttributes($after);
        $this->ing($record, $model, 'saving');
        $this->ing($record, $model, 'updating');
        $model->syncChanges();
        $this->ed($record, $model, 'updated');
        $this->ed($record, $model, 'saved');
        $model->syncOriginal();
        $this->ed($record, $model, 'restored');
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function deleted(Record $record, Model $model, array $before, bool $softDeletes): void
    {
        $model->setRawAttributes($before, true);

        if ($softDeletes) {
            $this->ing($record, $model, 'forceDeleting');
        }

        $this->ing($record, $model, 'deleting');
        $model->exists = false;
        $this->ed($record, $model, 'deleted');

        if ($softDeletes) {
            $this->ed($record, $model, 'forceDeleted');
        }
    }

    private function ing(Record $record, Model $model, string $event): void
    {
        $attributes = $model->getAttributes();

        if ($this->fire($record->map, $model, $event, true) === false) {
            throw CompatibilityWriteRefused::byMirroredListener($record->map, $model, $event);
        }

        $changed = $this->changed($attributes, $model->getAttributes());

        if ($changed !== []) {
            throw CompatibilityWriteRefused::byMirroredChange($record->map, $model, $event, $changed);
        }
    }

    private function ed(Record $record, Model $model, string $event): void
    {
        $this->fire($record->map, $model, $event, false);
    }

    private function fire(string $map, Model $model, string $event, bool $halt): mixed
    {
        $this->fired[] = $event;
        $dispatcher = $model::getEventDispatcher();

        if ($dispatcher === null) {
            return null;
        }

        $excludedListeners = array_values(array_filter($this->mirroredMaps->excludedListeners($map), fn (ExcludedListener $excludedListener) => $excludedListener->appliesTo($event)));
        $custom = $model->dispatchesEvents()[$event] ?? null;

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

        return $this->dispatch($dispatcher, "eloquent.{$event}: ".$model::class, $model, $halt, $excludedListeners, $event);
    }

    /**
     * @param  list<ExcludedListener>  $excludedListeners
     */
    private function dispatch(Dispatcher $dispatcher, object|string $event, Model $model, bool $halt, array $excludedListeners, string $modelEvent): mixed
    {
        $payload = is_object($event) ? [] : [$model];

        if ($excludedListeners === []) {
            return $halt ? $dispatcher->until($event, $payload) : $dispatcher->dispatch($event, $payload);
        }

        $real = $dispatcher instanceof EventFake ? $dispatcher->dispatcher : $dispatcher;
        $subject = is_object($event) ? $event : $model;
        $masked = $this->mask($real, is_object($event) ? $event::class : $event, $excludedListeners, $modelEvent);
        $this->excluded[$subject] = [$modelEvent, $excludedListeners];

        try {
            return $halt ? $real->until($event, $payload) : $real->dispatch($event, $payload);
        } finally {
            unset($this->excluded[$subject]);
            $this->unmask($real, $masked);
        }
    }

    /**
     * @param  list<ExcludedListener>  $excludedListeners
     * @return array<string, list<mixed>>
     */
    private function mask(Dispatcher $events, string $name, array $excludedListeners, string $modelEvent): array
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

    private function guard(Dispatcher $events, string $key, mixed $listener): mixed
    {
        if ($this->identify($listener)[0] === null) {
            return $listener;
        }

        $prepared = $events->makeListener($listener); // @phpstan-ignore staticMethod.dynamicCall

        return function (mixed ...$payload) use ($prepared, $key, $listener): mixed {
            $subject = $payload[0] ?? null;
            [$event, $excludedListeners] = is_object($subject) ? $this->excluded[$subject] ?? ['', []] : ['', []];

            return $this->excludes($excludedListeners, $event, $listener) ? null : $prepared($key, $payload);
        };
    }

    /**
     * @param  list<ExcludedListener>  $excludedListeners
     */
    private function excludes(array $excludedListeners, string $event, mixed $listener): bool
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

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function changed(array $before, array $after): array
    {
        $changed = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $column) {
            if (! $this->equivalent($before[$column] ?? null, $after[$column] ?? null)) {
                $changed[] = (string) $column;
            }
        }

        return $changed;
    }

    private function equivalent(mixed $before, mixed $after): bool
    {
        if ($before === $after) {
            return true;
        }

        if (is_numeric($before) && is_numeric($after)) {
            return (float) $before === (float) $after;
        }

        if (is_string($before) && is_string($after) && json_validate($before) && json_validate($after)) {
            return json_decode($before, true) === json_decode($after, true);
        }

        return false;
    }
}
