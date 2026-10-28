<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

enum Operation
{
    case Insert;
    case Update;
    case Delete;
}
