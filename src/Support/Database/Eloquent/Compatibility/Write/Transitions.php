<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use BackedEnum;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Support\Actions\Bus\Exceptions\Prevented;
use Support\Actions\Pipeline\Exceptions\Interrupted;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Exceptions\Invalid;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Transition;
use Support\Database\Eloquent\StateMachines\Contracts\StateMachineable;
use Support\Database\Eloquent\StateMachines\StateMachine;
use Support\Database\Eloquent\StateMachines\Triggers\Exceptions\Duplicate;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;

final readonly class Transitions
{
    public function __construct(private Connection $connection) {}

    public function run(Model $legacy, int|string $key, string $attribute, Model $successor, string $column, mixed $value): void
    {
        $machine = $this->machine($legacy, $attribute, $successor, $column);
        $current = self::stored($successor->getRawOriginal($column));
        $target = self::stored($value);

        if ($current === $target) {
            return;
        }

        $from = $current === null ? null : $machine::tryFrom($current);
        $to = $target === null ? null : $machine::tryFrom($target);
        $transitions = $from instanceof StateMachineable && $to !== null ? $from->transitions()->filter(fn (Transition $transition) => $transition->to === $to)->values()->all() : [];

        if ($transitions === []) {
            throw CompatibilityWriteRefused::noTransition($legacy::class, $attribute, $key, $machine, $current, $target);
        }

        if (count($transitions) > 1) {
            throw CompatibilityWriteRefused::severalTransitions($legacy::class, $attribute, $key, $machine, (string) $current, (string) $target, array_map(fn (Transition $transition) => $transition->using, $transitions), $successor::class);
        }

        $using = $transitions[0]->using;

        try {
            Applying::run($legacy, $successor, fn () => $this->trigger($successor, $column, Str::camel(class_basename($using)))->now());
        } catch (Invalid|Duplicate|Interrupted|Prevented $refusal) {
            throw CompatibilityWriteRefused::byTransition($legacy::class, $attribute, $key, $using, $refusal);
        }

        if (self::stored($successor->getRawOriginal($column)) !== $target) {
            throw CompatibilityWriteRefused::byTransition($legacy::class, $attribute, $key, $using, new RuntimeException(class_basename($successor)." did not store {$column}, because a listener of its save refused the save."));
        }
    }

    public function initial(Model $legacy, string $attribute, Model $successor, string $column, mixed $value): void
    {
        $this->machine($legacy, $attribute, $successor, $column);
        $initial = $this->initialState($successor, $column);
        $stored = self::stored($value);

        if ($initial === null || $stored !== $initial) {
            throw CompatibilityWriteRefused::notInitial($legacy::class, $attribute, $successor::class, $column, $stored, $initial);
        }
    }

    /**
     * @return class-string<StateMachineable&BackedEnum>
     */
    private function machine(Model $legacy, string $attribute, Model $successor, string $column): string
    {
        $cast = $successor->getCasts()[$column] ?? null;

        if (! is_string($cast) || ! is_subclass_of($cast, StateMachineable::class) || ! is_subclass_of($cast, BackedEnum::class)) {
            throw CompatibilityWriteRefused::notAStateMachine($legacy::class, $attribute, $successor::class, $column);
        }

        return $cast;
    }

    private function trigger(Model $successor, string $column, string $name): Trigger
    {
        $state = $successor->getAttribute($column);
        $trigger = $state instanceof StateMachine ? $state->{$name}() : null;

        return $trigger instanceof Trigger ? $trigger : throw new LogicException(class_basename($successor)."::\${$column} gives no {$name} trigger.");
    }

    private function initialState(Model $successor, string $column): null|string
    {
        $default = $successor->newInstance()->getAttributes()[$column] ?? null;

        if ($default !== null) {
            return self::stored($default);
        }

        $expression = (new ColumnCatalog($this->connection))->of($successor->getTable())[$column]->default ?? null;

        return $expression === null ? null : self::stored($this->connection->scalar('select ('.$expression.')::text'));
    }

    private static function stored(mixed $value): null|string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_int($value), is_string($value) => (string) $value,
            default => null,
        };
    }
}
