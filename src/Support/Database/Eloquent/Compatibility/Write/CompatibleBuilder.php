<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The Eloquent builder HasCompatibility gives a mapped model that names no builder of its own.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class CompatibleBuilder extends Builder
{
    use RoutesCompatibleWrites;
}
