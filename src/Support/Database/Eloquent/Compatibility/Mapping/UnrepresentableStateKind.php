<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

enum UnrepresentableStateKind: string
{
    case Refuse = 'refuse';
    case Degrade = 'degrade';
    case Hide = 'hide';
}
