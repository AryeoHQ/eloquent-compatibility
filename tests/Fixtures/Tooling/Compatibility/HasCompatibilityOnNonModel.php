<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

final class HasCompatibilityOnNonModel implements Compatible
{
    use HasCompatibility;
}
