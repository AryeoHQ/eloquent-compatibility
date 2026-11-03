<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

abstract class ValidModel extends Model implements Compatible
{
    use ServesCompatibility;

    public static function compatibility(Compatibility $compatibility): Compatibility
    {
        return $compatibility;
    }
}
