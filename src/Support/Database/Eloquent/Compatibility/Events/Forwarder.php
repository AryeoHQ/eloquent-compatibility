<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

use Closure;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\EventFake;
use LogicException;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Write\Applying;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;
use Throwable;
use WeakMap;

final class Forwarder
{
    private const array PRIMARY = [
        'saving' => ['saving'],
        'creating' => ['creating'],
        'created' => ['created'],
        'updating' => ['updating'],
        'updated' => ['updated'],
        'saved' => ['saved'],
        'deleting' => ['deleting'],
        'trashed' => ['trashed'],
        'deleted' => ['deleted'],
        'restoring' => ['restoring'],
        'restored' => ['restored'],
        'forceDeleting' => ['forceDeleting'],
        'forceDeleted' => ['forceDeleted'],
    ];

    private const array DEPENDENT = [
        'saving' => ['saving'],
        'creating' => ['updating'],
        'updating' => ['updating'],
        'created' => ['updated'],
        'updated' => ['updated'],
        'saved' => ['saved'],
        'deleting' => ['saving', 'updating'],
        'deleted' => ['updated', 'saved'],
    ];

    private const array FOLLOWING = [
        'saving' => ['saving', 'creating', 'created', 'updating', 'updated', 'saved'],
        'creating' => ['creating', 'created', 'saved'],
        'updating' => ['updating', 'updated'],
        'restoring' => ['restoring', 'saving', 'updating', 'updated', 'saved', 'restored'],
        'deleting' => ['deleting', 'trashed', 'deleted', 'forceDeleted'],
        'forceDeleting' => ['forceDeleting', 'deleting', 'trashed', 'deleted', 'forceDeleted'],
    ];

    private const array CONTINUES = [
        'restoring' => ['saving'],
        'saving' => ['creating', 'updating'],
        'forceDeleting' => ['deleting'],
    ];

    private const array ENDS = [
        'saving' => 'saved',
        'creating' => 'saved',
        'updating' => 'updated',
        'restoring' => 'restored',
        'deleting' => 'deleted',
        'forceDeleting' => 'forceDeleted',
    ];

    private const array STORED_BEFORE = ['saving', 'restoring'];

    /** @var WeakMap<Model, list<Sequence>> */
    private WeakMap $sequences;

    /** @var WeakMap<Model, string> */
    private WeakMap $fired;

    /** @var array<int, array{array<string, string>, list<string>}> */
    private array $compiled = [];

    /** @var null|array<string, class-string> */
    private null|array $objects = null;

    private function __construct(private readonly ResolvedMap $map, private readonly ResolvedMap $served, private readonly null|Related $dependent)
    {
        $this->sequences = new WeakMap;
        $this->fired = new WeakMap;
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function register(DispatcherContract $events, string $model): void
    {
        foreach (Compatibility::of($model)->successors() as $primary => $successors) {
            foreach ($successors as $successor) {
                $forward = self::lazily($model, $primary, $successor);
                $forwarded = array_keys($successor === $primary ? self::PRIMARY : self::DEPENDENT);

                foreach ($forwarded as $event) {
                    self::prepend($events, "eloquent.{$event}: {$successor}", fn (Model $instance): null|false => $forward($event, $instance, false));
                }

                foreach (self::objects($successor, $forwarded) as $object) {
                    self::prepend($events, $object, fn (object $fired): null|false => ($origin = self::origin($fired, $successor)) === null ? null : $forward($origin[0], $origin[1], true));
                }
            }
        }
    }

    /**
     * @param  class-string<Model>  $successor
     * @param  list<string>  $events
     * @return list<class-string>
     */
    private static function objects(string $successor, array $events): array
    {
        $objects = [];

        foreach (array_intersect_key((new $successor)->dispatchesEvents(), array_flip($events)) as $object) {
            if (is_string($object) && class_exists($object) && ! in_array($object, $objects, true)) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * @return null|array{string, Model}
     */
    private static function origin(object $fired, string $successor): null|array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
            $model = $frame['object'] ?? null;

            if ($frame['function'] !== 'fireCustomModelEvent' || ! $model instanceof Model) {
                continue;
            }

            $event = $frame['args'][0] ?? null;
            $object = is_string($event) ? $model->dispatchesEvents()[$event] ?? null : null;

            return $model::class === $successor && is_string($event) && is_string($object) && $fired instanceof $object ? [$event, $model] : null;
        }

        return null;
    }

    /**
     * @param  class-string<Model>  $model
     * @return Closure(string, Model, bool): (null|false)
     */
    private static function lazily(string $model, string $primary, string $successor): Closure
    {
        $forwarder = null;

        return function (string $event, Model $instance, bool $object) use (&$forwarder, $model, $primary, $successor): null|false {
            $forwarder ??= self::resolve(LegacyBuilder::mapOf($model), $primary, $successor);

            return $forwarder->forward($event, $instance, $object);
        };
    }

    public function forward(string $event, Model $successor, bool $object = false): null|false
    {
        if ($object) {
            $this->fired[$successor] = $event;
        } elseif (($this->fired[$successor] ?? null) === $event) {
            $this->fired->offsetUnset($successor);

            return null;
        }

        if (Applying::holds($successor)) {
            return null;
        }

        $ahead = array_key_exists($event, self::FOLLOWING);
        $sequences = $this->sequences[$successor] ?? [];
        $frames = null;

        while ($sequences !== [] && end($sequences)->frame !== null && ! self::active(end($sequences)->frame, $successor, $frames ??= self::frames())) {
            array_pop($sequences);
        }

        $top = array_key_last($sequences);

        if ($ahead && ($top === null || ! in_array($event, self::CONTINUES[$sequences[$top]->last] ?? [], true))) {
            $record = $this->start($event, $successor);
            $sequences[] = new Sequence($event, $event, $record, $record === null ? null : self::caller($successor, $frames ??= self::frames()));
            $top = array_key_last($sequences);
        } elseif ($top === null) {
            return null;
        }

        $sequence = $sequences[$top];
        $sequence->last = $event;
        $result = $this->raise($event, $successor, $sequence, $ahead);

        if ($result === false || self::ENDS[$sequence->start] === $event) {
            array_pop($sequences);
            $outer = array_key_last($sequences);

            if ($outer !== null && $result !== false) {
                $sequences[$outer]->record?->reread();
            }
        }

        if ($sequences === []) {
            $this->sequences->offsetUnset($successor);
        } else {
            $this->sequences->offsetSet($successor, $sequences);
        }

        return $result;
    }

    /**
     * @return list<array{function: string, object?: object}>
     */
    private static function frames(): array
    {
        return debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS);
    }

    /**
     * @param  list<array{function: string, object?: object}>  $frames
     * @return null|array{int, string}
     */
    private static function caller(Model $successor, array $frames): null|array
    {
        foreach ($frames as $index => $frame) {
            if (($frame['object'] ?? null) !== $successor || $frame['function'] !== 'fireModelEvent') {
                continue;
            }

            $caller = $index + 1;

            while (($frames[$caller]['function'] ?? null) === 'fireModelEvent') {
                $caller++;
            }

            $frame = $frames[$caller] ?? null;

            return $frame !== null && ($frame['object'] ?? null) === $successor ? [count($frames) - $caller, $frame['function']] : null;
        }

        return null;
    }

    /**
     * @param  array{int, string}  $frame
     * @param  list<array{function: string, object?: object}>  $frames
     */
    private static function active(array $frame, Model $successor, array $frames): bool
    {
        $caller = $frames[count($frames) - $frame[0]] ?? null;

        return $caller !== null && $caller['function'] === $frame[1] && ($caller['object'] ?? null) === $successor;
    }

    private static function resolve(ResolvedMap $map, string $primary, string $successor): self
    {
        $served = $map->branches[$primary] ?? $map;

        foreach ($served->related as $related) {
            if ($related->model === $successor) {
                return new self($map, $served, $related);
            }
        }

        return new self($map, $served, null);
    }

    private static function prepend(DispatcherContract $events, string $event, Closure $listener): void
    {
        $listeners = $events instanceof Dispatcher ? $events->getRawListeners()[$event] ?? [] : [];

        if ($listeners !== []) {
            $events->forget($event);
        }

        $events->listen($event, $listener);

        foreach ($listeners as $existing) {
            $events->listen($event, $existing);
        }
    }

    private function raise(string $event, Model $successor, Sequence $sequence, bool $ahead): null|false
    {
        $record = $sequence->record;

        if ($record === null) {
            return null;
        }

        if ($ahead && ($this->dependent !== null || ! $record->removes)) {
            $record->after($successor);
        }

        $events = ($this->dependent === null ? self::PRIMARY : self::DEPENDENT)[$event] ?? [];
        $batches = null;

        if ($this->dependent !== null) {
            $joining = $ahead ? array_diff_key($record->changed(), $sequence->forwarding) : [];
            $batches = array_filter([
                [$events, array_intersect_key($record->records(), $sequence->forwarding)],
                [$event === $sequence->start ? $events : ['saving', ...$events], $joining],
            ], fn (array $batch) => $batch[1] !== []);
            $sequence->forwarding += array_fill_keys(array_keys($joining), true);

            if ($batches === []) {
                return null;
            }
        }

        if (! $ahead) {
            $record->store($successor);
        }

        foreach ($batches ?? [[$events, $record->records()]] as [$legacyEvents, $legacies]) {
            foreach ($legacyEvents as $legacyEvent) {
                if (! $this->listens($legacyEvent)) {
                    continue;
                }

                foreach ($legacies as $legacy) {
                    if ($this->dependent === null) {
                        self::forceDeleting($legacy, $successor);
                    }

                    $before = $legacy->getAttributes();
                    $result = $this->dispatch($legacyEvent, $legacy, $ahead);

                    if ($ahead && $result === false) {
                        return false;
                    }

                    if ($ahead) {
                        $this->mapBack($legacy, $before, $successor, $event, $record->removes);
                    }
                }
            }
        }

        return null;
    }

    private static function forceDeleting(Model $legacy, Model $successor): void
    {
        if (! property_exists($legacy, 'forceDeleting')) {
            return;
        }

        $forcing = method_exists($successor, 'isForceDeleting') && $successor->isForceDeleting() === true;
        Closure::bind(function () use ($legacy, $forcing): void {
            $legacy->forceDeleting = $forcing;
        }, null, $legacy::class)();
    }

    private function start(string $event, Model $successor): null|LegacyRecord
    {
        $listened = array_filter($this->dependent === null ? self::FOLLOWING[$event] : ['saving', 'updating', 'updated', 'saved'], $this->listens(...)) !== [];

        return $listened && $this->member($successor) ? $this->read($successor, in_array($event, ['deleting', 'forceDeleting'], true)) : null;
    }

    private function read(Model $successor, bool $removes): null|LegacyRecord
    {
        $view = $successor->getConnection()->scalar(
            "select cast(oid as bigint) from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?) and relkind = 'v'",
            [SqlFragments::identifier($this->map->table)],
            false,
        );

        if (! is_int($view)) {
            return null;
        }

        [$columns, $json] = $this->compiled[$view] ??= LegacyRecord::compile($this->map, $this->served, $successor);
        $record = LegacyRecord::read($this->map, $this->served, $this->dependent, $columns, $json, $successor, $removes);

        if ($record === null) {
            return null;
        }

        if ($this->dependent !== null) {
            $record->after($successor);

            return $record;
        }

        if ($removes) {
            foreach ($record->records() as $legacy) {
                ExternalAttributes::resolveAll($legacy);
            }
        }

        return ($removes || $successor->exists) && $record->records() === [] ? null : $record;
    }

    private function member(Model $successor): bool
    {
        $membership = $this->served->membership;

        if ($membership === null || $this->dependent !== null || ! array_key_exists($membership->column, $successor->getAttributes())) {
            return true;
        }

        return $successor->getAttributes()[$membership->column] === $membership->alias
            && (! $successor->exists || $successor->getRawOriginal($membership->column) === $membership->alias);
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function mapBack(Model $legacy, array $before, Model $successor, string $event, bool $removes): void
    {
        $baseline = $before + $legacy->getRawOriginal();
        $compared = $legacy->newInstance([], true);
        $compared->setRawAttributes($baseline, true);
        $compared->setRawAttributes($legacy->getAttributes());

        foreach ($legacy->getAttributes() as $name => $value) {
            if (array_key_exists($name, $baseline) && self::equivalent($compared, $name)) {
                continue;
            }

            $attribute = $this->served->attributes[$name] ?? null;
            $because = match (true) {
                $removes => 'The forwarded event belongs to a delete, which writes no value.',
                array_key_exists($name, $this->served->identifiers) => 'It identifies the record, and a forwarded event cannot move it.',
                $attribute === null => 'No successor model stores it, so the forwarded write has nowhere to put it.',
                $this->served->owners[$name]->model !== $successor::class => 'It lives on '.class_basename($this->served->owners[$name]->model).', and the forwarded event belongs to a save of '.class_basename($successor).'.',
                $attribute->runsTransitions() => 'It is declared transitions(), so only a write through '.class_basename($legacy).' can run the trigger that changes it.',
                $attribute->isExternal() => $attribute->declinedBecause() ?? (in_array($event, self::STORED_BEFORE, true) ? null : 'It is declared external(), and '.class_basename($successor)." raised its {$event} event after its own saving listeners ran, so the write closure reaches no save."),
                ! $attribute->isWritable() => $attribute->readOnlyBecause() ?? 'It is declared fixed(), so it stores no column of '.class_basename($successor).'.',
                default => null,
            };

            if ($because !== null || $attribute === null) {
                throw CompatibilityWriteRefused::byForwardedMutation($legacy::class, $name, $successor, (string) $because);
            }

            if ($attribute->isExternal()) {
                $attribute->writeExternally($successor, $value);

                continue;
            }

            try {
                $stored = $attribute->write($value);
            } catch (LogicException $exception) {
                throw CompatibilityWriteRefused::byForwardedMutation($legacy::class, $name, $successor, $exception->getMessage());
            }

            $successor->setRawAttributes(array_replace($successor->getAttributes(), [(string) $attribute->column() => $stored]));
        }
    }

    private static function equivalent(Model $compared, string $name): bool
    {
        try {
            return $compared->originalIsEquivalent($name);
        } catch (Throwable) {
            return false;
        }
    }

    private function dispatch(string $event, Model $legacy, bool $halt): mixed
    {
        $dispatcher = Model::getEventDispatcher();

        if ($dispatcher === null) {
            return null;
        }

        $object = $legacy->dispatchesEvents()[$event] ?? null;

        if (is_string($object) && class_exists($object)) {
            $result = $this->send($dispatcher, new $object($legacy), [], $halt);
            $result = is_array($result) ? array_filter($result, fn (mixed $response) => $response !== null) : $result;

            if (! in_array($result, [null, 0, 0.0, '', '0', []], true)) {
                return $result;
            }
        }

        return $this->send($dispatcher, "eloquent.{$event}: ".$legacy::class, $legacy, $halt);
    }

    private function send(DispatcherContract $dispatcher, string|object $event, mixed $payload, bool $halt): mixed
    {
        while ($dispatcher instanceof EventFake) {
            $probe = new class extends Dispatcher
            {
                public bool $reached = false;

                /**
                 * @param  string|object  $event
                 * @param  mixed  $payload
                 * @param  bool  $halt
                 * @return null|array<array-key, mixed>
                 */
                public function dispatch($event, $payload = [], $halt = false)
                {
                    $this->reached = true;

                    return null;
                }
            };
            $real = $dispatcher->dispatcher;
            $dispatcher->dispatcher = $probe;

            try {
                $faked = $dispatcher->dispatch($event, $payload, $halt);
            } finally {
                $dispatcher->dispatcher = $real;
            }

            if (! $probe->reached) {
                return $faked;
            }

            $dispatcher = $real;
        }

        $name = is_object($event) ? $event::class : $event;

        if (! $dispatcher instanceof Dispatcher || ! $this->excludes($dispatcher, $name)) {
            return $halt ? $dispatcher->until($event, $payload) : $dispatcher->dispatch($event, $payload);
        }

        $raw = $this->raw($dispatcher, $name);
        $responses = [];

        foreach ($dispatcher->getListeners($name) as $index => $listener) {
            if ($this->excluded($raw[$index] ?? null)) {
                continue;
            }

            $response = $listener($name, is_object($event) ? [$event] : [$payload]);

            if ($halt && $response !== null) {
                return $response;
            }

            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    private function listens(string $event): bool
    {
        if (array_key_exists($event, $this->objects ??= (new $this->map->model)->dispatchesEvents())) {
            return true;
        }

        $dispatcher = Model::getEventDispatcher();
        $name = "eloquent.{$event}: ".$this->map->model;

        if (! $dispatcher instanceof Dispatcher) {
            return $dispatcher !== null;
        }

        foreach ($this->raw($dispatcher, $name) as $listener) {
            if (! $this->excluded($listener)) {
                return true;
            }
        }

        return false;
    }

    private function excludes(Dispatcher $dispatcher, string $name): bool
    {
        foreach ($this->raw($dispatcher, $name) as $listener) {
            if ($this->excluded($listener)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<mixed>
     */
    private function raw(Dispatcher $dispatcher, string $name): array
    {
        $raw = array_values($dispatcher->getRawListeners()[$name] ?? []);

        if (! $dispatcher->hasWildcardListeners($name)) {
            return $raw;
        }

        $wildcards = Closure::bind(fn (): array => $this->wildcards, $dispatcher, Dispatcher::class)();

        foreach ($wildcards as $pattern => $listeners) {
            if (Str::is($pattern, $name)) {
                array_push($raw, ...array_values($listeners));
            }
        }

        return $raw;
    }

    private function excluded(mixed $listener): bool
    {
        $class = match (true) {
            is_string($listener) => Str::before($listener, '@'),
            is_array($listener) && is_object($listener[0] ?? null) => $listener[0]::class,
            is_array($listener) && is_string($listener[0] ?? null) => $listener[0],
            is_object($listener) && ! $listener instanceof Closure => $listener::class,
            default => null,
        };

        foreach (array_keys($this->map->withoutForwarding) as $excluded) {
            if ($class !== null && is_a($class, $excluded, true)) {
                return true;
            }
        }

        return false;
    }
}
