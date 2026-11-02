<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

use Closure;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Write\Applying;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;
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
        'updating' => ['updating', 'updated', 'saved'],
        'restoring' => ['restoring', 'saving', 'updating', 'updated', 'saved', 'restored'],
        'deleting' => ['deleting', 'trashed', 'deleted', 'forceDeleted'],
        'forceDeleting' => ['forceDeleting', 'deleting', 'trashed', 'deleted', 'forceDeleted'],
    ];

    private const array CONTINUES = [
        'restoring' => ['saving'],
        'saving' => ['creating', 'updating'],
        'forceDeleting' => ['deleting'],
    ];

    /** @var WeakMap<Model, string> */
    private WeakMap $last;

    /** @var WeakMap<Model, LegacyRecord> */
    private WeakMap $records;

    /** @var array<int, array{array<string, string>, list<string>}> */
    private array $compiled = [];

    private function __construct(private readonly ResolvedMap $map, private readonly ResolvedMap $served, private readonly null|Related $dependent)
    {
        $this->last = new WeakMap;
        $this->records = new WeakMap;
    }

    public static function register(DispatcherContract $events, ResolvedMap $map): void
    {
        foreach ($map->branches === [] ? [$map] : $map->branches as $served) {
            $forwarders = [$served->primary->model => new self($map, $served, null)];

            foreach ($served->related as $related) {
                $forwarders[$related->model] = new self($map, $served, $related);
            }

            foreach ($forwarders as $successor => $forwarder) {
                foreach (array_keys($forwarder->dependent === null ? self::PRIMARY : self::DEPENDENT) as $event) {
                    $events->listen("eloquent.{$event}: {$successor}", fn (Model $model): null|false => $forwarder->forward($event, $model));
                }
            }
        }
    }

    public function forward(string $event, Model $successor): null|false
    {
        if (Applying::holds($successor)) {
            return null;
        }

        $ahead = array_key_exists($event, self::FOLLOWING);
        $last = $this->last[$successor] ?? null;

        if ($ahead && ($last === null || ! in_array($event, self::CONTINUES[$last] ?? [], true))) {
            $started = $this->start($event, $successor);

            if ($started === null) {
                $this->records->offsetUnset($successor);
            } else {
                $this->records->offsetSet($successor, $started);
            }
        } elseif ($last === null) {
            return null;
        }

        $this->last[$successor] = $event;
        $record = $this->records[$successor] ?? null;

        if ($record === null) {
            return null;
        }

        if ($ahead && ($this->dependent !== null || ! $record->removes)) {
            $record->after($successor);
        }

        if (! $ahead) {
            $record->store($successor);
        }

        foreach (($this->dependent === null ? self::PRIMARY : self::DEPENDENT)[$event] ?? [] as $legacyEvent) {
            if (! $this->listens($legacyEvent)) {
                continue;
            }

            foreach ($record->records() as $legacy) {
                $before = $legacy->getAttributes();
                $result = $this->dispatch($legacyEvent, $legacy, $ahead);

                if ($ahead && $result === false) {
                    return false;
                }

                if ($ahead) {
                    $this->mapBack($legacy, $before, $successor, $record->removes);
                }
            }
        }

        return null;
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

            return $record->changes() ? $record : null;
        }

        if ($removes) {
            foreach ($record->records() as $legacy) {
                ExternalAttributes::resolveAll($legacy);
            }
        }

        return $removes && $record->records() === [] ? null : $record;
    }

    private function member(Model $successor): bool
    {
        $membership = $this->served->membership;

        if ($membership === null || $this->dependent !== null || ! array_key_exists($membership->column, $successor->getAttributes())) {
            return true;
        }

        return in_array($membership->alias, [$successor->getAttributes()[$membership->column], $successor->getRawOriginal($membership->column)], true);
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function mapBack(Model $legacy, array $before, Model $successor, bool $removes): void
    {
        $baseline = $before + $legacy->getRawOriginal();

        foreach ($legacy->getAttributes() as $name => $value) {
            if (array_key_exists($name, $baseline) && $baseline[$name] === $value) {
                continue;
            }

            $attribute = $this->served->attributes[$name] ?? null;
            $because = match (true) {
                $removes => 'The forwarded event belongs to a delete, which writes no value.',
                array_key_exists($name, $this->served->identifiers) => 'It identifies the record, and a forwarded event cannot move it.',
                $attribute === null => 'No successor model stores it, so the forwarded write has nowhere to put it.',
                $this->served->owners[$name]->model !== $successor::class => 'It lives on '.class_basename($this->served->owners[$name]->model).', and the forwarded event belongs to a save of '.class_basename($successor).'.',
                $attribute->runsTransitions() => 'It is declared transitions(), so only a write through '.class_basename($legacy).' can run the trigger that changes it.',
                ! $attribute->isWritable() => $attribute->readOnlyBecause() ?? 'It is declared fixed() or external(), so it stores no column of '.class_basename($successor).'.',
                default => null,
            };

            if ($because !== null || $attribute === null) {
                throw CompatibilityWriteRefused::byForwardedMutation($legacy::class, $name, $successor, (string) $because);
            }

            try {
                $stored = $attribute->write($value);
            } catch (LogicException $exception) {
                throw CompatibilityWriteRefused::byForwardedMutation($legacy::class, $name, $successor, $exception->getMessage());
            }

            $successor->setRawAttributes(array_replace($successor->getAttributes(), [(string) $attribute->column() => $stored]));
        }
    }

    private function dispatch(string $event, Model $legacy, bool $halt): mixed
    {
        $dispatcher = Model::getEventDispatcher();
        $name = "eloquent.{$event}: ".$legacy::class;

        if (! $dispatcher instanceof Dispatcher) {
            return $halt ? $dispatcher?->until($name, $legacy) : $dispatcher?->dispatch($name, $legacy);
        }

        $exact = $dispatcher->getRawListeners()[$name] ?? [];

        foreach ($dispatcher->getListeners($name) as $index => $listener) {
            if ($index < count($exact) && $this->excluded($exact[$index])) {
                continue;
            }

            $response = $listener($name, [$legacy]);

            if ($halt && $response !== null) {
                return $response;
            }

            if ($response === false) {
                break;
            }
        }

        return null;
    }

    private function listens(string $event): bool
    {
        $dispatcher = Model::getEventDispatcher();
        $name = "eloquent.{$event}: ".$this->map->model;

        if (! $dispatcher instanceof Dispatcher) {
            return $dispatcher !== null;
        }

        foreach ($dispatcher->getRawListeners()[$name] ?? [] as $listener) {
            if (! $this->excluded($listener)) {
                return true;
            }
        }

        return $dispatcher->hasWildcardListeners($name);
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
