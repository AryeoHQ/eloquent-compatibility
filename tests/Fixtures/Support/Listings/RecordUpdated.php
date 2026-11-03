<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class RecordUpdated
{
    public static int $constructed = 0;

    public function __construct(public readonly Model $model)
    {
        if (++self::$constructed > 50) {
            throw new RuntimeException('RecordUpdated was constructed more than 50 times in one test.');
        }
    }
}
