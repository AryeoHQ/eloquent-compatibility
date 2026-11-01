<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Throwable;

/**
 * A write the write router refused before or during its transaction. The transaction rolls back, so nothing of the
 * write is stored.
 */
final class CompatibilityWriteRefused extends CompatibilityException
{
    public function __construct(
        public readonly string $refused,
        public readonly string $because,
        public readonly string $instead,
        null|Throwable $previous = null,
    ) {
        parent::__construct("{$refused}. Why: {$because} Instead: {$instead}", 0, $previous);
    }

    public static function readOnly(string $map, string $view, string $column, int|string $key, string $because): self
    {
        return new self(
            "Compatibility map '{$map}' refused a change to {$view}.{$column} on record {$key}",
            $because,
            "Leave {$column} unchanged in writes through the previous model.",
        );
    }

    public static function nullValue(string $map, string $view, string $column, null|int|string $key, string $retainedTable): self
    {
        return new self(
            "Compatibility map '{$map}' refused a null {$view}.{$column} on ".($key === null ? 'a new record' : "record {$key}"),
            "{$retainedTable}.{$column} is NOT NULL, so the previous model never stores null.",
            "Write a value to {$column}, or omit it from an insert to take its default.",
        );
    }

    /**
     * @param  list<string>  $columns
     */
    public static function unknownAttributes(string $map, string $view, array $columns): self
    {
        $names = implode(', ', $columns);

        return new self(
            "Compatibility map '{$map}' refused a write of {$names} through {$view}",
            'The compatibility map declares no attribute of that name, so the write has nowhere to store it.',
            'Declare the attribute in the compatibility map, or leave it out of the write.',
        );
    }

    public static function missingRow(string $map, string $view, int|string $key, string $table): self
    {
        return new self(
            "Compatibility map '{$map}' refused a write to {$view} record {$key}: its {$table} row is missing",
            'The record has nowhere to store the change, so the previous and current models have diverged.',
            "Run php artisan compatibility:check --map={$map} and repair the record it names.",
        );
    }

    /**
     * @param  list<string>  $columns
     */
    public static function noDependentRow(string $map, string $view, int|string $key, array $columns, string $table): self
    {
        return new self(
            "Compatibility map '{$map}' refused a change to ".implode(', ', $columns)." on {$view} record {$key}",
            "The record has no {$table} row, and the compatibility map does not create one for this write.",
            "Create the {$table} row through its own model first, or declare createdWhen() for this dependent current model.",
        );
    }

    /**
     * @param  list<string>  $columns
     */
    public static function designationMismatch(string $map, string $view, int|string $key, array $columns, string $table, string $designation, string $readableWhen): self
    {
        return new self(
            "Compatibility map '{$map}' refused a change to ".implode(', ', $columns)." on {$view} record {$key}: its designated {$table} row is not one the previous model reads",
            "The record reads only the {$table} row that {$designation} names, and that row no longer matches ({$readableWhen}) or belongs to another record.",
            "Make that {$table} row match again through its own model, or repair {$designation} by hand.",
        );
    }

    public static function refusedState(string $map, string $description, string $table, int|string $key, string $because, string $instead): self
    {
        return new self("Compatibility map '{$map}' refused {$description} in {$table} record {$key}", $because, $instead);
    }

    public static function refusedPreviousUpdate(string $map, string $description, string $view, int|string $key, string $because, string $instead): self
    {
        return new self("Compatibility map '{$map}' refused {$description} on {$view} record {$key}", $because, $instead);
    }

    public static function rawExpression(string $map, string $view, string $column): self
    {
        return new self(
            "Compatibility map '{$map}' refused a raw SQL value for {$view}.{$column}",
            'The write router computes each current column from the attribute values in PHP, so it cannot split a SQL expression across the current models.',
            "Read the record and write the computed value, or write the current model's column through its own model.",
        );
    }

    public static function unsupported(string $map, string $relation, string $method): self
    {
        return new self(
            "Compatibility map '{$map}' refused {$method}() on {$relation}",
            "{$method}() writes rows the write router cannot see one by one, so it cannot keep the previous and current models in step.",
            'Write each record through the model, or through insert(), update() or delete() on its query builder.',
        );
    }

    public static function byCurrentListener(string $map, Model $current): self
    {
        $record = class_basename($current).' '.self::key($current);

        return new self(
            "Compatibility map '{$map}' refused this write, because a listener of {$record} vetoed its save",
            "The write through the previous model stores its values in {$record}, and that model's own listeners refused them.",
            'Make the write satisfy the rules of '.class_basename($current).'.',
        );
    }

    public static function byMirroredListener(string $map, Model $destination, ModelEvent $event): self
    {
        $record = class_basename($destination).' '.self::key($destination);

        return new self(
            "Compatibility map '{$map}' refused this write, because a '{$event->value}' listener of {$record} returned false",
            "The write changed {$record} through the other side, and every listener of that side runs for it, so that side's rules refused the change.",
            'Make the write satisfy the rules of '.class_basename($destination).", or exclude the listener in the map's event mirror if it must not run for writes through the other side.",
        );
    }

    /**
     * @param  list<string>  $columns
     */
    public static function byMirroredChange(string $map, Model $destination, ModelEvent $event, array $columns): self
    {
        $record = class_basename($destination).' '.self::key($destination);

        return new self(
            "Compatibility map '{$map}' refused this write, because a '{$event->value}' listener of {$record} changed ".implode(', ', $columns),
            'Mirrored events fire after the write through the other side has reached the database, so a change a listener makes to the mirrored record cannot be stored.',
            'Make the change through '.class_basename($destination)." itself, or exclude the listener in the map's event mirror.",
        );
    }

    private static function key(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? '#'.$key : '(new)';
    }
}
