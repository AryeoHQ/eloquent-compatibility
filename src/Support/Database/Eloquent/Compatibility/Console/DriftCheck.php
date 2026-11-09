<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

enum DriftCheck: string
{
    case NoPrimary = 'no primary';

    case ValuesDiffer = 'values differ';

    case Misplaced = 'misplaced';

    case StaleView = 'stale view';

    case RetainedWithoutPrimary = 'retained without primary';

    case HeldTwice = 'held twice';

    case NoMorphTarget = 'no morph target';

    case OutsideMembership = 'outside when()';

    case GuardRefuses = 'guard refuses';
}
