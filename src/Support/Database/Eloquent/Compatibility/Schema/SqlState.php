<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

enum SqlState: string
{
    case Refused = 'CM001';
    case SerializationFailure = '40001';
}
