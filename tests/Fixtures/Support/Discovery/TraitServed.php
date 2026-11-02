<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Discovery;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;

final class TraitServed extends Model implements Compatible
{
    use ServesThroughTrait;

    public static function compatibility(Compatibility $compatibility): Compatibility
    {
        return $compatibility;
    }
}
