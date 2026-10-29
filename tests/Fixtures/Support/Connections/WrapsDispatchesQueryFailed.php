<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Connections;

use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;

trait WrapsDispatchesQueryFailed
{
    use DispatchesQueryFailed;
}
