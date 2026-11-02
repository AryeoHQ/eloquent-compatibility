<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;

final class FileTables
{
    public const array MODELS = [Photo::class, FloorPlan::class, LegacyFile::class];

    public static function create(): void
    {
        self::drop();

        Schema::create('files', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('filename');
            $table->string('mime_type');
            $table->bigInteger('size_bytes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('photos', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('file_name');
            $table->string('mime_type');
            $table->string('caption')->nullable();
            $table->integer('views')->default(0);
            $table->decimal('size_kb', 12, 3)->nullable();
            $table->timestamps();
        });

        Schema::create('floorplans', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('file_name');
            $table->string('mime_type');
            $table->integer('level')->nullable();
            $table->timestamps();
        });

        Schema::create('legacy_files', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('file_name');
            $table->string('mime_type');
            $table->timestamps();
        });

        Schema::create('photo_comments', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('photo_id');
            $table->string('body');
            $table->foreign('photo_id')->references('id')->on('photos')->cascadeOnDelete();
        });

        Schema::create('photo_shares', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('photo_uuid')->nullable();
            $table->foreign('photo_uuid')->references('uuid')->on('photos')->nullOnDelete();
        });
    }

    public static function seed(): void
    {
        DB::table('photos')->insert([
            ['file_name' => 'oak-front.jpg', 'mime_type' => 'image/jpeg', 'size_kb' => 512, 'caption' => 'Front', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00'],
            ['file_name' => 'oak-back.jpg', 'mime_type' => 'image/jpeg', 'size_kb' => 2048, 'caption' => null, 'created_at' => '2026-01-02 10:00:00', 'updated_at' => '2026-01-02 10:00:00'],
            ['file_name' => 'elm.png', 'mime_type' => 'image/png', 'size_kb' => 1.5, 'caption' => 'Elm', 'created_at' => '2026-01-03 10:00:00', 'updated_at' => '2026-01-03 10:00:00'],
        ]);

        DB::table('floorplans')->insert([
            ['file_name' => 'oak-plan.pdf', 'mime_type' => 'application/pdf', 'level' => 1, 'created_at' => '2026-01-04 10:00:00', 'updated_at' => '2026-01-04 10:00:00'],
            ['file_name' => 'elm-plan.png', 'mime_type' => 'image/png', 'level' => null, 'created_at' => '2026-01-05 10:00:00', 'updated_at' => '2026-01-05 10:00:00'],
        ]);

        DB::table('legacy_files')->insert([
            ['file_name' => 'contract.pdf', 'mime_type' => 'application/pdf', 'created_at' => '2026-01-06 10:00:00', 'updated_at' => '2026-01-06 10:00:00'],
            ['file_name' => 'notes.txt', 'mime_type' => 'text/plain', 'created_at' => '2026-01-07 10:00:00', 'updated_at' => '2026-01-07 10:00:00'],
        ]);

        DB::table('photo_comments')->insert([['photo_id' => 1, 'body' => 'Bright'], ['photo_id' => 3, 'body' => 'Sharp']]);
        DB::table('photo_shares')->insert([['photo_uuid' => DB::table('photos')->where('id', 1)->value('uuid')], ['photo_uuid' => null]]);
    }

    public static function backfill(): void
    {
        foreach (['photo' => ['photos', 'round(size_kb * 1000)'], 'floorplan' => ['floorplans', 'null'], 'legacy_file' => ['legacy_files', 'null']] as $type => [$table, $size]) {
            DB::statement("insert into files (legacy_type, legacy_id, legacy_uuid, filename, mime_type, size_bytes, created_at, updated_at) select '{$type}', id, uuid, file_name, mime_type, {$size}, created_at, updated_at from {$table}");
        }
    }

    public static function install(): void
    {
        foreach (self::MODELS as $model) {
            Compatibility::prepare($model);
        }

        self::backfill();

        foreach (self::MODELS as $model) {
            Compatibility::install($model);
        }
    }

    public static function drop(): void
    {
        DB::statement('drop table if exists photo_comments, photo_shares cascade');

        foreach (['photos', 'floorplans', 'legacy_files'] as $view) {
            if (Schema::hasView($view)) {
                DB::statement("drop view {$view}");
            }
        }

        DB::statement('drop table if exists photos, photos_retained, floorplans, floorplans_retained, legacy_files, legacy_files_retained, files cascade');
        DB::statement('drop sequence if exists compatibility_legacy_id_seq');
        LegacyBuilder::forgetViews();
    }
}
