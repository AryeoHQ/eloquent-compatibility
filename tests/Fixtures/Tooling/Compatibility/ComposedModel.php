<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;

abstract class ComposedModel extends Model implements Compatible
{
    use ComposedTrait;

    public static function compatibility(Compatibility $compatibility): Compatibility
    {
        return $compatibility;
    }
}
