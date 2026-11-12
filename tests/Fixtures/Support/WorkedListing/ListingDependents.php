<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Illuminate\Database\Connection;
use Support\Database\Eloquent\Compatibility\Contracts\ManagesDependents;

final class ListingDependents implements ManagesDependents
{
    public function beforeInstall(Connection $connection): void
    {
        $connection->statement('DROP VIEW job_sites_view');
    }

    public function afterInstall(Connection $connection): void
    {
        $connection->statement(JobSiteViewSql::overServedListings());
    }

    public function beforeRollback(Connection $connection): void
    {
        $connection->statement('DROP VIEW job_sites_view');
    }

    public function afterRollback(Connection $connection): void
    {
        $connection->statement(JobSiteViewSql::overLegacyListings());
    }
}
