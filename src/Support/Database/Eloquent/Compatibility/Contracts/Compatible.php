<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Compatibility;

/**
 * @phpstan-require-extends Model
 */
interface Compatible
{
    public static function compatibility(Compatibility $compatibility): Compatibility;
}
