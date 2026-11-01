<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Compatibility;

/**
 * A previous model that declares its own compatibility map. Installer and WriteRouter then take the model's class.
 *
 * @phpstan-require-extends Model
 */
interface DefinesCompatibility extends Compatible
{
    public static function compatibility(Compatibility $map): Compatibility;
}
