<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Connections;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;

final class OverridesHandleQueryExceptionConnection extends PostgresConnection
{
    use DispatchesQueryFailed;

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        if ($this->transactions >= 1) {
            throw $e;
        }

        return $this->tryAgainIfCausedByLostConnection($e, $query, $bindings, $callback);
    }
}
