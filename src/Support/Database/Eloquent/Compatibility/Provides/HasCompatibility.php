<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Write\CompatibleBuilder;
use Support\Database\Eloquent\Compatibility\Write\RoutesCompatibleWrites;
use Support\Database\Eloquent\Compatibility\Write\WriteRouter;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Compatible
 */
trait HasCompatibility
{
    /**
     * Gives the model a builder that routes its writes. A custom builder must use RoutesCompatibleWrites.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return Builder<static>
     */
    public function newEloquentBuilder($query)
    {
        $builder = parent::newEloquentBuilder($query);

        if (in_array(RoutesCompatibleWrites::class, class_uses_recursive($builder), true)) {
            return $builder;
        }

        if ($builder::class === Builder::class) {
            return new CompatibleBuilder($query);
        }

        throw new LogicException(static::class.' uses HasCompatibility, and its Eloquent builder '.$builder::class.' does not use '.RoutesCompatibleWrites::class.', so its writes would bypass the write router. Use the trait in the builder.');
    }

    /**
     * Marks the builder of the model's own update or delete, so the write router knows the write is this model's.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        parent::setKeysForSaveQuery($query);

        if (method_exists($query, 'compatibilityWriteOf')) {
            $query->compatibilityWriteOf($this);
        }

        return $query;
    }

    /**
     * Marks the model's own insert. Laravel calls this only inside the model's insert, after its creating event.
     *
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        $attributes = parent::getAttributesForInsert();
        $container = Container::getInstance();

        if ($container->resolved(WriteRouter::class)) {
            $container->make(WriteRouter::class)->markInsert($this);
        }

        return $attributes;
    }
}
