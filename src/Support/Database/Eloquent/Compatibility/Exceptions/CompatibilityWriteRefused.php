<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Stringable;
use Support\Database\Eloquent\Compatibility\Definition\Guard;
use Throwable;

/**
 * @internal
 */
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

    public static function roundTrip(string $model, string $attribute, int|string $key, mixed $written, mixed $served, bool $hidden = false, null|Throwable $previous = null): self
    {
        return new self(
            refused: "{$model} record {$key} cannot take this write of \${$attribute}",
            because: 'The write stores '.self::shown($written).', and '.($hidden ? "the record, which a hide() rule now hides, reads {$attribute} as " : "the view now serves {$attribute} as ").self::shown($served).', so the record would not read back what was written.',
            instead: "Make the operation of {$attribute} store a value its read serves back.",
            previous: $previous,
        );
    }

    public static function roundTripLost(string $model, int|string $key): self
    {
        return new self(
            refused: "{$model} record {$key} cannot take this write, because no row holds the record after it",
            because: "Read without its hide() rules, no row serves record {$key} once the write has saved, so a listener or a trigger removed the record the write stored.",
            instead: 'Keep the successors of the record in place while a write through '.class_basename($model).' runs.',
        );
    }

    /**
     * @param  class-string<Model>  $successor
     * @param  list<string>  $scoped
     * @param  list<string>  $locked
     */
    public static function successorsMoved(string $model, string $successor, array $scoped, array $locked): SuccessorsMoved
    {
        $keys = fn (array $keys): string => '['.implode(
            separator: ', ',
            array: array_slice(
                array: $keys,
                offset: 0,
                length: 10,
            ),
        ).(count($keys) > 10 ? ', and '.(count($keys) - 10).' more' : '').']';
        $counted = fn (array $keys): string => count($keys).(count($keys) === 1 ? ' key' : ' keys');

        return new SuccessorsMoved("{$model} cannot take this write now, because its ".class_basename($successor).' rows moved after the write scope ran. Why: The write scope received '.$counted($scoped).', and the package then locked '.$counted($locked).'. It locked '.$keys(array_values(array_diff($locked, $scoped))).', which the scope never received, and no longer found '.$keys(array_values(array_diff($scoped, $locked))).', so it would write a row the scope never ordered. Instead: Retry the write in a new transaction; the outermost transaction of the application is the retry point.');
    }

    /**
     * @param  class-string  $scope
     */
    public static function unscoped(string $model, string $scope): self
    {
        $name = class_basename($scope);

        return new self(
            refused: "{$model} cannot take this write, because its write scope {$name} returned without running it",
            because: "The package runs the write inside {$name}::run(), and run() returned without calling \$write, so the write would report success and store nothing.",
            instead: "Make {$name}::run() call \$write and return its result.",
        );
    }

    /**
     * @param  class-string<Model>  $root
     * @param  list<class-string<Model>>  $raised
     * @param  list<class-string<Model>>  $joined
     */
    public static function membershipMoved(string $root, array $raised, array $joined): self
    {
        $named = fn (array $models): string => $models === [] ? 'no legacy model' : implode(' and ', array_map(class_basename(...), $models));
        $name = class_basename($root);

        return new self(
            "{$root} cannot take this create, because its legacy model changed between its creating event and its insert",
            'The package raised the legacy saving and creating events to '.$named($raised).", and the inserted {$name} row joins ".$named($joined).', so a column that when() reads changed after the creating event.',
            "Set every column that when() reads before the insert, in the create's attributes or in a listener of {$name}, rather than in a database default or trigger.",
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

    /**
     * @param  class-string<Model>  $successor
     * @param  class-string<Model>  $root
     */
    public static function unrelated(string $model, string $successor, string $root): self
    {
        $name = class_basename($successor);

        return new self(
            "{$model} cannot run an operation that names {$name} through its compatibility map",
            "The map relates no {$name} under its root ".class_basename($root).', so the unit of work holds no such successor.',
            "Relate {$name} under ".class_basename($root).' in the map of '.class_basename($model).', or make the operation name a successor the map relates.',
        );
    }

    /**
     * @param  class-string<Model>  $successor
     */
    public static function forwardedOnly(string $model, string $method, string $successor, null|Model $forwarding): self
    {
        $saved = $forwarding === null ? 'a successor' : class_basename($forwarding);

        return new self(
            "{$model} cannot run an operation that calls {$method} for ".class_basename($successor).' from a forwarded legacy event through its compatibility map',
            "A forwarded event belongs to the save of {$saved}, so the operation reaches that successor alone and the package saves nothing else.",
            "Make the operation reach only {$saved} when a forwarded event runs it, or write the attribute through ".class_basename($model).', whose write runs the whole unit of work.',
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

    public static function byGuard(string $model, Guard $guard, int|string $key): self
    {
        return new self("{$model} record {$key} cannot take this write, because guard '{$guard->name}' refuses it", $guard->because, $guard->instead);
    }

    private static function shown(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => "'".$value."'",
            $value instanceof BackedEnum => "'".$value->value."'",
            $value instanceof Stringable => "'".$value."'",
            default => get_debug_type($value),
        };
    }

    public static function bySuccessorListener(string $model, Model $successor, null|int|string $key = null): self
    {
        $name = class_basename($successor);
        $successorKey = $successor->getKey();

        return new self(
            refused: ($key === null ? $model : "{$model} record {$key}").' cannot take this write, because a listener of '.$name.(is_int($successorKey) || is_string($successorKey) ? " {$successorKey}" : '').' refused its save',
            because: "The write stores its values through {$name}, and that model's own listeners refused them.",
            instead: "Make the write satisfy the rules of {$name}.",
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
