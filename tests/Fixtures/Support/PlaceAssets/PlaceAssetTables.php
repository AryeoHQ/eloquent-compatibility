<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;

final class PlaceAssetTables
{
    public const array MODELS = [Image::class, Video::class, InteractiveContent::class];

    public static function create(): void
    {
        self::drop();

        Schema::create('place_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('asset_type');
            $table->uuid('asset_id');
            $table->string('title')->nullable();
            $table->timestampsTz();
            $table->index(['asset_type', 'asset_id']);
        });

        Schema::create('asset_files', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('zml_asset_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('asset_links', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('url');
            $table->timestampsTz();
        });

        Schema::create('place_images', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('title')->nullable();
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });

        Schema::create('place_videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('title')->nullable();
            $table->string('original_file_name')->nullable();
            $table->decimal('size_kb', 12, 3)->nullable();
            $table->string('youtube_embed_url')->nullable();
            $table->integer('views')->default(0);
            $table->timestamps();
        });

        Schema::create('place_interactive_content', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique()->default(new Expression('gen_random_uuid()'));
            $table->string('title')->nullable();
            $table->string('url');
            $table->timestamps();
        });
    }

    public static function seed(): void
    {
        DB::table('place_images')->insert([
            ['title' => 'Front', 'file_name' => 'front.jpg', 'mime_type' => 'image/jpeg', 'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'],
            ['title' => null, 'file_name' => 'back.png', 'mime_type' => 'image/png', 'created_at' => '2026-03-02 10:00:00', 'updated_at' => '2026-03-02 10:00:00'],
        ]);

        DB::table('place_videos')->insert([
            ['title' => 'Tour', 'original_file_name' => 'tour.mp4', 'size_kb' => 2048, 'youtube_embed_url' => null, 'views' => 5, 'created_at' => '2026-03-03 10:00:00', 'updated_at' => '2026-03-03 10:00:00'],
            ['title' => 'Oak walkthrough', 'original_file_name' => null, 'size_kb' => null, 'youtube_embed_url' => 'https://youtu.be/oak', 'views' => 2, 'created_at' => '2026-03-04 10:00:00', 'updated_at' => '2026-03-04 10:00:00'],
            ['title' => null, 'original_file_name' => 'drone.mov', 'size_kb' => 512, 'youtube_embed_url' => null, 'views' => 0, 'created_at' => '2026-03-05 10:00:00', 'updated_at' => '2026-03-05 10:00:00'],
        ]);

        DB::table('place_interactive_content')->insert([
            ['title' => 'Oak tour', 'url' => 'https://tour.example/oak', 'created_at' => '2026-03-06 10:00:00', 'updated_at' => '2026-03-06 10:00:00'],
        ]);
    }

    public static function prepare(): void
    {
        foreach (self::MODELS as $model) {
            Compatibility::prepare($model);
        }
    }

    public static function backfill(): void
    {
        foreach (self::MODELS as $model) {
            (new Backfiller(Compatibility::of($model)->resolve(), DB::connection()))->run();
        }
    }

    public static function install(): void
    {
        self::prepare();
        self::backfill();

        foreach (self::MODELS as $model) {
            Compatibility::install($model);
        }
    }

    public static function drop(): void
    {
        foreach (['place_images', 'place_videos', 'place_interactive_content'] as $view) {
            if (Schema::hasView($view)) {
                DB::statement("drop view {$view}");
            }
        }

        DB::statement('drop table if exists place_images, place_images_retained, place_images_backfilled, place_videos, place_videos_retained, place_videos_backfilled, place_interactive_content, place_interactive_content_retained, place_interactive_content_backfilled, place_assets, asset_files, asset_links cascade');
        DB::statement('drop sequence if exists compatibility_legacy_id_seq');
        LegacyBuilder::forgetViews();
    }
}
