<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Connections;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Support\Database\Eloquent\Compatibility\Bridge\PendingMirroredWrite;
use Support\Database\Eloquent\Compatibility\Connections\Events\QueryFailed;
use Throwable;
use WeakMap;
use WeakReference;

/**
 * @phpstan-require-extends Connection
 */
trait DispatchesQueryFailed
{
    private bool $compatibilityDispatchingQueryFailed = false;

    /** @var null|WeakReference<QueryException> */
    private null|WeakReference $compatibilityDispatchedQueryFailure = null;

    /** @var null|Closure(null|PendingMirroredWrite, Connection): void */
    private null|Closure $compatibilityEventBridgeRelease = null;

    /** @var null|WeakReference<PendingMirroredWrite> */
    private null|WeakReference $compatibilityStatementWrite = null;

    /** @var null|WeakMap<QueryException, WeakReference<PendingMirroredWrite>> */
    private null|WeakMap $compatibilityFailedWrites = null;

    /** @var list<Closure> */
    private array $compatibilityBeforeExecutingCallbacks = [];

    /**
     * @param  Closure(QueryFailed): mixed  $callback
     */
    public function listenForFailures(Closure $callback): void
    {
        $this->events?->listen(QueryFailed::class, $callback);
    }

    /**
     * @param  Closure(string, array<array-key, mixed>, Connection): (null|PendingMirroredWrite)  $start
     * @param  Closure(null|PendingMirroredWrite, string, array<array-key, mixed>, Connection): (null|PendingMirroredWrite)  $open
     * @param  Closure(null|PendingMirroredWrite, Connection): void  $release
     */
    public function registerEventBridge(Closure $start, Closure $open, Closure $release): void
    {
        if ($this->compatibilityEventBridgeRelease === null) {
            $this->compatibilityBeforeExecutingCallbacks = array_values($this->beforeExecutingCallbacks);
        }

        $this->compatibilityEventBridgeRelease = $release;
        $this->beforeExecutingCallbacks = [
            function (string &$query, array &$bindings, Connection $connection) use ($start, $open): void { // @phpstan-ignore ergebnis.noParameterPassedByReference, ergebnis.noParameterPassedByReference
                $taken = $start($query, $bindings, $connection);

                foreach ($this->compatibilityBeforeExecutingCallbacks as $callback) {
                    $callback($query, $bindings, $connection);
                }

                $opened = $open($taken, $query, $bindings, $connection);
                $this->compatibilityStatementWrite = $opened === null ? null : WeakReference::create($opened);
            },
        ];
    }

    /**
     * @return $this
     */
    public function beforeExecuting(Closure $callback)
    {
        if ($this->compatibilityEventBridgeRelease === null) {
            return parent::beforeExecuting($callback);
        }

        $this->compatibilityBeforeExecutingCallbacks[] = $callback;

        return $this;
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     *
     * @throws QueryException
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        $statementWrite = $this->compatibilityStatementWrite;
        $this->compatibilityStatementWrite = null;

        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $e) {
            if ($statementWrite !== null) {
                $this->compatibilityFailedWrites ??= new WeakMap;
                $this->compatibilityFailedWrites[$e] = $statementWrite;
            }

            throw $e;
        }
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

            $readWriteType = $this->latestReadWriteTypeUsed();

            if ($this->compatibilityEventBridgeRelease !== null) {
                ($this->compatibilityEventBridgeRelease)(($this->compatibilityFailedWrites[$e] ?? null)?->get(), $this);
            }

            if (! $this->compatibilityDispatchingQueryFailed && $failure !== $this->compatibilityDispatchedQueryFailure?->get()) {
                $this->compatibilityDispatchingQueryFailed = true;
                $this->compatibilityDispatchedQueryFailure = WeakReference::create($failure);

                try {
                    $this->event(new QueryFailed($query, $bindings, null, $this, $failure, $readWriteType));
                } catch (Throwable $listenerFailure) {
                    try {
                        report($listenerFailure);
                    } catch (Throwable) {
                    }
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

            if (in_array(DispatchesQueryFailed::class, trait_uses_recursive($class), true)) {
                return true;
            }
        }

        return false;
    }
}
