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

    /**
     * @param  class-string<Model>  $model
     */
    public static function writes(string $model, int|string $key): bool
    {
        foreach (self::$writes ?? [] as $legacy) {
            $written = $legacy->getRawOriginal($legacy->getKeyName()) ?? $legacy->getKey();

            if ($legacy::class === $model && (is_int($written) || is_string($written)) && (string) $written === (string) $key) {
                return true;
            }
        }

        return false;
    }
}
