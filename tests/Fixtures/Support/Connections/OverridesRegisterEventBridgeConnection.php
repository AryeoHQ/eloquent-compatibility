<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Connections;

use Closure;
use Illuminate\Database\PostgresConnection;
use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;

final class OverridesRegisterEventBridgeConnection extends PostgresConnection
{
    use DispatchesQueryFailed;

    public function registerEventBridge(Closure $start, Closure $open, Closure $release): void {}
}
