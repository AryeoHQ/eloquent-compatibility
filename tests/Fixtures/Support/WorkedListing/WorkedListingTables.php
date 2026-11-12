<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;

final class WorkedListingTables
{
    public const string COMPANY = '5d1c6a2e-8b4f-4e3a-9c7d-2f1e0a9b8c76';

    public const string OAK_PLACE = '7a3e5c1b-2d4f-4a6b-8c9d-0e1f2a3b4c5d';

    public const string OAK_SITE = '8b4f6d2c-3e5a-4b7c-9d0e-1f2a3b4c5d6e';

    public const string PINE_PLACE = '9c5a7e3d-4f6b-4c8d-a0e1-2a3b4c5d6e7f';

    public static function create(): void
    {
        self::drop();

        Schema::create('jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('name');
            $table->uuid('company_id');
            $table->string('lifecycle_status')->default('draft');
            $table->boolean('are_customer_downloads_enabled')->nullable();
            $table->boolean('opted_in_to_zma')->default(false);
            $table->string('payment_status')->default('unpaid');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('addresses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('street');
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('places', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->foreignUuid('job_id')->unique()->constrained('jobs')->cascadeOnDelete();
            $table->uuid('address_id')->nullable();
            $table->string('name')->nullable();
            $table->timestampsTz();
            $table->foreign('address_id')->references('uuid')->on('addresses');
        });

        Schema::create('job_sites', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->foreignUuid('job_id')->unique()->constrained('jobs')->cascadeOnDelete();
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->timestampsTz();
        });

        Schema::create('listing_compatibility_states', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->foreignUuid('job_id')->unique()->constrained('jobs')->cascadeOnDelete();
            $table->boolean('downloads_enabled_fallback')->nullable();
            $table->timestampsTz();
        });

        Schema::create('listings', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->foreignUuid('job_id')->nullable()->constrained('jobs');
            $table->uuid('place_id')->nullable();
            $table->uuid('job_site_id')->nullable();
            $table->bigInteger('address_id')->nullable();
            $table->string('title');
            $table->uuid('company_id');
            $table->boolean('is_draft')->default(true);
            $table->boolean('downloads_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('listing_orders', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('listing_id');
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
        });

        DB::statement(JobSiteViewSql::overLegacyListings());
    }

    public static function seed(): void
    {
        DB::table('addresses')->insert([
            ['street' => '1 Oak St', 'latitude' => 47.6062, 'longitude' => -122.3321],
            ['street' => '2 Pine Rd', 'latitude' => null, 'longitude' => null],
            ['street' => '3 Elm Ave', 'latitude' => 45.5152, 'longitude' => -122.6784],
            ['street' => '4 Ash Ln', 'latitude' => null, 'longitude' => null],
        ]);

        DB::table('listings')->insert([
            ['uuid' => '1f0e2d3c-4b5a-4697-8a7b-6c5d4e3f2a10', 'place_id' => self::OAK_PLACE, 'job_site_id' => self::OAK_SITE, 'address_id' => 1, 'title' => 'Oak House', 'company_id' => self::COMPANY, 'is_draft' => false, 'downloads_enabled' => true, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 11:30:00', 'deleted_at' => null],
            ['uuid' => '2a1f3e4d-5c6b-4708-9b8c-7d6e5f4a3b21', 'place_id' => self::PINE_PLACE, 'job_site_id' => null, 'address_id' => 2, 'title' => 'Pine Flat', 'company_id' => self::COMPANY, 'is_draft' => true, 'downloads_enabled' => false, 'created_at' => '2026-01-03 09:15:00', 'updated_at' => '2026-01-03 09:15:00', 'deleted_at' => '2026-02-01 00:00:00'],
        ]);

        DB::table('listing_orders')->insert([
            ['listing_id' => 1],
        ]);
    }

    public static function drop(): void
    {
        DB::statement('drop view if exists job_sites_view');
        DB::statement('drop table if exists listing_orders cascade');

        if (Schema::hasView('listings')) {
            DB::statement('drop view listings');
        }

        DB::statement('drop table if exists listings, listings_retained, listings_backfill, listing_compatibility_states, job_sites, places, addresses, jobs cascade');
        DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type IN ('listings'); IF NOT EXISTS (SELECT FROM compatibility_backfills) THEN DROP TABLE compatibility_backfills; END IF; END IF; END \$\$");
        LegacyBuilder::forgetViews();
    }
}
