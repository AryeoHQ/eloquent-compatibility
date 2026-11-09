<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

final class LeaveTitleUnchanged implements WritesAttribute
{
    /**
     * @param  Successors<Job>  $successors
     */
    public function write(Successors $successors, mixed $value, Change $change): void {}
}
