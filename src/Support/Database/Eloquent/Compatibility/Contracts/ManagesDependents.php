<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

use Illuminate\Database\Connection;

interface ManagesDependents
{
    public function beforeInstall(Connection $connection): void;

    public function afterInstall(Connection $connection): void;

    public function beforeRollback(Connection $connection): void;

    public function afterRollback(Connection $connection): void;
}
