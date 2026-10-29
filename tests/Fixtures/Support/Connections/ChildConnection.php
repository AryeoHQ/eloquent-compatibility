<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Connections;

use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;

final class ChildConnection extends ParentConnection
{
    use DispatchesQueryFailed;
}
