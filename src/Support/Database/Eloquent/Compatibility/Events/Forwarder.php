<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

use Closure;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\EventFake;
use LogicException;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\CarriesLegacy;
use Support\Database\Eloquent\Compatibility\Schema\ServedTable;
use Support\Database\Eloquent\Compatibility\Write\Applying;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;
use Support\Database\Eloquent\Compatibility\Write\Origin;
use Support\Database\Eloquent\Compatibility\Write\RetainedRows;
use Support\Database\Eloquent\Compatibility\Write\Staging;
use Support\Database\Eloquent\Compatibility\Write\UnitOfWork;
use Throwable;
use WeakMap;

/**
 * @internal
 */
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

    private const array BEFORE_INSERT = ['saving', 'creating'];

    /** @var WeakMap<Model, list<Sequence>> */
    private WeakMap $sequences;

    /** @var WeakMap<Model, array<int, string>> */
    private WeakMap $fired;

    /** @var array<int, array{array<string, string>, list<string>, list<string>}> */
    private array $compiled = [];

    /** @var null|array<string, class-string> */
    private null|array $objects = null;

    /** @var null|WeakMap<object, true> */
    private static null|WeakMap $emitted = null;

    /** @var null|WeakMap<Model, list<array{self, Sequence}>> */
    private static null|WeakMap $deferred = null;

    /** @var null|WeakMap<Model, array<class-string<Model>, bool>> */
    private static null|WeakMap $chosen = null;

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
        $successors = Compatibility::of($model)->successors();
        $primary = $successors[0] ?? null;

        foreach ($successors as $successor) {
            $forward = self::lazily(
                model: $model,
                successor: $successor,
            );
            $forwarded = array_keys($successor === $primary ? self::PRIMARY : self::DEPENDENT);

            foreach ($forwarded as $event) {
                self::prepend(
                    events: $events,
                    event: "eloquent.{$event}: {$successor}",
                    listener: fn (Model $instance): null|false => $forward($event, $instance, false),
                );
            }

            foreach (self::objects(
                successor: $successor,
                events: $forwarded,
            ) as $object) {
                self::prepend(
                    events: $events,
                    event: $object,
                    listener: fn (object $fired): null|false => (self::$emitted?->offsetExists($fired) ?? false) || ($origin = self::origin(
                        fired: $fired,
                        successor: $successor,
                    )) === null ? null : $forward($origin[0], $origin[1], true),
                );
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
    private static function lazily(string $model, string $successor): Closure
    {
        $forwarder = null;

        return function (string $event, Model $instance, bool $object) use (&$forwarder, $model, $successor): null|false {
            $forwarder ??= self::resolve(
                map: LegacyBuilder::mapOf($model),
                successor: $successor,
            );

            return $forwarder->forward($event, $instance, $object);
        };
    }

    public function forward(string $event, Model $successor, bool $object = false): null|false
    {
        if ($this->forwarded($event, $successor, $object)) {
            return null;
        }

        if (Applying::holds($successor)) {
            return null;
        }

        $ahead = array_key_exists($event, self::FOLLOWING);
        $sequences = $this->sequences[$successor] ?? [];
        $frames = null;

        while ($sequences !== [] && ((end($sequences)->vetoed && $event !== 'restored') || (end($sequences)->frame !== null && ! self::active(end($sequences)->frame, $successor, $frames ??= self::frames())))) {
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
        $saved = $event !== 'restored' || $sequence->last === 'saved';
        $sequence->last = $event;
        $result = in_array($event, self::BEFORE_INSERT, true) && $this->defers($successor) ? $this->defer(
            event: $event,
            successor: $successor,
            sequence: $sequence,
        ) : $this->raise(
            event: $event,
            successor: $successor,
            sequence: $sequence,
            ahead: $ahead,
            saved: $saved,
        );

        if ($result === false && $sequence->start === 'restoring' && $event !== 'restoring') {
            $sequence->vetoed = true;
        } elseif ($result === false || self::ENDS[$sequence->start] === $event) {
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

    public static function creating(Model $successor, bool $proceeds): null|false
    {
        $deferred = self::$deferred ??= new WeakMap;
        $chosen = self::$chosen ??= new WeakMap;
        $pending = $deferred[$successor] ?? [];

        unset($deferred[$successor], $chosen[$successor]);

        if (! $proceeds) {
            return null;
        }

        $choices = [];

        foreach (self::BEFORE_INSERT as $event) {
            foreach ($pending as [$forwarder, $sequence]) {
                if (! in_array($event, $sequence->deferred, true)) {
                    continue;
                }

                if ($forwarder->raise(event: $event, successor: $successor, sequence: $sequence, ahead: true, saved: true, deferred: true) === false) {
                    $sequence->vetoed = true;

                    return false;
                }

                $admits = $sequence->record?->admits();

                if ($event === 'creating' && $admits !== null) {
                    $choices[$forwarder->map->model] = $admits;
                }
            }
        }

        $chosen[$successor] = $choices;

        return null;
    }

    /**
     * @return array<class-string<Model>, bool>
     */
    public static function chosen(Model $successor): array
    {
        $chosen = self::$chosen ??= new WeakMap;
        $choices = $chosen[$successor] ?? [];

        unset($chosen[$successor]);

        return $choices;
    }

    private function defers(Model $successor): bool
    {
        return $this->dependent === null
            && ! $successor->exists
            && in_array(
                needle: CarriesLegacy::class,
                haystack: class_uses_recursive($successor),
                strict: true,
            )
            && RetainedRows::shares($successor);
    }

    private function defer(string $event, Model $successor, Sequence $sequence): null
    {
        $sequence->deferred[] = $event;

        if ($event === 'creating' && $sequence->record !== null) {
            $deferred = self::$deferred ??= new WeakMap;
            $deferred[$successor] = [...$deferred[$successor] ?? [], [$this, $sequence]];
        }

        return null;
    }

    private function forwarded(string $event, Model $successor, bool $object): bool
    {
        $marks = $this->fired[$successor] ?? [];

        if (! $object && $marks === []) {
            return false;
        }

        $frames = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
        $count = count($frames);
        $marks = array_filter($marks, fn (string $marked, int $depth) => self::fires($frames[$count - $depth] ?? null, $successor, $marked), ARRAY_FILTER_USE_BOTH);
        $depth = null;

        foreach ($frames as $index => $frame) {
            if (self::fires($frame, $successor)) {
                $depth = $count - $index;

                break;
            }
        }

        $forwarded = ! $object && $depth !== null && ($marks[$depth] ?? null) === $event;

        if ($forwarded) {
            unset($marks[$depth]);
        } elseif ($object && $depth !== null) {
            $marks[$depth] = $event;
        }

        if ($marks !== []) {
            $this->fired[$successor] = $marks;
        } elseif ($this->fired->offsetExists($successor)) {
            $this->fired->offsetUnset($successor);
        }

        return $forwarded;
    }

    /**
     * @param  null|array{function: string, object?: object, args?: array<array-key, mixed>}  $frame
     */
    private static function fires(null|array $frame, Model $successor, null|string $event = null): bool
    {
        return $frame !== null
            && $frame['function'] === 'fireModelEvent'
            && ($frame['object'] ?? null) === $successor
            && ($event === null || ($frame['args'][0] ?? null) === $event);
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

    private static function resolve(ResolvedMap $map, string $successor): self
    {
        $served = $map->morphs[$successor] ?? $map;

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

    private function raise(string $event, Model $successor, Sequence $sequence, bool $ahead, bool $saved, bool $deferred = false): null|false
    {
        $record = $sequence->record;

        if ($record === null) {
            return null;
        }

        if ($this->dependent === null ? $ahead && ! $record->removes : $ahead || ! $record->removes) {
            $record->after($successor, ! $ahead);
        }

        $events = ($this->dependent === null ? self::PRIMARY : self::DEPENDENT)[$event] ?? [];
        $batches = $this->dependent === null ? [] : $this->batches($sequence, $record->changed(), $event, $events, $ahead);

        if ($this->dependent !== null && $batches === []) {
            return null;
        }

        if (! $ahead && $saved) {
            $record->store($successor);
        }

        if ($this->dependent === null) {
            $batches = $this->batches($sequence, $record->records(), $event, $events, $ahead);
        }

        foreach ($batches as [$legacyEvents, $legacies]) {
            $legacies = array_intersect_key($legacies, $record->records());

            foreach ($legacyEvents as $legacyEvent) {
                if (! $this->listens($legacyEvent)) {
                    continue;
                }

                foreach ($legacies as $key => $legacy) {
                    if ($this->dependent === null) {
                        self::forceDeleting($legacy, $successor);
                    }

                    $before = $legacy->getAttributes();
                    $result = $this->dispatch($legacyEvent, $legacy, $ahead);

                    if ($ahead && $result === false) {
                        return false;
                    }

                    if ($ahead) {
                        $this->mapBack(
                            legacy: $legacy,
                            before: $before,
                            successor: $successor,
                            event: $event,
                            removes: $record->removes,
                            serves: $this->dependent === null || $record->serves($key),
                            deferred: $deferred,
                        );
                    }
                }
            }

            foreach (array_intersect_key($legacies, $record->records()) as $legacy) {
                if (in_array('saved', $legacyEvents, true)) {
                    $legacy->syncOriginal();
                } elseif ($sequence->start === 'updating' && in_array('updated', $legacyEvents, true)) {
                    $legacy->syncOriginalAttributes(array_keys($legacy->getChanges()));
                }
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, Model>  $candidates
     * @param  list<string>  $events
     * @return list<array{list<string>, array<array-key, Model>}>
     */
    private function batches(Sequence $sequence, array $candidates, string $event, array $events, bool $ahead): array
    {
        $joining = array_diff_key($candidates, $sequence->forwarding);
        $batches = array_values(array_filter([
            [$events, array_intersect_key($sequence->record?->records() ?? [], $sequence->forwarding)],
            [! $ahead || $event === $sequence->start ? $events : ['saving', ...$events], $joining],
        ], fn (array $batch) => $batch[1] !== []));
        $sequence->forwarding += array_fill_keys(array_keys($joining), true);

        return $batches;
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

        return $listened ? $this->read(
            successor: $successor,
            removes: in_array(
                needle: $event,
                haystack: ['deleting', 'forceDeleting'],
                strict: true,
            ),
        ) : null;
    }

    private function read(Model $successor, bool $removes): null|LegacyRecord
    {
        $relation = ServedTable::relation($successor->getConnection(), $this->map->table, ServedTable::readsOf($this->served), writing: true);
        $view = $relation['oid'];

        if ($view === 0 || $relation['unbound'] !== []) {
            return null;
        }

        [$columns, $json, $reads] = $this->compiled[$view] ??= LegacyRecord::compile($this->map, $this->served, $successor);
        $record = LegacyRecord::read(
            map: $this->map,
            served: $this->served,
            dependent: $this->dependent,
            columns: $columns,
            json: $json,
            reads: $reads,
            successor: $successor,
            removes: $removes,
            membership: $this->defers($successor),
        );

        if ($record === null) {
            return null;
        }

        if ($this->dependent !== null) {
            $record->after($successor);

            return $record;
        }

        if ($removes || $successor->exists) {
            foreach ($record->records() as $legacy) {
                ExternalAttributes::resolveAll($legacy);
            }
        }

        return ($removes || $successor->exists) && $record->records() === [] ? null : $record;
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function mapBack(Model $legacy, array $before, Model $successor, string $event, bool $removes, bool $serves, bool $deferred): void
    {
        $inserts = $this->dependent === null && ! $successor->exists;

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
                in_array(
                    needle: $name,
                    haystack: [$legacy->getKeyName(), $this->served->successorKey],
                    strict: true,
                ) => 'It identifies the record, and a forwarded event cannot move it.',
                $attribute === null => 'No successor model stores it, so the forwarded write has nowhere to put it.',
                $this->served->owners[$name]->model !== $successor::class => 'It lives on '.class_basename($this->served->owners[$name]->model).', and the forwarded event belongs to a save of '.class_basename($successor).'.',
                ! $serves => 'The written '.class_basename($successor).' no longer serves this '.class_basename($legacy).', so the forwarded write cannot store it there.',
                $attribute->ignoresWrites() => null,
                $attribute->isExternal() && $attribute->operation() !== null => match (true) {
                    $deferred => "It is declared computedAs(), and the package raised the legacy {$event} event after the saving and creating listeners of ".class_basename($successor).' ran, because several legacy models share '.class_basename($successor).', so its operation reaches no save.',
                    in_array($event, self::STORED_BEFORE, true) => null,
                    default => 'It is declared computedAs(), and '.class_basename($successor)." raised its {$event} event after its own saving listeners ran, so its operation reaches no save.",
                },
                $attribute->operation() !== null => 'It is writtenBy() '.class_basename($attribute->operation()).', and only a write through '.class_basename($legacy).' runs its operation, which may stage other successors.',
                $attribute->readOnlyBecause() !== null => $inserts && $attribute->invertible() ? null : $attribute->readOnlyBecause(),
                ! $attribute->invertible() => 'It is declared fixed(), so it stores no column of '.class_basename($successor).'.',
                default => null,
            };

            if ($because !== null || $attribute === null) {
                throw CompatibilityWriteRefused::byForwardedMutation($legacy::class, $name, $successor, (string) $because);
            }

            $change = new Change(
                record: $legacy,
                original: $legacy->newInstance([], true)->setRawAttributes($baseline, true),
                attribute: $name,
                origin: Origin::Live,
            );

            if ($attribute->ignoresWrites()) {
                if ($value !== null) {
                    Event::dispatch(new WriteIgnored(
                        change: $change,
                        value: $value,
                    ));
                }

                continue;
            }

            $operation = $attribute->operation();

            if ($operation !== null) {
                $instance = UnitOfWork::resolve(
                    class: $operation,
                    interface: WritesAttribute::class,
                    role: 'operation',
                );

                UnitOfWork::hook(
                    run: fn () => $instance->write((new Staging(
                        map: $this->served,
                        root: $this->dependent === null ? $successor : (new $this->served->primary->model)->setConnection($successor->getConnectionName()),
                        models: $this->dependent === null ? [] : [$this->dependent->table => $successor],
                        forwarding: $successor,
                    ))->successors(), $value, $change),
                    origin: Origin::Live,
                    model: $legacy::class,
                    key: $inserts ? null : UnitOfWork::keyOrNull($legacy),
                    attribute: $name,
                    class: $operation,
                );

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
            $emitted = new $object($legacy);
            self::$emitted ??= new WeakMap;
            self::$emitted[$emitted] = true;
            $result = $this->send($dispatcher, $emitted, [], $halt);
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

        $filtered = $this->filtered($dispatcher, $name);

        return $halt ? $filtered->until($event, $payload) : $filtered->dispatch($event, $payload);
    }

    private function filtered(Dispatcher $dispatcher, string $name): Dispatcher
    {
        $filtered = new class extends Dispatcher
        {
            /** @var null|Closure(string): array<int, mixed> */
            public null|Closure $resolves = null;

            /**
             * @param  string  $eventName
             * @return array<int, mixed>
             */
            public function getListeners($eventName)
            {
                return $this->resolves === null ? [] : ($this->resolves)($eventName);
            }
        };
        $filtered->resolves = function (string $eventName) use ($dispatcher, $name): array {
            $listeners = $dispatcher->getListeners($eventName);

            if ($eventName !== $name) {
                return $listeners;
            }

            $raw = $this->raw($dispatcher, $name);

            return array_values(array_filter($listeners, fn (int $index) => ! $this->excluded($raw[$index] ?? null), ARRAY_FILTER_USE_KEY));
        };
        Closure::bind(function () use ($dispatcher): void {
            $this->container = $dispatcher->container;
            $this->transactionManagerResolver = $dispatcher->transactionManagerResolver;
        }, $filtered, Dispatcher::class)();

        return $filtered;
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
        $registered = $dispatcher->getRawListeners();
        $raw = array_values($registered[$name] ?? []);

        if ($dispatcher->hasWildcardListeners($name)) {
            $wildcards = Closure::bind(fn (): array => $this->wildcards, $dispatcher, Dispatcher::class)();

            foreach ($wildcards as $pattern => $listeners) {
                if (Str::is($pattern, $name)) {
                    array_push($raw, ...array_values($listeners));
                }
            }
        }

        if (class_exists($name, false)) {
            foreach (class_implements($name) as $interface) {
                array_push($raw, ...array_values($registered[$interface] ?? []));
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
