<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ListingTables
{
    /**
     * The schema after a migration renamed listings to listings_retained and moved its columns to the current models.
     */
    public static function create(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('name');
            $table->string('source');
            $table->integer('price_cents')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('places', function (Blueprint $table): void {
            $table->uuid('id')->default(new Expression('gen_random_uuid()'));
            $table->primary('id');
            $table->foreignUuid('job_id')->constrained('jobs');
            $table->foreignUuid('parent_id')->nullable()->constrained('places');
            $table->string('street');
            $table->string('city')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('job_sites', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->foreignUuid('job_id')->unique()->constrained('jobs');
            $table->double('latitude');
            $table->timestampsTz();
        });

        Schema::create('listings_retained', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('mls_number')->nullable();
            $table->string('status')->default('Draft');
            $table->foreignUuid('job_id')->nullable()->constrained('jobs');
            $table->uuid('place_id')->nullable();
        });
    }

    public static function drop(): void
    {
        DB::statement('drop view if exists listings');
        DB::statement('drop table if exists listings_retained, job_sites, places, jobs cascade');
    }
}
