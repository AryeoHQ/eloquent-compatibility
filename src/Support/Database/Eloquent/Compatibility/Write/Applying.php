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

    /** @var null|WeakMap<Model, int|string> */
    private static null|WeakMap $keys = null;

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

    public static function identified(Model $legacy, int|string $key): void
    {
        $keys = self::$keys ??= new WeakMap;
        $keys[$legacy] = $key;
    }

    public static function unidentified(Model $legacy): void
    {
        if (self::$keys !== null && self::$keys->offsetExists($legacy)) {
            self::$keys->offsetUnset($legacy);
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @param  null|Closure(Model): (null|int|string)  $identify
     */
    public static function writes(string $model, int|string $key, string $connection, null|Closure $identify = null): bool
    {
        $keyName = null;

        foreach (self::$writes ?? [] as $successor => $legacy) {
            if ($legacy::class !== $model || $successor->getConnection()->getName() !== $connection) {
                continue;
            }

            $keyName ??= (new $model)->getKeyName();
            $written = array_filter([$legacy->getRawOriginal($keyName), $legacy->getAttribute($keyName)], fn (mixed $written) => is_int($written) || is_string($written));

            foreach ($written === [] ? [self::identity($legacy, $successor, $identify)] : $written as $held) {
                if ($held !== null && (string) $held === (string) $key) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  null|Closure(Model): (null|int|string)  $identify
     */
    private static function identity(Model $legacy, Model $successor, null|Closure $identify): null|int|string
    {
        if (self::$keys !== null && self::$keys->offsetExists($legacy)) {
            return self::$keys[$legacy];
        }

        $key = $identify === null ? null : $identify($successor);

        if ($key !== null) {
            self::identified($legacy, $key);
        }

        return $key;
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
