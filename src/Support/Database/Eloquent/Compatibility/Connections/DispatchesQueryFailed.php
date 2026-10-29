<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Connections;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Support\Database\Eloquent\Compatibility\Connections\Events\QueryFailed;
use Throwable;
use WeakReference;

/**
 * @phpstan-require-extends Connection
 */
trait DispatchesQueryFailed
{
    private bool $compatibilityDispatchingQueryFailed = false;

    /** @var null|WeakReference<QueryException> */
    private null|WeakReference $compatibilityDispatchedQueryFailure = null;

    /** @var null|array{Closure(string, array<array-key, mixed>, Connection): void, Closure(string, array<array-key, mixed>, Connection): void, Closure(string, array<array-key, mixed>, Connection): void} */
    private null|array $compatibilityEventBridge = null;

    /**
     * @param  Closure(QueryFailed): mixed  $callback
     */
    public function listenForFailures(Closure $callback): void
    {
        $this->events?->listen(QueryFailed::class, $callback);
    }

    /**
     * @param  Closure(string, array<array-key, mixed>, Connection): void  $start
     * @param  Closure(string, array<array-key, mixed>, Connection): void  $open
     * @param  Closure(string, array<array-key, mixed>, Connection): void  $release
     */
    public function registerEventBridge(Closure $start, Closure $open, Closure $release): void
    {
        $this->compatibilityEventBridge = [$start, $open, $release];
        $this->orderEventBridgeCallbacks();
    }

    /**
     * @return $this
     */
    public function beforeExecuting(Closure $callback)
    {
        parent::beforeExecuting($callback);
        $this->orderEventBridgeCallbacks();

        return $this;
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
            if (self::usesDispatchesQueryFailedBelow()) {
                throw $failure;
            }

            if ($this->compatibilityEventBridge !== null) {
                $this->compatibilityEventBridge[2]($query, $bindings, $this);
            }

            if (! $this->compatibilityDispatchingQueryFailed && $failure !== $this->compatibilityDispatchedQueryFailure?->get()) {
                $this->compatibilityDispatchingQueryFailed = true;
                $this->compatibilityDispatchedQueryFailure = WeakReference::create($failure);

                try {
                    $this->event(new QueryFailed($query, $bindings, null, $this, $failure, $this->latestReadWriteTypeUsed()));
                } catch (Throwable $listenerFailure) {
                    report($listenerFailure);
                } finally {
                    $this->compatibilityDispatchingQueryFailed = false;
                }
            }

            throw $failure;
        }
    }

    private static function usesDispatchesQueryFailedBelow(): bool
    {
        foreach ([static::class, ...class_parents(static::class)] as $class) {
            if ($class === self::class) {
                return false;
            }

            if (in_array(DispatchesQueryFailed::class, class_uses($class), true)) {
                return true;
            }
        }

        return false;
    }

    private function orderEventBridgeCallbacks(): void
    {
        if ($this->compatibilityEventBridge === null) {
            return;
        }

        [$start, $open] = $this->compatibilityEventBridge;
        $others = array_filter($this->beforeExecutingCallbacks, fn (Closure $callback) => $callback !== $start && $callback !== $open);
        $this->beforeExecutingCallbacks = [$start, ...$others, $open];
    }
}
