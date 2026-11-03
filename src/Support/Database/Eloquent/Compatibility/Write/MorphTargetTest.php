<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Successors;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\AssetNote;
use Tests\Fixtures\Support\PlaceAssets\Brochure;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\InteractiveContent;
use Tests\Fixtures\Support\PlaceAssets\NoteAuthor;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\Fixtures\Support\PlaceAssets\TrashableAssetFile;
use Tests\Fixtures\Support\PlaceAssets\Video;
use Tests\TestCase;

#[CoversClass(RecordWriter::class)]
#[CoversClass(MorphMove::class)]
#[CoversClass(ExternalAttributes::class)]
#[CoversClass(Backfiller::class)]
#[CoversClass(MassWriter::class)]
final class MorphTargetTest extends TestCase
{
    private static int $reads = 0;

    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        PlaceAssetTables::install();
    }

    protected function tearDown(): void
    {
        AssetFile::flushEventListeners();
        AssetLink::flushEventListeners();
        PlaceAsset::flushEventListeners();
        PlaceAssetTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_updates_the_place_asset_of_an_image_whose_file_is_gone_and_creates_no_file(): void
    {
        $asset = $this->asset('place_image', 1);
        DB::table('asset_files')->where('id', $asset['asset_id'])->delete();

        $updated = Image::query()->findOrFail(1)->update(['title' => 'New']);

        $this->assertTrue($updated);
        $this->assertSame(['New', null], [Image::query()->findOrFail(1)->title, Image::query()->findOrFail(1)->file_name]);
        $this->assertSame([$asset['asset_type'], $asset['asset_id'], 3], [$this->asset('place_image', 1)['asset_type'], $this->asset('place_image', 1)['asset_id'], DB::table('asset_files')->count()]);
    }

    #[Test]
    public function it_increments_a_retained_column_of_a_video_whose_link_is_gone_and_creates_no_link(): void
    {
        $asset = $this->asset('place_video', 2);
        DB::table('asset_links')->where('id', $asset['asset_id'])->delete();

        $incremented = Video::query()->findOrFail(2)->increment('views');

        $this->assertSame(1, $incremented);
        $this->assertSame([3, null, 'Oak walkthrough'], [Video::query()->findOrFail(2)->views, Video::query()->findOrFail(2)->youtube_embed_url, Video::query()->findOrFail(2)->title]);
        $this->assertSame([$asset, 1], [$this->asset('place_video', 2), DB::table('asset_links')->count()]);
    }

    #[Test]
    public function it_updates_a_video_whose_link_is_gone_through_its_place_asset_and_moves_nothing(): void
    {
        $asset = $this->asset('place_video', 2);
        DB::table('asset_links')->where('id', $asset['asset_id'])->delete();

        $updated = Video::query()->findOrFail(2)->update(['title' => 'Oak v2', 'views' => 4]);

        $this->assertTrue($updated);
        $this->assertSame(['Oak v2', 4, null, null], [Video::query()->findOrFail(2)->title, Video::query()->findOrFail(2)->views, Video::query()->findOrFail(2)->youtube_embed_url, Video::query()->findOrFail(2)->original_file_name]);
        $this->assertSame([$asset['asset_type'], $asset['asset_id'], 4, 1], [$this->asset('place_video', 2)['asset_type'], $this->asset('place_video', 2)['asset_id'], DB::table('asset_files')->count(), DB::table('asset_links')->count()]);
    }

    #[Test]
    public function it_updates_a_video_whose_place_asset_names_an_undeclared_alias_through_that_place_asset_alone(): void
    {
        DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 3)->update(['asset_type' => 'retired_asset']);
        $files = DB::table('asset_files')->count();

        $updated = Video::query()->findOrFail(3)->update(['title' => 'Drone']);
        $incremented = Video::query()->findOrFail(3)->increment('views', 2);

        $this->assertSame([true, 1], [$updated, $incremented]);
        $this->assertSame(['Drone', 2, null], [Video::query()->findOrFail(3)->title, Video::query()->findOrFail(3)->views, Video::query()->findOrFail(3)->original_file_name]);
        $this->assertSame(['retired_asset', $files], [$this->asset('place_video', 3)['asset_type'], DB::table('asset_files')->count()]);
    }

    #[Test]
    public function it_refuses_to_move_a_video_from_an_undeclared_alias_without_the_values_its_new_target_requires_and_changes_nothing(): void
    {
        DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 3)->update(['asset_type' => 'retired_asset']);
        $before = $this->rows();

        try {
            Video::query()->findOrFail(3)->update(['size_kb' => '1.000']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString("cannot run a write that moves record 3, whose PlaceAsset stores asset_type 'retired_asset', into AssetFile without \$original_file_name", $refusal);
        $this->assertStringContainsString('asset_files.filename needs a value', $refusal);
        $this->assertSame($before, $this->rows());
    }

    #[Test]
    public function it_reads_and_writes_a_video_whose_place_asset_stores_no_morph_type(): void
    {
        DB::statement('alter table place_assets alter column asset_type drop not null');
        DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 3)->update(['asset_type' => null]);
        $files = DB::table('asset_files')->count();

        $read = Video::query()->findOrFail(3);
        $updated = $read->update(['title' => 'Drone']);
        $incremented = Video::query()->findOrFail(3)->increment('views');

        $this->assertSame([true, 1], [$updated, $incremented]);
        $this->assertSame([3, 'Drone', null, null, null, 1], [$read->id, Video::query()->findOrFail(3)->title, Video::query()->findOrFail(3)->original_file_name, Video::query()->findOrFail(3)->size_kb, Video::query()->findOrFail(3)->youtube_embed_url, Video::query()->findOrFail(3)->views]);
        $this->assertSame([null, $files], [$this->asset('place_video', 3)['asset_type'], DB::table('asset_files')->count()]);
    }

    #[Test]
    public function it_serves_and_writes_the_records_of_an_empty_target_table(): void
    {
        DB::table('asset_links')->delete();

        $videos = Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->youtube_embed_url])->all();
        $tours = InteractiveContent::query()->get()->map(fn (InteractiveContent $tour) => [$tour->title, $tour->url])->all();
        $titled = Video::query()->update(['title' => 'Every video']);
        $updated = InteractiveContent::query()->findOrFail(1)->update(['title' => 'Oak tour v2']);

        $this->assertSame([[[1, null], [2, null], [3, null]], [['Oak tour', null]]], [$videos, $tours]);
        $this->assertSame([3, true], [$titled, $updated]);
        $this->assertSame([['Every video', 'Every video', 'Every video'], 'Oak tour v2', 0], [Video::query()->orderBy('id')->pluck('title')->all(), InteractiveContent::query()->findOrFail(1)->title, DB::table('asset_links')->count()]);
    }

    #[Test]
    public function it_reads_an_external_attribute_of_the_place_asset_for_a_record_whose_alias_no_target_declares(): void
    {
        $this->installBrochures();
        DB::table('place_assets')->where('legacy_type', 'place_brochure')->where('legacy_id', 1)->update(['asset_type' => 'retired_asset']);

        $titles = Brochure::query()->orderBy('id')->get()->map(fn (Brochure $brochure) => [$brochure->id, $brochure->title, $brochure->file_name])->all();
        $brochure = Brochure::query()->findOrFail(1);
        $updated = $brochure->update(['title' => 'Guide v2']);

        $this->assertSame([[1, 'Guide', null], [2, 'Map', null]], $titles);
        $this->assertSame([true, 'Guide v2', 'Guide v2', 'Guide v2'], [$updated, $brochure->title, Brochure::query()->findOrFail(1)->title, DB::table('place_assets')->where('legacy_type', 'place_brochure')->where('legacy_id', 1)->value('title')]);
    }

    #[Test]
    public function it_serves_a_record_whose_target_is_trashed_and_writes_it_without_restoring_or_deleting_that_target(): void
    {
        $this->installBrochures();
        $trashed = (string) $this->asset('place_brochure', 1)['asset_id'];
        TrashableAssetFile::query()->findOrFail($trashed)->delete();

        $read = Brochure::query()->findOrFail(1);
        $updated = $read->update(['pages' => 13]);
        $pointer = $this->asset('place_brochure', 1);
        $renamed = Brochure::query()->findOrFail(1)->update(['file_name' => 'guide-v2.pdf']);
        $renaming = $this->asset('place_brochure', 1);

        $this->assertSame([1, 'Guide', null, true], [$read->id, $read->title, $read->file_name, $read->hosted]);
        $this->assertSame([true, $trashed, 13], [$updated, $pointer['asset_id'], Brochure::query()->findOrFail(1)->pages]);
        $this->assertTrue($renamed);
        $this->assertNotSame($trashed, $renaming['asset_id']);
        $this->assertSame(['guide-v2.pdf', 'guide-v2.pdf', true], [Brochure::query()->findOrFail(1)->file_name, DB::table('trashable_asset_files')->where('id', $renaming['asset_id'])->value('filename'), DB::table('trashable_asset_files')->where('id', $trashed)->whereNotNull('deleted_at')->exists()]);
    }

    #[Test]
    public function it_moves_a_record_whose_target_is_trashed_and_leaves_that_target_trashed(): void
    {
        $this->installBrochures();
        $trashed = (string) $this->asset('place_brochure', 1)['asset_id'];
        TrashableAssetFile::query()->findOrFail($trashed)->delete();

        $updated = Brochure::query()->findOrFail(1)->update(['hosted' => false, 'url' => 'https://guide.example/oak']);

        $moved = $this->asset('place_brochure', 1);
        $this->assertTrue($updated);
        $this->assertSame(['place_asset_link', 'https://guide.example/oak'], [$moved['asset_type'], DB::table('asset_links')->where('id', $moved['asset_id'])->value('url')]);
        $this->assertSame([null, 'https://guide.example/oak', false, 'Guide'], [Brochure::query()->findOrFail(1)->file_name, Brochure::query()->findOrFail(1)->url, Brochure::query()->findOrFail(1)->hosted, Brochure::query()->findOrFail(1)->title]);
        $this->assertTrue(DB::table('trashable_asset_files')->where('id', $trashed)->whereNotNull('deleted_at')->exists());
    }

    #[Test]
    public function it_backfills_a_record_whose_target_is_trashed_into_a_live_target_and_leaves_the_trashed_one(): void
    {
        PlaceAssetTables::brochures();
        Compatibility::prepare(Brochure::class);
        $backfiller = new Backfiller(Compatibility::of(Brochure::class)->resolve(), DB::connection());
        $backfiller->run();
        $trashed = (string) $this->asset('place_brochure', 1)['asset_id'];
        TrashableAssetFile::query()->findOrFail($trashed)->delete();

        $run = $backfiller->run();
        Compatibility::install(Brochure::class);

        $live = (string) $this->asset('place_brochure', 1)['asset_id'];
        $this->assertSame([2, 2], [$run['read'], $run['changed']]);
        $this->assertNotSame($trashed, $live);
        $this->assertSame(['guide.pdf', true], [DB::table('trashable_asset_files')->where('id', $live)->whereNull('deleted_at')->value('filename'), DB::table('trashable_asset_files')->where('id', $trashed)->whereNotNull('deleted_at')->exists()]);
        $this->assertSame([[1, 'Guide', 'guide.pdf', null], [2, 'Map', null, 'https://maps.example/oak']], Brochure::query()->orderBy('id')->get()->map(fn (Brochure $brochure) => [$brochure->id, $brochure->title, $brochure->file_name, $brochure->url])->all());
    }

    #[Test]
    public function it_moves_the_place_asset_of_a_legacy_row_whose_key_changed_onto_the_new_key_by_its_other_identifier_and_keeps_its_target(): void
    {
        PlaceAssetTables::brochures();
        Compatibility::prepare(Brochure::class);
        $backfiller = new Backfiller(Compatibility::of(Brochure::class)->resolve(), DB::connection());
        $watermark = $backfiller->run()['watermark'];
        $before = $this->asset('place_brochure', 1);
        DB::update('update place_brochures set id = 99, updated_at = localtimestamp(0) where id = 1');

        $backfiller->run(since: $watermark['updatedAt']);
        Compatibility::install(Brochure::class);

        $this->assertSame([2, 99], DB::table('place_assets')->where('legacy_type', 'place_brochure')->orderBy('legacy_id')->pluck('legacy_id')->map(fn (mixed $id) => (int) $id)->all());
        $this->assertSame($before, $this->asset('place_brochure', 99));
        $this->assertSame([[2, 'Map', null, 'https://maps.example/oak'], [99, 'Guide', 'guide.pdf', null]], Brochure::query()->orderBy('id')->get()->map(fn (Brochure $brochure) => [$brochure->id, $brochure->title, $brochure->file_name, $brochure->url])->all());
    }

    #[Test]
    public function it_chooses_the_target_of_a_new_record_from_the_default_of_a_retained_column_it_omits(): void
    {
        $this->installBrochures();

        $flyer = Brochure::query()->create(['title' => 'Flyer', 'file_name' => 'flyer.pdf']);

        $this->assertSame(['Flyer', 'flyer.pdf', null, true, 0], [$flyer->refresh()->title, $flyer->file_name, $flyer->url, $flyer->hosted, $flyer->pages]);
        $this->assertSame(TrashableAssetFile::class, $this->asset('place_brochure', $flyer->id)['asset_type']);
    }

    #[Test]
    public function it_chooses_the_target_of_a_new_record_from_a_retained_expression_it_evaluates_first(): void
    {
        $this->installBrochures();

        $site = Brochure::query()->create(['title' => 'Site', 'url' => 'https://site.example/oak', 'hosted' => DB::raw('1 = 0')]);

        $this->assertSame(['Site', null, 'https://site.example/oak', false], [$site->refresh()->title, $site->file_name, $site->url, $site->hosted]);
        $this->assertSame('place_asset_link', $this->asset('place_brochure', $site->id)['asset_type']);
    }

    #[Test]
    public function it_chooses_the_target_of_a_mass_write_from_the_value_the_database_stores_for_a_retained_literal(): void
    {
        $this->installBrochures();
        $before = $this->asset('place_brochure', 1);

        try {
            Brochure::query()->whereKey(1)->update(['hosted' => 'false']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $kept = Brochure::query()->whereKey(2)->update(['hosted' => 'off', 'pages' => 3]);

        $this->assertStringContainsString('It would move record 1 from TrashableAssetFile to AssetLink', $refusal);
        $this->assertSame([true, $before], [Brochure::query()->findOrFail(1)->hosted, $this->asset('place_brochure', 1)]);
        $this->assertSame([1, false, 3, 'place_asset_link'], [$kept, Brochure::query()->findOrFail(2)->hosted, Brochure::query()->findOrFail(2)->pages, $this->asset('place_brochure', 2)['asset_type']]);
    }

    #[Test]
    public function it_writes_only_the_records_that_still_match_the_mass_write_once_it_holds_their_place_assets(): void
    {
        DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 3)->update(['title' => 'Tour']);
        config(['database.connections.second' => config('database.connections.'.DB::getDefaultConnection())]);
        $second = DB::connection('second');
        $renamed = null;
        DB::listen(function (QueryExecuted $query) use (&$renamed, $second): void {
            if ($renamed !== null || $query->connectionName !== DB::getDefaultConnection() || ! str_starts_with($query->sql, 'CREATE TEMPORARY TABLE') || ! str_contains($query->sql, 'compatibility_selected')) {
                return;
            }

            try {
                $second->transaction(function () use ($second): void {
                    $second->statement("SET LOCAL lock_timeout = '1s'");
                    $second->update("update place_assets set title = 'Changed' where legacy_type = 'place_video' and legacy_id = 1");
                });
                $renamed = true;
            } catch (QueryException) {
                $renamed = false;
            }
        });

        try {
            $updated = Video::query()->where('title', 'Tour')->update(['title' => 'Mass']);
        } finally {
            DB::purge('second');
        }

        $this->assertSame([true, 1], [$renamed, $updated]);
        $this->assertSame(['Changed', 'Oak walkthrough', 'Mass'], Video::query()->orderBy('id')->pluck('title')->all());
    }

    #[Test]
    public function it_holds_the_target_of_each_selected_record_before_it_checks_the_mass_write_against_its_query_again(): void
    {
        $file = (string) $this->asset('place_video', 1)['asset_id'];
        config(['database.connections.second' => config('database.connections.'.DB::getDefaultConnection())]);
        $second = DB::connection('second');
        $blocked = null;
        DB::listen(function (QueryExecuted $query) use (&$blocked, $second, $file): void {
            if ($blocked !== null || $query->connectionName !== DB::getDefaultConnection() || ! str_starts_with($query->sql, 'delete from "pg_temp"."compatibility_selected"')) {
                return;
            }

            try {
                $second->transaction(function () use ($second, $file): void {
                    $second->statement("SET LOCAL lock_timeout = '1s'");
                    $second->update("update asset_files set filename = 'raced.mp4' where id = ?", [$file]);
                });
                $blocked = false;
            } catch (QueryException) {
                $blocked = true;
            }
        });

        try {
            $updated = Video::query()->where('original_file_name', 'tour.mp4')->update(['title' => 'Held']);
        } finally {
            DB::purge('second');
        }

        $this->assertSame([true, 1], [$blocked, $updated]);
        $this->assertSame(['tour.mp4', 'Held'], [Video::query()->findOrFail(1)->original_file_name, Video::query()->findOrFail(1)->title]);
    }

    #[Test]
    public function it_evaluates_a_retained_expression_of_a_mass_write_once_and_chooses_from_the_value_it_stores(): void
    {
        $this->installBrochures();
        DB::statement('create sequence brochure_parity');

        try {
            $updated = Brochure::query()->whereKey(2)->update(['hosted' => DB::raw("nextval('brochure_parity') % 2 = 0")]);
            $calls = DB::scalar('select last_value from brochure_parity');
        } finally {
            DB::statement('drop sequence brochure_parity');
        }

        $this->assertSame([1, 1, false, 'place_asset_link'], [$updated, $calls, Brochure::query()->findOrFail(2)->hosted, $this->asset('place_brochure', 2)['asset_type']]);
    }

    #[Test]
    public function it_chooses_the_target_of_a_mass_increment_from_the_value_it_assigns_over_the_amount(): void
    {
        $this->installBrochures();
        $brochure = $this->pagedBrochure();

        $kept = $brochure->newQuery()->whereKey(2)->increment('pages', 20, ['pages' => 2]);

        try {
            $brochure->newQuery()->whereKey(1)->increment('pages', 1, ['pages' => 2]);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame([1, 2, 'place_asset_link'], [$kept, Brochure::query()->findOrFail(2)->pages, $this->asset('place_brochure', 2)['asset_type']]);
        $this->assertStringContainsString('It would move record 1 from TrashableAssetFile to AssetLink', $refusal);
        $this->assertSame(12, Brochure::query()->findOrFail(1)->pages);
    }

    #[Test]
    public function it_chooses_the_target_of_a_mass_increment_of_a_retained_column_from_the_value_the_column_stores(): void
    {
        $this->installBrochures();
        $brochure = $this->pagedBrochure();
        $brochure->newQuery()->whereKey(2)->update(['pages' => 9]);

        try {
            $brochure->newQuery()->whereKey(2)->increment('pages', 0.6);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString('It would move record 2 from AssetLink to TrashableAssetFile', $refusal);
        $this->assertSame([9, 'place_asset_link'], [Brochure::query()->findOrFail(2)->pages, $this->asset('place_brochure', 2)['asset_type']]);
    }

    #[Test]
    public function it_chooses_the_target_of_a_mass_increment_of_a_mapped_attribute_from_the_value_its_column_stores(): void
    {
        $video = $this->sizedVideo();

        $kept = $video->newQuery()->whereKey(3)->increment('size_kb', 0.0004);

        try {
            $video->newQuery()->whereKey(3)->increment('size_kb', 0.0006);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame([1, '512.000', 'place_asset_file'], [$kept, Video::query()->findOrFail(3)->size_kb, $this->asset('place_video', 3)['asset_type']]);
        $this->assertStringContainsString('It would move record 3 from AssetFile to AssetLink', $refusal);
        $this->assertSame('512.000', Video::query()->findOrFail(3)->size_kb);
    }

    #[Test]
    public function it_evaluates_a_retained_expression_of_a_mass_write_against_the_legacy_columns_alone(): void
    {
        $updated = Video::query()->whereIn('id', [1, 2])->update(['views' => DB::raw('length(title)')]);

        $this->assertSame([2, [4, 15, 0]], [$updated, Video::query()->orderBy('id')->pluck('views')->all()]);
    }

    #[Test]
    public function it_checks_the_target_of_a_mass_increment_that_also_renumbers_the_record_against_its_new_number(): void
    {
        $video = $this->sizedVideo();

        try {
            $video->newQuery()->whereKey(3)->increment('size_kb', 1, ['id' => 30]);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString('It would move record 3 from AssetFile to AssetLink', $refusal);
        $this->assertSame(['512.000', 'place_asset_file', null], [Video::query()->findOrFail(3)->size_kb, $this->asset('place_video', 3)['asset_type'], Video::query()->find(30)]);

        $renumbered = $video->newQuery()->whereKey(3)->increment('size_kb', 100, ['id' => 30]);

        $this->assertSame([1, '612.000', 'place_asset_file', null], [$renumbered, Video::query()->findOrFail(30)->size_kb, $this->asset('place_video', 30)['asset_type'], Video::query()->find(3)]);
    }

    #[Test]
    public function it_refuses_a_mass_json_path_write_that_changes_the_target_its_choose_closure_reads_and_changes_nothing(): void
    {
        $brochure = $this->installed($this->describedBrochure(), function (): void {
            Schema::table('place_brochures', fn (Blueprint $table) => $table->jsonb('metadata')->nullable());
            DB::table('place_brochures')->where('id', 1)->update(['metadata' => '{"hosted": true}']);
            DB::table('place_brochures')->where('id', 2)->update(['metadata' => '{"hosted": false}']);
        });

        try {
            $brochure->newQuery()->whereKey(2)->update(['metadata->hosted' => true]);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString('It would move record 2 from AssetLink to TrashableAssetFile', $refusal);
        $this->assertSame([['hosted' => false], 'place_asset_link'], [$brochure->newQuery()->findOrFail(2)->getAttribute('metadata'), $this->asset('place_brochure', 2)['asset_type']]);

        $kept = $brochure->newQuery()->whereKey(2)->update(['metadata->tier' => 'gold']);

        $this->assertSame([1, ['tier' => 'gold', 'hosted' => false], 'place_asset_link'], [$kept, $brochure->newQuery()->findOrFail(2)->getAttribute('metadata'), $this->asset('place_brochure', 2)['asset_type']]);
    }

    #[Test]
    public function it_reads_an_external_attribute_of_a_successor_every_target_shares_once_for_all_the_records_it_loads(): void
    {
        Schema::create('asset_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('place_asset_id');
            $table->string('body')->nullable();
        });

        try {
            $brochure = $this->installed($this->notedBrochure(), fn () => Schema::table('place_brochures', fn (Blueprint $table) => $table->string('note')->nullable()));
            DB::table('asset_notes')->updateOrInsert(['place_asset_id' => $this->asset('place_brochure', 1)['id']], ['body' => 'Lobby']);
            $queries = 0;
            $before = self::reads();
            DB::listen(function (QueryExecuted $query) use (&$queries): void {
                $queries += str_contains($query->sql, '"asset_notes"') ? 1 : 0;
            });

            $read = [$brochure->newQuery()->orderBy('id')->get()->map(fn (Model $record) => $record->getAttribute('note'))->all(), $queries, self::reads() - $before];
        } finally {
            PlaceAssetTables::drop();
            Schema::dropIfExists('asset_notes');
        }

        $this->assertSame([['Lobby', null], 1, 1], $read);
    }

    #[Test]
    public function it_refuses_a_mass_write_whose_read_as_projection_changes_the_target_its_choose_closure_reads_and_changes_nothing(): void
    {
        $brochure = $this->installed($this->titledBrochure(), fn () => null);

        try {
            $brochure->newQuery()->whereKey(1)->update(['title' => 'embed']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString('It would move record 1 from TrashableAssetFile to AssetLink', $refusal);
        $this->assertSame(['Guide', TrashableAssetFile::class], [$this->asset('place_brochure', 1)['title'], $this->asset('place_brochure', 1)['asset_type']]);

        $kept = $brochure->newQuery()->whereKey(1)->update(['title' => 'manual']);

        $this->assertSame([1, 'MANUAL', 'manual', TrashableAssetFile::class], [$kept, $brochure->newQuery()->findOrFail(1)->getAttribute('title'), $this->asset('place_brochure', 1)['title'], $this->asset('place_brochure', 1)['asset_type']]);
    }

    #[Test]
    public function it_applies_a_mass_increment_once_to_a_successor_row_that_records_of_two_targets_share(): void
    {
        Schema::create('asset_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('place_asset_id')->nullable();
            $table->string('body')->nullable();
            $table->integer('pins')->default(0);
            $table->timestampsTz();
        });

        try {
            $brochure = $this->installed($this->pinnedBrochure(), function (): void {
                Schema::table('place_brochures', fn (Blueprint $table) => $table->integer('pins')->nullable());
                Schema::table('place_assets', fn (Blueprint $table) => $table->uuid('note_id')->nullable());
            });
            $note = (string) DB::table('asset_notes')->insertGetId(['pins' => 5]);
            DB::table('place_assets')->where('legacy_type', 'place_brochure')->update(['note_id' => $note]);

            $updated = $brochure->newQuery()->whereKey([1, 2])->increment('pins', 1);
            $written = [$updated, DB::table('asset_notes')->where('id', $note)->value('pins'), $brochure->newQuery()->orderBy('id')->pluck('pins')->all(), $this->asset('place_brochure', 1)['asset_type'], $this->asset('place_brochure', 2)['asset_type']];
        } finally {
            PlaceAssetTables::drop();
            Schema::dropIfExists('asset_notes');
        }

        $this->assertSame([2, 6, [6, 6], TrashableAssetFile::class, 'place_asset_link'], $written);
    }

    #[Test]
    public function it_backfills_the_external_values_of_two_nested_successors_it_creates_onto_the_key_the_parent_receives(): void
    {
        Schema::create('asset_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('place_asset_id');
            $table->string('body')->nullable();
            $table->timestampsTz();
        });
        Schema::create('note_authors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_note_id')->constrained('asset_notes')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->timestampsTz();
        });

        try {
            $brochure = $this->installed($this->authoredBrochure(), function (): void {
                Schema::table('place_brochures', function (Blueprint $table): void {
                    $table->string('note')->nullable();
                    $table->string('author')->nullable();
                });
                DB::table('place_brochures')->where('id', 1)->update(['note' => 'Lobby', 'author' => 'Ana']);
            });
            $note = DB::table('asset_notes')->where('place_asset_id', $this->asset('place_brochure', 1)['id'])->first(['id', 'body']);
            $written = [
                $note?->body,
                DB::table('note_authors')->where('asset_note_id', $note?->id)->value('name'),
                DB::table('asset_notes')->count(),
                DB::table('note_authors')->count(),
                $brochure->newQuery()->orderBy('id')->get()->map(fn (Model $record) => [$record->getAttribute('note'), $record->getAttribute('author')])->all(),
            ];
        } finally {
            PlaceAssetTables::drop();
            Schema::dropIfExists('note_authors');
            Schema::dropIfExists('asset_notes');
        }

        $this->assertSame(['Lobby', 'Ana', 1, 1, [['Lobby', 'Ana'], [null, null]]], $written);
    }

    private function pagedBrochure(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'place_brochures';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'place_brochure';
            }

            public static function compatibility(Compatibility $brochure): Compatibility
            {
                return $brochure
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('place_brochures_retained')
                    ->timestamps()
                    ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset
                        ->attribute('title', fn (Attribute $attribute) => $attribute->external(
                            read: fn (Collection $assets): array => $assets->mapWithKeys(fn (Model $asset) => [$asset->getKey() => $asset->getAttribute('title')])->all(),
                            write: fn (PlaceAsset $asset, null|string $title) => $asset->title = $title))
                        ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $brochure): string => (int) $brochure->getAttribute('pages') >= 10 ? TrashableAssetFile::class : AssetLink::class,
                            successors: fn (Successors $successors) => $successors
                                ->as(TrashableAssetFile::class, fn (Successor $file) => $file
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->fixed(null)))
                                ->as(AssetLink::class, fn (Successor $link) => $link
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->from('url')))));
            }
        };
    }

    private function sizedVideo(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'place_videos';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'place_video';
            }

            public static function compatibility(Compatibility $video): Compatibility
            {
                return $video
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('place_videos_retained')
                    ->timestamps()
                    ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('title'))
                        ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null || (float) $video->getAttribute('size_kb') > 512 && (float) $video->getAttribute('size_kb') < 600 ? AssetLink::class : AssetFile::class,
                            successors: fn (Successors $successors) => $successors
                                ->as(AssetFile::class, fn (Successor $file) => $file
                                    ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                                    ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000))
                                    ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->fixed(null)))
                                ->as(AssetLink::class, fn (Successor $link) => $link
                                    ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                                    ->attribute('size_kb', fn (Attribute $attribute) => $attribute->fixed(null))
                                    ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url')))));
            }
        };
    }

    /**
     * @param  Collection<array-key, Model>  $notes
     * @return array<array-key, mixed>
     */
    public static function read(Collection $notes): array
    {
        self::$reads++;

        return $notes->mapWithKeys(fn (Model $note) => [$note->getKey() => $note->getAttribute('body')])->all();
    }

    private static function reads(): int
    {
        return self::$reads;
    }

    /**
     * @param  Closure(): void  $shape
     */
    private function installed(Model $brochure, Closure $shape): Model
    {
        PlaceAssetTables::brochures();
        $shape();
        Compatibility::prepare($brochure::class);
        (new Backfiller(Compatibility::of($brochure::class)->resolve(), DB::connection()))->run();
        Compatibility::install($brochure::class);

        return $brochure;
    }

    private function describedBrochure(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'place_brochures';

            protected $guarded = [];

            protected $casts = ['metadata' => 'array'];

            public function getMorphClass()
            {
                return 'place_brochure';
            }

            public static function compatibility(Compatibility $brochure): Compatibility
            {
                return $brochure
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('place_brochures_retained')
                    ->timestamps()
                    ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('title'))
                        ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $brochure): string => data_get($brochure->getAttribute('metadata'), 'hosted') === true ? TrashableAssetFile::class : AssetLink::class,
                            successors: fn (Successors $successors) => $successors
                                ->as(TrashableAssetFile::class, fn (Successor $file) => $file
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->fixed(null)))
                                ->as(AssetLink::class, fn (Successor $link) => $link
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->from('url')))));
            }
        };
    }

    private function notedBrochure(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'place_brochures';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'place_brochure';
            }

            public static function compatibility(Compatibility $brochure): Compatibility
            {
                return $brochure
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('place_brochures_retained')
                    ->timestamps()
                    ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('title'))
                        ->related(fn (PlaceAsset $asset) => $asset->hasOne(AssetNote::class, 'place_asset_id'), fn (Successor $note) => $note
                            ->attribute('note', fn (Attribute $attribute) => $attribute->external(
                                read: fn (Collection $notes): array => MorphTargetTest::read($notes),
                                write: fn (AssetNote $note, null|string $body) => $note->body = $body)))
                        ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $brochure): string => (bool) $brochure->getAttribute('hosted') ? TrashableAssetFile::class : AssetLink::class,
                            successors: fn (Successors $successors) => $successors
                                ->as(TrashableAssetFile::class, fn (Successor $file) => $file
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->fixed(null)))
                                ->as(AssetLink::class, fn (Successor $link) => $link
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->from('url')))));
            }
        };
    }

    private function titledBrochure(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'place_brochures';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'place_brochure';
            }

            public static function compatibility(Compatibility $brochure): Compatibility
            {
                return $brochure
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('place_brochures_retained')
                    ->timestamps()
                    ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('title')->readAs('upper("place_assets"."title")')->writeTo(fn (mixed $title): mixed => $title))
                        ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $brochure): string => $brochure->getAttribute('title') === 'EMBED' || ! (bool) $brochure->getAttribute('hosted') ? AssetLink::class : TrashableAssetFile::class,
                            successors: fn (Successors $successors) => $successors
                                ->as(TrashableAssetFile::class, fn (Successor $file) => $file
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->fixed(null)))
                                ->as(AssetLink::class, fn (Successor $link) => $link
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->from('url')))));
            }
        };
    }

    private function pinnedBrochure(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'place_brochures';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'place_brochure';
            }

            public static function compatibility(Compatibility $brochure): Compatibility
            {
                return $brochure
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('place_brochures_retained')
                    ->timestamps()
                    ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('title'))
                        ->related(fn (PlaceAsset $asset) => $asset->belongsTo(AssetNote::class, 'note_id'), fn (Successor $note) => $note
                            ->attribute('pins', fn (Attribute $attribute) => $attribute->from('pins')))
                        ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $brochure): string => (bool) $brochure->getAttribute('hosted') ? TrashableAssetFile::class : AssetLink::class,
                            successors: fn (Successors $successors) => $successors
                                ->as(TrashableAssetFile::class, fn (Successor $file) => $file
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->fixed(null)))
                                ->as(AssetLink::class, fn (Successor $link) => $link
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->from('url')))));
            }
        };
    }

    private function authoredBrochure(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'place_brochures';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'place_brochure';
            }

            public static function compatibility(Compatibility $brochure): Compatibility
            {
                return $brochure
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('place_brochures_retained')
                    ->timestamps()
                    ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('title'))
                        ->related(fn (PlaceAsset $asset) => $asset->hasOne(AssetNote::class, 'place_asset_id'), fn (Successor $note) => $note
                            ->attribute('note', fn (Attribute $attribute) => $attribute->external(
                                read: fn (Collection $notes): array => $notes->mapWithKeys(fn (Model $note) => [$note->getKey() => $note->getAttribute('body')])->all(),
                                write: fn (AssetNote $note, null|string $body) => $note->body = $body))
                            ->related(fn (AssetNote $note) => $note->hasOne(NoteAuthor::class, 'asset_note_id'), fn (Successor $author) => $author
                                ->attribute('author', fn (Attribute $attribute) => $attribute->external(
                                    read: fn (Collection $authors): array => $authors->mapWithKeys(fn (Model $author) => [$author->getKey() => $author->getAttribute('name')])->all(),
                                    write: fn (NoteAuthor $author, null|string $name) => $author->name = $name))))
                        ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $brochure): string => (bool) $brochure->getAttribute('hosted') ? TrashableAssetFile::class : AssetLink::class,
                            successors: fn (Successors $successors) => $successors
                                ->as(TrashableAssetFile::class, fn (Successor $file) => $file
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->fixed(null)))
                                ->as(AssetLink::class, fn (Successor $link) => $link
                                    ->attribute('file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                                    ->attribute('url', fn (Attribute $attribute) => $attribute->from('url')))));
            }
        };
    }

    private function installBrochures(): void
    {
        PlaceAssetTables::brochures();
        Compatibility::prepare(Brochure::class);
        (new Backfiller(Compatibility::of(Brochure::class)->resolve(), DB::connection()))->run();
        Compatibility::install(Brochure::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(string $legacyType, int $id): array
    {
        return (array) DB::table('place_assets')->where('legacy_type', $legacyType)->where('legacy_id', $id)->first(['id', 'asset_type', 'asset_id', 'title']);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (['place_assets' => 'id', 'asset_files' => 'id', 'asset_links' => 'id', 'place_videos_retained' => 'place_asset_id'] as $table => $key) {
            $rows[$table] = array_map(fn (object $row) => (array) $row, DB::table($table)->orderBy($key)->get()->all());
        }

        return $rows;
    }
}
