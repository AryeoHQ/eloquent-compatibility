<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Definition\Guard;

final class CompatibilityWriteRefused extends CompatibilityException
{
    public function __construct(public readonly string $refused, public readonly string $because, public readonly string $instead)
    {
        parent::__construct("{$refused}. Why: {$because} Instead: {$instead}");
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
            "{$model}::\${$attribute} cannot take a write",
            "It is declared transitions(), so a write must run the successor's state transition, and this release runs no transition yet.",
            "Change the state through the successor model's own transition.",
        );
    }

    public static function external(string $model, string $attribute): self
    {
        return new self(
            "{$model}::\${$attribute} cannot take a write",
            'It is declared external(), so its value lives outside the database, and this release writes no external value yet.',
            'Write the value through the successor model.',
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
