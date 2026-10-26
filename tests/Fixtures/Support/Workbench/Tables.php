<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final class Tables
{
    public static function create(): void
    {
        Schema::create('gadgets', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('name');
            $table->integer('price_cents')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('gadget_places', function (Blueprint $table): void {
            $table->uuid('id')->default(new Expression('gen_random_uuid()'));
            $table->primary('id');
            $table->foreignUuid('gadget_id')->constrained('gadgets');
            $table->foreignUuid('parent_id')->nullable()->constrained('gadget_places');
            $table->string('street');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('gadget_sites', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->foreignUuid('gadget_id')->unique()->constrained('gadgets');
            $table->decimal('latitude', 24, 12);
            $table->decimal('longitude', 24, 12)->default(0);
            $table->timestampsTz();
        });

        Schema::create('retained_widgets', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('sku')->nullable();
            $table->string('status')->default('Draft');
            $table->foreignUuid('gadget_id')->nullable()->constrained('gadgets');
            $table->uuid('place_id')->nullable();
        });

        Schema::create('people', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('email');
            $table->string('display_name')->nullable();
            $table->timestampsTz();
        });

        Schema::create('retained_accounts', function (Blueprint $table): void {
            $table->increments('id');
            $table->foreignUuid('person_id')->nullable()->constrained('people');
            $table->string('password_hint')->nullable();
        });

        Schema::create('retained_profiles', function (Blueprint $table): void {
            $table->increments('id');
            $table->foreignUuid('person_id')->nullable()->constrained('people');
            $table->text('bio')->nullable();
        });
    }

    public static function createItems(): void
    {
        Schema::create('Gadget-Items', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('label');
        });

        Schema::create('retained_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->foreignUuid('item_id')->nullable()->constrained('Gadget-Items');
        });
    }

    public static function drop(): void
    {
        foreach ([new WidgetCompatibilityMap, new AccountCompatibilityMap, new ProfileCompatibilityMap] as $map) {
            Schema::getConnection()->unprepared('DROP VIEW IF EXISTS "'.$map->previousModel()->view.'" CASCADE');
        }

        foreach (['retained_widgets', 'gadget_sites', 'gadget_places', 'gadgets', 'retained_accounts', 'retained_profiles', 'people'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach ([new WidgetCompatibilityMap, new AccountCompatibilityMap, new ProfileCompatibilityMap] as $map) {
            $prefix = SqlFragments::like(ResolvedMap::of($map)->objectPrefix());

            Schema::getConnection()->unprepared(
                "DO \$drop\$ DECLARE trigger_function regprocedure; BEGIN
                   FOR trigger_function IN SELECT procedure.oid::regprocedure FROM pg_proc procedure WHERE procedure.proname LIKE {$prefix} AND procedure.pronamespace = current_schema()::regnamespace LOOP
                     EXECUTE 'DROP FUNCTION IF EXISTS ' || trigger_function || ' CASCADE';
                   END LOOP;
                 END \$drop\$",
            );
        }
    }
}
