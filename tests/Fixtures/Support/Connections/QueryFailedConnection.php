<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Connections;

use Illuminate\Database\PostgresConnection;
use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;

final class QueryFailedConnection extends PostgresConnection
{
    use DispatchesQueryFailed;
}
