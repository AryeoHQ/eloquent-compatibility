<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Throwable;

/**
 * @internal
 */
final class ScopedWrite
{
    public bool $ran = false;

    public null|Throwable $failure = null;

    /**
     * @param  Closure(): mixed  $write
     */
    public function __construct(private readonly Closure $write) {}

    public function __invoke(): mixed
    {
        $this->ran = true;

        try {
            return ($this->write)();
        } catch (Throwable $exception) {
            $this->failure = $exception;

            throw $exception;
        }
    }
}
