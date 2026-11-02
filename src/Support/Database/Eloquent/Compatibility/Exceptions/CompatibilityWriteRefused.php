<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Definition\Guard;
use Throwable;

final class CompatibilityWriteRefused extends CompatibilityException
{
    public function __construct(public readonly string $refused, public readonly string $because, public readonly string $instead, null|Throwable $previous = null)
    {
        parent::__construct("{$refused}. Why: {$because} Instead: {$instead}", previous: $previous);
    }

    public static function readOnly(string $model, string $attribute, null|int|string $key, string $because): self
    {
        return new self(
            ($key === null ? "{$model} records" : "{$model} record {$key}")." cannot change \${$attribute}",
            $because,
            "Leave {$attribute} unchanged in writes through ".class_basename($model).'.',
        );
    }

    public static function transitions(string $model, string $attribute): self
    {
        return new self(
            "{$model}::\${$attribute} cannot take a mass write",
            "It is declared transitions(), so each record's change runs its successor's state-machine trigger, and a set-based statement cannot run a trigger per row.",
            'Write the records one at a time through '.class_basename($model).'.',
        );
    }

    public static function notAStateMachine(string $model, string $attribute, string $successor, string $column): self
    {
        $name = class_basename($successor);

        return new self(
            "{$model}::\${$attribute} cannot take a write",
            "It is declared transitions(), and {$name}::\${$column} has no state-machine cast.",
            "Cast {$column} on {$name} to a StateMachineable enum, or drop transitions() from the attribute.",
        );
    }

    public static function noTransition(string $model, string $attribute, int|string $key, string $machine, null|string $from, null|string $to): self
    {
        $name = class_basename($machine);

        return new self(
            "{$model} record {$key} cannot change \${$attribute} from '{$from}' to '{$to}'",
            "{$name} declares no transition from '{$from}' to '{$to}', so no trigger can make the change.",
            "Change {$attribute} only along a transition {$name} declares.",
        );
    }

    /**
     * @param  non-empty-list<string>  $triggers
     */
    public static function severalTransitions(string $model, string $attribute, int|string $key, string $machine, string $from, string $to, array $triggers, string $successor): self
    {
        return new self(
            "{$model} record {$key} cannot change \${$attribute} from '{$from}' to '{$to}'",
            class_basename($machine).' declares '.count($triggers)." transitions from '{$from}' to '{$to}', through ".implode(' and ', array_map(class_basename(...), $triggers)).', so the write cannot choose one.',
            'Run the trigger you mean on '.class_basename($successor).' directly.',
        );
    }

    public static function byTransition(string $model, string $attribute, int|string $key, string $trigger, Throwable $refusal): self
    {
        $name = class_basename($trigger);

        return new self(
            "{$model} record {$key} cannot change \${$attribute}, because the trigger {$name} refused it",
            $refusal->getMessage(),
            "Make the change once {$name} allows it, or change {$attribute} along another transition.",
            $refusal,
        );
    }

    public static function notInitial(string $model, string $attribute, string $successor, string $column, null|string $value, null|string $initial): self
    {
        $name = class_basename($successor);

        if ($initial === null) {
            return new self(
                "{$model} cannot insert a record whose \${$attribute} stores '{$value}'",
                "{$name} declares no initial state for {$column}, so an insert cannot store a state without a transition.",
                "Insert the record without {$attribute}, then set it through the state machine of {$name}.",
            );
        }

        return new self(
            "{$model} cannot insert a record whose \${$attribute} stores '{$value}'",
            "A new {$name} starts in its initial state '{$initial}' on {$column}, and an insert runs no transition.",
            "Insert the record with the {$attribute} that stores '{$initial}', then change it in a second write.",
        );
    }

    public static function external(string $model, string $attribute): self
    {
        return new self(
            "{$model}::\${$attribute} cannot take a mass write",
            'It is declared external(), so its value lives outside the database, and no set-based statement can reach an external system.',
            'Write the records one at a time through '.class_basename($model).'.',
        );
    }

    public static function declined(string $model, string $attribute, string $because): self
    {
        return new self(
            "{$model}::\${$attribute} cannot take a write",
            $because,
            "Leave {$attribute} unchanged in writes through ".class_basename($model).'.',
        );
    }

    public static function unstorable(string $model, string $attribute, string $because): self
    {
        return new self(
            "{$model}::\${$attribute} cannot take a write",
            $because,
            'Write a value its conversions can store, or write the stored column through the successor model.',
        );
    }

    public static function unsupported(string $model, string $operation, string $because): self
    {
        return new self(
            "{$model} cannot run {$operation} through its compatibility map",
            $because,
            'Write each record through '.class_basename($model).', or write the successor models directly.',
        );
    }

    public static function rawExpression(string $model, string $attribute, string $because): self
    {
        return new self(
            "{$model}::\${$attribute} cannot take a raw expression",
            $because,
            'Write the computed value, or write the stored column through the successor model.',
        );
    }

    /**
     * @param  list<string>  $attributes
     */
    public static function noKeyDefault(string $model, array $attributes, string $table, string $column): self
    {
        return new self(
            "{$model} cannot write \$".implode(', $', $attributes)." in one statement to records that have no {$table} row",
            "A set-based write creates the missing {$table} rows in SQL, and {$table}.{$column} has no database default to key them.",
            'Write the records one at a time through '.class_basename($model).", or give {$table}.{$column} a database default.",
        );
    }

    public static function byGuard(string $model, Guard $guard, int|string $key): self
    {
        return new self("{$model} record {$key} cannot take this write, because guard '{$guard->name}' refuses it", $guard->because, $guard->instead);
    }

    public static function bySuccessorListener(string $model, Model $successor): self
    {
        $name = class_basename($successor);

        return new self(
            "{$model} cannot take this write, because a listener of {$name} refused its save",
            "The write stores its values through {$name}, and that model's own listeners refused them.",
            "Make the write satisfy the rules of {$name}.",
        );
    }

    public static function byForwardedMutation(string $model, string $attribute, Model $successor, string $because): self
    {
        $name = class_basename($successor);

        return new self(
            "A listener of a {$model} event that {$name} forwarded cannot change \${$attribute}",
            $because,
            "Write {$attribute} through ".class_basename($model).", or change {$name} or the model that stores {$attribute} directly.",
        );
    }

    /**
     * @param  list<string>  $attributes
     */
    public static function noParentRow(string $model, array $attributes, string $table, string $parentTable): self
    {
        return new self(
            "{$model} cannot write \$".implode(', $', $attributes),
            "They live on a {$table} row, and the record has no {$parentTable} row to hold one.",
            "Write a value that creates the {$parentTable} row in the same write, or create that row through its own model first.",
        );
    }

    /**
     * @param  list<string>  $attributes
     */
    public static function noRelatedRow(string $model, array $attributes, string $table, string $parentTable): self
    {
        return new self(
            "{$model} cannot write \$".implode(', $', $attributes),
            "They live on a {$table} row the record does not have, and {$parentTable} reaches that row through a belongs-to relation, which creates no row.",
            "Create the {$table} row through its own model and associate it with the {$parentTable} row first.",
        );
    }
}
