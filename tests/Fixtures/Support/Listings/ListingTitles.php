<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Connection;
use Support\Database\Eloquent\Compatibility\Contracts\ManagesDependents;

final class ListingTitles implements ManagesDependents
{
    public const string VIEW = 'create view listing_titles as select id, title from listings';

    public function beforeInstall(Connection $connection): void
    {
        $connection->statement('drop view listing_titles');
    }

    public function afterInstall(Connection $connection): void
    {
        $connection->statement(self::VIEW);
    }

    public function beforeRollback(Connection $connection): void
    {
        $connection->statement('drop view listing_titles');
    }

    public function afterRollback(Connection $connection): void
    {
        $connection->statement(self::VIEW);
    }
}
