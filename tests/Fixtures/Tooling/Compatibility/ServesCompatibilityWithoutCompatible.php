<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

final class ServesCompatibilityWithoutCompatible extends Model
{
    use ServesCompatibility;
}
