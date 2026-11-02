<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;

final class ListingTables
{
    public const string COMPANY = '0b5a4c7e-1f2d-4c3b-9a8e-7d6c5b4a3f21';

    public static function create(): void
    {
        self::drop();

        Schema::create('jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('name');
            $table->uuid('company_id');
            $table->integer('price_cents')->nullable();
            $table->string('lifecycle_status')->default('drafting');
            $table->uuid('merged_into_job_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::table('jobs', function (Blueprint $table): void {
            $table->foreign('merged_into_job_id')->references('id')->on('jobs');
        });

        Schema::create('places', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->foreignUuid('job_id')->constrained('jobs')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('street');
            $table->string('city')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::table('places', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('places');
        });

        Schema::create('job_sites', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->foreignUuid('job_id')->unique()->constrained('jobs')->cascadeOnDelete();
            $table->double('latitude')->nullable();
            $table->timestampsTz();
        });

        Schema::create('listings', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('title');
            $table->uuid('company_id');
            $table->decimal('price', 10, 2)->nullable();
            $table->string('status')->default('Draft');
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->double('latitude')->nullable();
            $table->string('mls_number')->nullable();
            $table->boolean('featured')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('listing_photos', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('listing_id');
            $table->string('path');
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
        });

        Schema::create('listing_shares', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('listing_uuid')->nullable();
            $table->foreign('listing_uuid')->references('uuid')->on('listings')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public static function seed(): void
    {
        DB::table('listings')->insert([
            ['title' => 'Oak House', 'company_id' => self::COMPANY, 'price' => '1250.50', 'status' => 'Active', 'street' => '1 Oak St', 'city' => 'Seattle', 'latitude' => 47.6062, 'mls_number' => 'MLS-1', 'featured' => true, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 11:30:00', 'deleted_at' => null],
            ['title' => 'Pine Flat', 'company_id' => self::COMPANY, 'price' => null, 'status' => 'Draft', 'street' => null, 'city' => null, 'latitude' => null, 'mls_number' => null, 'featured' => false, 'created_at' => '2026-01-03 09:15:00', 'updated_at' => '2026-01-03 09:15:00', 'deleted_at' => '2026-02-01 00:00:00'],
            ['title' => 'Elm Cottage', 'company_id' => self::COMPANY, 'price' => '99.99', 'status' => 'Sold', 'street' => '3 Elm St', 'city' => null, 'latitude' => -122.3321, 'mls_number' => 'MLS-3', 'featured' => false, 'created_at' => '2026-01-04 08:00:00', 'updated_at' => '2026-01-05 08:00:00', 'deleted_at' => null],
        ]);

        DB::table('listing_photos')->insert([
            ['listing_id' => 1, 'path' => 'oak/front.jpg'],
            ['listing_id' => 1, 'path' => 'oak/back.jpg'],
            ['listing_id' => 3, 'path' => 'elm/front.jpg'],
        ]);

        DB::table('listing_shares')->insert([
            ['listing_uuid' => DB::table('listings')->where('id', 1)->value('uuid')],
            ['listing_uuid' => null],
        ]);
    }

    public static function backfill(): void
    {
        DB::statement(
            "insert into jobs (legacy_id, legacy_uuid, name, company_id, price_cents, lifecycle_status, created_at, updated_at, deleted_at)
             select id, uuid, title, company_id, round(price * 100), case status when 'Draft' then 'drafting' when 'Active' then 'live' when 'Sold' then 'closed' end, created_at, updated_at, deleted_at
             from listings",
        );
        DB::statement('insert into places (job_id, street, city) select jobs.id, listings.street, listings.city from listings join jobs on jobs.legacy_id = listings.id where listings.street is not null');
        DB::statement('insert into job_sites (job_id, latitude) select jobs.id, listings.latitude from listings join jobs on jobs.legacy_id = listings.id where listings.latitude is not null');
    }

    public static function drop(): void
    {
        DB::statement('drop table if exists listing_photos, listing_shares cascade');

        if (Schema::hasView('listings')) {
            DB::statement('drop view listings');
        }

        DB::statement('drop table if exists listings, listings_retained, listings_backfilled, job_sites, places, jobs cascade');
        LegacyBuilder::forgetViews();
    }
}
