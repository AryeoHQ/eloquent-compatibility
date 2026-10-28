<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

final class HasCompatibilityWithoutCompatible extends Model
{
    use HasCompatibility;
}
