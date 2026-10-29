<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Connections;

use Closure;
use Illuminate\Database\PostgresConnection;
use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;

final class OverridesBeforeExecutingConnection extends PostgresConnection
{
    use DispatchesQueryFailed;

    /**
     * @return $this
     */
    public function beforeExecuting(Closure $callback)
    {
        $this->beforeExecutingCallbacks[] = $callback;

        return $this;
    }
}
