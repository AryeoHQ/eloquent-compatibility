<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Carbon\CarbonInterval;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;

final class FileTables
{
    public const array MODELS = [Photo::class, LegacyFile::class];

    public const int TIMEOUT = 30;

    public static function create(): void
    {
        self::drop();

        Schema::create('files', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('embed_url')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('zml_asset_id')->nullable();
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

        Schema::create('legacy_files', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('file_name');
            $table->string('mime_type');
            $table->timestamps();
        });

        Schema::create('links', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('url');
            $table->timestampsTz();
        });

        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('original_file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->decimal('size_kb', 12, 3)->nullable();
            $table->string('youtube_embed_url')->nullable();
            $table->string('title')->nullable();
            $table->integer('views')->default(0);
            $table->timestamps();
        });

        Schema::create('interactive_content', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('url');
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
            $table->foreign('photo_uuid')->references('uuid')->on('photos')->cascadeOnDelete();
        });
    }

    public static function seed(): void
    {
        DB::table('photos')->insert([
            ['file_name' => 'oak-front.jpg', 'mime_type' => 'image/jpeg', 'size_kb' => 512, 'caption' => 'Front', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00'],
            ['file_name' => 'oak-back.jpg', 'mime_type' => 'image/jpeg', 'size_kb' => 2048, 'caption' => null, 'created_at' => '2026-01-02 10:00:00', 'updated_at' => '2026-01-02 10:00:00'],
            ['file_name' => 'elm.png', 'mime_type' => 'image/png', 'size_kb' => 1.5, 'caption' => 'Elm', 'created_at' => '2026-01-03 10:00:00', 'updated_at' => '2026-01-03 10:00:00'],
        ]);

        DB::table('legacy_files')->insert([
            ['file_name' => 'contract.pdf', 'mime_type' => 'application/pdf', 'created_at' => '2026-01-06 10:00:00', 'updated_at' => '2026-01-06 10:00:00'],
            ['file_name' => 'notes.txt', 'mime_type' => 'text/plain', 'created_at' => '2026-01-07 10:00:00', 'updated_at' => '2026-01-07 10:00:00'],
        ]);

        DB::table('photo_comments')->insert([['photo_id' => 1, 'body' => 'Bright'], ['photo_id' => 3, 'body' => 'Sharp']]);
        DB::table('photo_shares')->insert([['photo_uuid' => DB::table('photos')->where('id', 1)->value('uuid')], ['photo_uuid' => null]]);
    }

    public static function seedMedia(): void
    {
        DB::table('videos')->insert([
            ['original_file_name' => 'tour.mp4', 'mime_type' => 'video/mp4', 'size_kb' => 2048, 'youtube_embed_url' => null, 'title' => 'Tour', 'views' => 5, 'created_at' => '2026-02-01 10:00:00', 'updated_at' => '2026-02-01 10:00:00'],
            ['original_file_name' => null, 'mime_type' => null, 'size_kb' => null, 'youtube_embed_url' => 'https://youtu.be/oak', 'title' => 'Oak walkthrough', 'views' => 2, 'created_at' => '2026-02-02 10:00:00', 'updated_at' => '2026-02-02 10:00:00'],
            ['original_file_name' => 'drone.mov', 'mime_type' => 'video/quicktime', 'size_kb' => 512, 'youtube_embed_url' => null, 'title' => null, 'views' => 0, 'created_at' => '2026-02-03 10:00:00', 'updated_at' => '2026-02-03 10:00:00'],
        ]);

        DB::table('interactive_content')->insert([
            ['url' => 'https://tour.example/oak', 'created_at' => '2026-02-04 10:00:00', 'updated_at' => '2026-02-04 10:00:00'],
            ['url' => 'https://tour.example/elm', 'created_at' => '2026-02-05 10:00:00', 'updated_at' => '2026-02-05 10:00:00'],
        ]);
    }

    public static function backfillMedia(): void
    {
        DB::statement('update videos set file_id = gen_random_uuid() where file_id is null');
        DB::statement('insert into files (id, filename, mime_type, size_bytes, embed_url, created_at, updated_at) select file_id, original_file_name, mime_type, round(size_kb * 1000), youtube_embed_url, created_at, updated_at from videos');
        self::catalog('videos');
        DB::statement('update interactive_content set link_id = gen_random_uuid() where link_id is null');
        DB::statement('insert into links (id, url, created_at, updated_at) select link_id, url, created_at, updated_at from interactive_content');
    }

    public static function installMedia(): void
    {
        Compatibility::prepare(Video::class);
        Compatibility::prepare(InteractiveContent::class);
        self::backfillMedia();
        Compatibility::install(
            model: InteractiveContent::class,
            statementTimeout: CarbonInterval::seconds(self::TIMEOUT),
        );
        Compatibility::install(
            model: Video::class,
            statementTimeout: CarbonInterval::seconds(self::TIMEOUT),
        );
    }

    public static function backfill(): void
    {
        foreach (['photos' => 'round(size_kb * 1000)', 'legacy_files' => 'null'] as $table => $size) {
            DB::statement("update {$table} set file_id = gen_random_uuid() where file_id is null");
            DB::statement("insert into files (id, filename, mime_type, size_bytes, created_at, updated_at) select file_id, file_name, mime_type, {$size}, created_at, updated_at from {$table}");
        }

        self::catalog('photos');
    }

    public static function catalog(string $table): void
    {
        File::query()->whereIn(
            column: 'id',
            values: DB::table($table)->select('file_id'),
        )->whereNull('zml_asset_id')->orderBy(DB::table($table)->select('id')->whereColumn(
            first: "{$table}.file_id",
            operator: '=',
            second: 'files.id',
        ))->each(function (File $file): void {
            $file->metadata()->mimeType = $file->mime_type;
            $file->timestamps = false;
            $file->save();
        });
    }

    public static function install(): void
    {
        foreach (self::MODELS as $model) {
            Compatibility::prepare($model);
        }

        self::backfill();

        foreach (self::MODELS as $model) {
            Compatibility::install(
                model: $model,
                statementTimeout: CarbonInterval::seconds(self::TIMEOUT),
            );
        }
    }

    public static function fileOf(string $table, int $id): mixed
    {
        return DB::table(Schema::hasTable("{$table}_retained") ? "{$table}_retained" : $table)
            ->where(column: 'id', operator: '=', value: $id)
            ->value('file_id');
    }

    public static function drop(): void
    {
        DB::statement('drop table if exists photo_comments, photo_shares cascade');

        foreach (['photos', 'legacy_files', 'videos', 'interactive_content'] as $view) {
            if (Schema::hasView($view)) {
                DB::statement("drop view {$view}");
            }
        }

        DB::statement('drop table if exists photos, photos_retained, photos_backfill, legacy_files, legacy_files_retained, legacy_files_backfill, videos, videos_retained, videos_backfill, interactive_content, interactive_content_retained, interactive_content_backfill, files, links cascade');
        DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type IN ('photos', 'legacy_files', 'videos', 'interactive_content'); IF NOT EXISTS (SELECT FROM compatibility_backfills) THEN DROP TABLE compatibility_backfills; END IF; END IF; END \$\$");
        LegacyBuilder::forgetViews();
    }
}
