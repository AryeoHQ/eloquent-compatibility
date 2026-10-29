<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Connections;

final class WrappedChildConnection extends ParentConnection
{
    use WrapsDispatchesQueryFailed;
}
