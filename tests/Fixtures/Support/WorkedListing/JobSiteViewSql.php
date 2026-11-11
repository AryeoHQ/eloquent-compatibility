<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

final class JobSiteViewSql
{
    public static function overServedListings(): string
    {
        return self::over('served');
    }

    public static function overLegacyListings(): string
    {
        return self::over('legacy');
    }

    private static function over(string $listings): string
    {
        return "CREATE VIEW job_sites_view AS SELECT job_sites.id, job_sites.latitude, job_sites.longitude, listings.id AS listing_id, '{$listings}'::text AS listings FROM listings JOIN job_sites ON job_sites.id = listings.job_site_id";
    }
}
