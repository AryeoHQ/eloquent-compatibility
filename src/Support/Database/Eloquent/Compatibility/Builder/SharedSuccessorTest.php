<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Write\MassWriter;
use Support\Database\Eloquent\Compatibility\Write\RecordWriter;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Photo;
use Tests\TestCase;

#[CoversClass(LegacyBuilder::class)]
#[CoversClass(RecordWriter::class)]
#[CoversClass(MassWriter::class)]
final class SharedSuccessorTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        FileTables::create();
        FileTables::seed();

        foreach (FileTables::MODELS as $model) {
            Compatibility::prepare($model);
        }

        FileTables::backfill();
        $this->before = $this->legacyRows();

        foreach (FileTables::MODELS as $model) {
            Compatibility::install($model);
        }
    }

    protected function tearDown(): void
    {
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_serves_each_legacy_model_the_rows_and_values_its_legacy_table_held(): void
    {
        $this->assertSame(['v', 'v', 'v'], array_map(fn (string $view) => DB::scalar('select relkind::text from pg_class where oid = to_regclass(?)', [$view]), ['photos', 'floorplans', 'legacy_files']));
        $this->assertSame(['photos' => array_map(fn (array $row) => array_diff_key($row, ['mime_type' => true]), $this->before['photos'])] + $this->before, $this->legacyRows());
        $this->assertSame(array_column($this->before['photos'], 'mime_type'), Photo::query()->orderBy('id')->get()->map(fn (Photo $photo) => $photo->mime_type)->all());
        $this->assertSame(
            ['oak-front.jpg', 'oak-plan.pdf', 'contract.pdf'],
            [Photo::query()->findOrFail(1)->file_name, FloorPlan::query()->findOrFail(1)->file_name, LegacyFile::query()->findOrFail(1)->file_name],
        );
    }

    #[Test]
    public function it_serves_a_file_a_successor_writer_classifies_only_in_the_legacy_model_its_discriminator_names(): void
    {
        DB::table('files')->insert(['filename' => 'pine-plan.png', 'mime_type' => 'image/png', 'legacy_type' => 'floorplan']);
        $file = DB::selectOne("select legacy_id, legacy_uuid from files where filename = 'pine-plan.png'");
        $floorPlan = FloorPlan::query()->where('file_name', 'pine-plan.png')->firstOrFail();

        $this->assertSame([$file->legacy_id, $file->legacy_uuid], [$floorPlan->id, $floorPlan->uuid]);
        $this->assertGreaterThan(3, $floorPlan->id);
        $this->assertSame([[], []], [Photo::query()->where('file_name', 'pine-plan.png')->pluck('id')->all(), LegacyFile::query()->where('file_name', 'pine-plan.png')->pluck('id')->all()]);
    }

    #[Test]
    public function it_serves_a_file_without_a_discriminator_in_no_legacy_model_until_a_writer_classifies_it(): void
    {
        DB::table('files')->insert(['filename' => 'draft.txt', 'mime_type' => 'text/plain']);
        $unclassified = [Photo::query()->where('file_name', 'draft.txt')->pluck('id')->all(), FloorPlan::query()->where('file_name', 'draft.txt')->pluck('id')->all(), LegacyFile::query()->where('file_name', 'draft.txt')->pluck('id')->all()];
        $legacyId = DB::scalar("select legacy_id from files where filename = 'draft.txt'");

        DB::table('files')->where('filename', 'draft.txt')->update(['legacy_type' => 'legacy_file']);

        $this->assertSame([[], [], []], $unclassified);
        $this->assertSame($legacyId, LegacyFile::query()->where('file_name', 'draft.txt')->value('id'));
    }

    #[Test]
    public function it_keeps_the_legacy_identity_of_a_record_through_a_successor_update_that_leaves_the_discriminator(): void
    {
        $uuid = Photo::query()->findOrFail(1)->uuid;

        File::query()->where('legacy_type', 'photo')->where('legacy_id', 1)->firstOrFail()->update(['filename' => 'oak-front-cropped.jpg']);
        $photo = Photo::query()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame([1, 'oak-front-cropped.jpg'], [$photo->id, $photo->file_name]);
        $this->assertSame('oak-plan.pdf', FloorPlan::query()->findOrFail(1)->file_name);
    }

    #[Test]
    public function it_never_serves_a_successor_key_as_a_legacy_identifier(): void
    {
        DB::table('files')->insert(['filename' => 'pine.jpg', 'mime_type' => 'image/jpeg', 'legacy_type' => 'photo']);

        foreach (['photos', 'floorplans', 'legacy_files'] as $view) {
            $this->assertSame(0, DB::scalar("select count(*) from {$view} where cast(uuid as text) in (select cast(id as text) from files)"), $view);
        }
    }

    #[Test]
    public function it_creates_a_legacy_record_as_a_successor_row_that_carries_its_discriminator(): void
    {
        $photo = Photo::query()->create(['file_name' => 'birch.jpg', 'mime_type' => 'image/jpeg', 'size_kb' => 2, 'caption' => 'Birch']);
        $files = DB::select('select legacy_type, filename, size_bytes from files where legacy_id = ?', [$photo->id]);

        $this->assertGreaterThan(3, $photo->id);
        $this->assertEquals([(object) ['legacy_type' => 'photo', 'filename' => 'birch.jpg', 'size_bytes' => 2000]], $files);
        $this->assertSame(['birch.jpg', 'Birch'], [Photo::query()->findOrFail($photo->id)->file_name, Photo::query()->findOrFail($photo->id)->caption]);
        $this->assertNull(FloorPlan::query()->find($photo->id));
    }

    #[Test]
    public function it_refuses_a_record_write_whose_successor_listener_gives_the_row_another_legacy_models_discriminator(): void
    {
        File::creating(function (File $file): void {
            if ($file->filename === 'birch.jpg') {
                $file->legacy_type = 'legacy_file';
            }
        });
        File::updating(function (File $file): void {
            if ($file->filename === 'ash-back.jpg') {
                $file->legacy_type = 'legacy_file';
            }
        });
        $ash = Photo::query()->create(['file_name' => 'ash.jpg', 'mime_type' => 'image/jpeg', 'caption' => 'Ash']);
        $before = $this->legacyRows();
        $outcomes = [];

        foreach ([
            fn () => Photo::query()->create(['file_name' => 'birch.jpg', 'mime_type' => 'image/jpeg', 'size_kb' => 2, 'caption' => 'Birch']),
            fn () => $ash->update(['file_name' => 'ash-back.jpg']),
        ] as $write) {
            try {
                $write();
                $outcomes[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $outcomes[] = strstr($refusal->getMessage(), '. Why:', true);
            }
        }

        $this->assertSame([
            Photo::class.' cannot run a successor listener that gives a new record another legacy model through its compatibility map',
            Photo::class.' cannot run a successor listener that gives record '.$ash->id.' another legacy model through its compatibility map',
        ], $outcomes);
        $this->assertSame($before, $this->legacyRows());
        $this->assertSame(0, DB::table('files')->whereIn('filename', ['birch.jpg', 'ash-back.jpg'])->count());
        $this->assertSame(['ash.jpg', 'Ash'], [Photo::query()->findOrFail($ash->id)->file_name, Photo::query()->findOrFail($ash->id)->caption]);
    }

    #[Test]
    public function it_writes_a_record_only_to_the_successor_row_of_its_own_legacy_model(): void
    {
        FloorPlan::query()->findOrFail(1)->update(['file_name' => 'oak-plan-v2.pdf']);
        Photo::query()->findOrFail(2)->delete();

        $this->assertSame(['oak-front.jpg', 'oak-plan-v2.pdf'], [Photo::query()->findOrFail(1)->file_name, FloorPlan::query()->findOrFail(1)->file_name]);
        $this->assertSame([null, 'elm-plan.png'], [Photo::query()->find(2), FloorPlan::query()->findOrFail(2)->file_name]);
    }

    #[Test]
    public function it_filters_a_converted_attribute_among_the_rows_of_its_own_legacy_model(): void
    {
        DB::table('files')->where('legacy_type', 'floorplan')->where('legacy_id', 1)->update(['size_bytes' => 10 * 1024 * 1024]);

        $this->assertSame([2], Photo::query()->where('size_kb', '>', 1000)->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function it_writes_a_mass_update_increment_and_delete_only_to_the_rows_of_its_own_legacy_model(): void
    {
        Photo::query()->where('id', 1)->update(['file_name' => 'renamed.jpg']);
        Photo::query()->whereKey(1)->increment('views', 3);
        Photo::query()->where('id', 2)->delete();

        $this->assertSame([['renamed.jpg', 3], ['elm.png', 0]], Photo::query()->orderBy('id')->get(['file_name', 'views'])->map(fn (Photo $photo) => [$photo->file_name, $photo->views])->all());
        $this->assertSame(['oak-plan.pdf', 'elm-plan.png'], FloorPlan::query()->orderBy('id')->pluck('file_name')->all());
        $this->assertSame(['contract.pdf', 'notes.txt'], LegacyFile::query()->orderBy('id')->pluck('file_name')->all());
    }

    #[Test]
    public function it_locks_no_successor_row_of_another_legacy_model_for_a_mass_write(): void
    {
        config(['database.connections.compatibility_other' => config('database.connections.pgsql')]);
        $other = DB::connection('compatibility_other');
        $other->beginTransaction();

        try {
            $other->select("select id from files where legacy_type = 'floorplan' and legacy_id = 1 for update");
            DB::statement("set lock_timeout = '2s'");
            $updated = Photo::query()->where('id', 1)->update(['file_name' => 'renamed.jpg']);
        } finally {
            DB::statement('reset lock_timeout');
            $other->rollBack();
            DB::purge('compatibility_other');
        }

        $this->assertSame([1, 'renamed.jpg'], [$updated, Photo::query()->findOrFail(1)->file_name]);
    }

    #[Test]
    public function it_refuses_a_reference_to_a_photo_that_does_not_exist_even_when_another_legacy_model_holds_its_id(): void
    {
        $floorPlan = FloorPlan::query()->create(['file_name' => 'cedar-plan.pdf', 'mime_type' => 'application/pdf']);
        $refusals = [];

        foreach ([999, $floorPlan->id] as $photoId) {
            try {
                DB::table('photo_comments')->insert(['photo_id' => $photoId, 'body' => 'Dangling']);
            } catch (QueryException $exception) {
                $refusals[] = $exception->getCode();
            }
        }

        Photo::query()->findOrFail(1)->delete();

        $this->assertSame(['23503', '23503'], $refusals);
        $this->assertSame([3], DB::table('photo_comments')->pluck('photo_id')->all());
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function legacyRows(): array
    {
        $rows = [];

        foreach (['photos', 'floorplans', 'legacy_files'] as $table) {
            $rows[$table] = array_map(fn (object $row) => (array) $row, DB::select("select * from {$table} order by id"));
        }

        return $rows;
    }
}
