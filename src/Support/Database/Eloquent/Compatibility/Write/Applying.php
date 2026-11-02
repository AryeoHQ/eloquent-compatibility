<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\NullDispatcher;
use ReflectionProperty;
use WeakMap;

final class Applying
{
    /** @var null|WeakMap<Model, Model> */
    private static null|WeakMap $writes = null;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public static function run(Model $legacy, Model $successor, Closure $write): mixed
    {
        $writes = self::$writes ??= new WeakMap;
        $outer = $writes[$successor] ?? null;
        $writes[$successor] = $legacy;
        $dispatcher = Model::getEventDispatcher();
        $quiet = $dispatcher instanceof NullDispatcher;

        if ($quiet) {
            Model::setEventDispatcher(self::loud($dispatcher));
        }

        try {
            return $write();
        } finally {
            if ($quiet) {
                Model::setEventDispatcher($dispatcher);
            }

            if ($outer === null) {
                unset($writes[$successor]);
            } else {
                $writes[$successor] = $outer;
            }
        }
    }

    public static function holds(Model $successor): bool
    {
        return self::$writes !== null && self::$writes->offsetExists($successor);
    }

    private static function loud(NullDispatcher $dispatcher): Dispatcher
    {
        $inner = (new ReflectionProperty(NullDispatcher::class, 'dispatcher'))->getValue($dispatcher);

        return match (true) {
            $inner instanceof NullDispatcher => self::loud($inner),
            $inner instanceof Dispatcher => $inner,
            default => $dispatcher,
        };
    }
}
