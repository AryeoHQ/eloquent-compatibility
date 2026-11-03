<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

final class ServesCompatibilityOnNonModel implements Compatible
{
    use ServesCompatibility;

    public static function compatibility(Compatibility $compatibility): Compatibility
    {
        return $compatibility;
    }
}
