<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Support\Database\Eloquent\Compatibility\Contracts\InitializesSuccessors;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Origin;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements InitializesSuccessors<PlaceAsset>
 */
final class ClassifyPlaceAsset implements InitializesSuccessors
{
    /** @var list<Origin> */
    public static array $origins = [];

    public function initialize(Successors $successors, Creation $creation): void
    {
        self::$origins[] = $creation->origin;
        $successors->root()->legacy_type = $creation->record->getMorphClass();
    }
}
