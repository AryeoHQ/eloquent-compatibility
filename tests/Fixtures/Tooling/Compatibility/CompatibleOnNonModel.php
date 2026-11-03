<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;

final class CompatibleOnNonModel implements Compatible
{
    public static function compatibility(Compatibility $compatibility): Compatibility
    {
        return $compatibility;
    }
}
