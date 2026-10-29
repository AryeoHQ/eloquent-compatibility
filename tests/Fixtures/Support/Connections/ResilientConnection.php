<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Connections;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;
use Throwable;

final class ResilientConnection extends PostgresConnection
{
    use DispatchesQueryFailed;

    public int $retries = 0;

    private bool $retrying = false;

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    protected function run($query, $bindings, Closure $callback)
    {
        $result = parent::run($query, $bindings, $callback);

        if ($this->transactions < 1) {
            $this->retrying = false;
        }

        return $result;
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    protected function tryAgainIfCausedByLostConnection(QueryException $e, $query, $bindings, Closure $callback)
    {
        if (! $this->causedByLostConnection($e->getPrevious()) || $this->retrying) {
            return parent::tryAgainIfCausedByLostConnection($e, $query, $bindings, $callback);
        }

        $this->retrying = true;

        try {
            return retry(
                times: 2,
                callback: function () use ($query, $bindings, $callback): mixed {
                    $this->retries++;
                    $this->reconnect();

                    return $this->runQueryCallback($query, $bindings, $callback);
                },
                when: fn (Throwable $retry) => $this->causedByLostConnection($retry->getPrevious() ?? $retry),
            );
        } finally {
            $this->retrying = false;
        }
    }
}
