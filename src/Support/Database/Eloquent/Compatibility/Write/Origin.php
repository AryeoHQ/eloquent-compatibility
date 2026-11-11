<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

enum Origin
{
    case Live;
    case Backfill;
}
