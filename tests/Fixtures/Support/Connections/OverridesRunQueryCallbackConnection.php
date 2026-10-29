<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Connections;

use Closure;
use Illuminate\Database\PostgresConnection;
use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;

final class OverridesRunQueryCallbackConnection extends PostgresConnection
{
    use DispatchesQueryFailed;

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        return $callback($query, $bindings);
    }
}
