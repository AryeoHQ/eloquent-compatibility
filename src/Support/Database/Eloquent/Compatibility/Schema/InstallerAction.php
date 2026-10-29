<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

enum InstallerAction: string
{
    case Install = 'install';
    case Recompile = 'recompile';
    case Uninstall = 'uninstall';
    case Prepare = 'prepare';
}
