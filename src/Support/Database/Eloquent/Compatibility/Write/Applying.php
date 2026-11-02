<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Database\Eloquent\Model;
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

        try {
            return $write();
        } finally {
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

    /**
     * @param  class-string<Model>  $model
     * @param  null|Closure(Model): (null|int|string)  $identify
     */
    public static function writes(string $model, int|string $key, null|Closure $identify = null): bool
    {
        foreach (self::$writes ?? [] as $successor => $legacy) {
            if ($legacy::class !== $model) {
                continue;
            }

            $written = $legacy->getRawOriginal($legacy->getKeyName()) ?? $legacy->getKey() ?? self::identity($legacy, $successor, $identify);

            if ((is_int($written) || is_string($written)) && (string) $written === (string) $key) {
                return true;
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
}
