<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

enum DriftCheck: string
{
    case PreviousWithoutCurrent = 'previous without current';
    case OrphanCurrent = 'orphan current';
    case MoreThanOnePrimary = 'more than one primary';
    case RefusedState = 'refused state';
    case Designation = 'designation';
}
