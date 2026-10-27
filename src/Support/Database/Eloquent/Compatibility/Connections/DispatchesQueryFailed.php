<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Connections;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use ReflectionFunction;
use Support\Database\Eloquent\Compatibility\Bridge\EventBridge;
use Support\Database\Eloquent\Compatibility\Connections\Events\QueryFailed;

/**
 * @phpstan-require-extends Connection
 */
trait DispatchesQueryFailed
{
    private bool $compatibilityDispatchingQueryFailed = false;

    private null|QueryException $compatibilityDispatchedQueryFailure = null;

    /**
     * @param  Closure(QueryFailed): mixed  $callback
     */
    public function listenForFailures(Closure $callback): void
    {
        $this->events?->listen(QueryFailed::class, $callback);
    }

    /**
     * @return $this
     */
    public function beforeExecuting(Closure $callback)
    {
        if ((new ReflectionFunction($callback))->getClosureThis() instanceof EventBridge) {
            array_unshift($this->beforeExecutingCallbacks, $callback);

            return $this;
        }

        return parent::beforeExecuting($callback);
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     *
     * @throws QueryException
     */
    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        try {
            return parent::handleQueryException($e, $query, $bindings, $callback);
        } catch (QueryException $failure) {
            if (! $this->compatibilityDispatchingQueryFailed && $failure !== $this->compatibilityDispatchedQueryFailure) {
                $this->compatibilityDispatchingQueryFailed = true;
                $this->compatibilityDispatchedQueryFailure = $failure;

                try {
                    $this->event(new QueryFailed($query, $bindings, null, $this, $failure, $this->latestReadWriteTypeUsed()));
                } finally {
                    $this->compatibilityDispatchingQueryFailed = false;
                }
            }

            throw $failure;
        }
    }
}
