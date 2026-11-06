<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Carbon\CarbonInterval;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Write\MassWriter;
use Support\Database\Eloquent\Compatibility\Write\RecordWriter;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileTables;
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
            Compatibility::install(
                model: $model,
                statementTimeout: CarbonInterval::seconds(FileTables::TIMEOUT),
            );
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
        $this->assertSame(['v', 'v'], array_map(
            callback: fn (string $view) => DB::scalar(
                query: 'select relkind::text from pg_class where oid = to_regclass(?)',
                bindings: [$view],
            ),
            array: ['photos', 'legacy_files'],
        ));
        $this->assertSame(['photos' => array_map(fn (array $row) => array_diff_key($row, ['mime_type' => true]), $this->before['photos'])] + $this->before, $this->legacyRows());
        $this->assertSame(array_column($this->before['photos'], 'mime_type'), Photo::query()->orderBy('id')->get()->map(fn (Photo $photo) => $photo->mime_type)->all());
        $this->assertSame(['oak-front.jpg', 'contract.pdf'], [Photo::query()->findOrFail(1)->file_name, LegacyFile::query()->findOrFail(1)->file_name]);
    }

    #[Test]
    public function it_serves_a_file_a_successor_writer_creates_in_no_legacy_model_while_no_retained_row_names_it(): void
    {
        DB::table('files')->insert(['filename' => 'pine.jpg', 'mime_type' => 'image/jpeg']);

        $this->assertSame([[], []], [Photo::query()->where(
            column: 'file_name',
            operator: '=',
            value: 'pine.jpg',
        )->pluck('id')->all(), LegacyFile::query()->where(
            column: 'file_name',
            operator: '=',
            value: 'pine.jpg',
        )->pluck('id')->all()]);
        $this->assertSame([3, 2], [Photo::query()->toBase()->count(), LegacyFile::query()->toBase()->count()]);
    }

    #[Test]
    public function it_keeps_the_legacy_identity_of_a_record_through_a_successor_update(): void
    {
        $uuid = Photo::query()->findOrFail(1)->uuid;

        File::query()->findOrFail(DB::table('photos_retained')->where(
            column: 'id',
            operator: '=',
            value: 1,
        )->value('file_id'))->update(['filename' => 'oak-front-cropped.jpg']);
        $photo = Photo::query()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame([1, 'oak-front-cropped.jpg'], [$photo->id, $photo->file_name]);
        $this->assertSame('contract.pdf', LegacyFile::query()->findOrFail(1)->file_name);
    }

    #[Test]
    public function it_never_serves_a_successor_key_as_a_legacy_identifier(): void
    {
        DB::table('files')->insert(['filename' => 'pine.jpg', 'mime_type' => 'image/jpeg']);

        foreach (['photos', 'legacy_files'] as $view) {
            $this->assertSame(0, DB::scalar("select count(*) from {$view} where cast(uuid as text) in (select cast(id as text) from files)"), $view);
        }
    }

    #[Test]
    public function it_creates_a_legacy_record_as_a_successor_row_and_a_retained_row_that_keeps_its_own_identity(): void
    {
        $photo = Photo::query()->create(['file_name' => 'birch.jpg', 'mime_type' => 'image/jpeg', 'size_kb' => 2, 'caption' => 'Birch']);
        $file = DB::selectOne(
            query: 'select filename, size_bytes from files where id = (select file_id from photos_retained where id = ?)',
            bindings: [$photo->id],
        );
        $legacyFile = LegacyFile::query()->create(['file_name' => 'lease.pdf', 'mime_type' => 'application/pdf']);

        $this->assertSame([4, 3], [$photo->id, $legacyFile->id]);
        $this->assertEquals((object) ['filename' => 'birch.jpg', 'size_bytes' => 2000], $file);
        $this->assertSame(['birch.jpg', 'Birch'], [Photo::query()->findOrFail($photo->id)->file_name, Photo::query()->findOrFail($photo->id)->caption]);
        $this->assertSame(['elm.png', 'lease.pdf'], [Photo::query()->findOrFail(3)->file_name, LegacyFile::query()->findOrFail(3)->file_name]);
    }

    #[Test]
    public function it_writes_a_record_only_to_the_successor_row_of_its_own_legacy_model(): void
    {
        LegacyFile::query()->findOrFail(1)->update(['file_name' => 'contract-v2.pdf']);
        Photo::query()->findOrFail(2)->delete();

        $this->assertSame(['oak-front.jpg', 'contract-v2.pdf'], [Photo::query()->findOrFail(1)->file_name, LegacyFile::query()->findOrFail(1)->file_name]);
        $this->assertSame([null, 'notes.txt'], [Photo::query()->find(2), LegacyFile::query()->findOrFail(2)->file_name]);
    }

    #[Test]
    public function it_filters_a_converted_attribute_among_the_rows_of_its_own_legacy_model(): void
    {
        DB::table('files')->where(
            column: 'id',
            operator: '=',
            value: DB::table('legacy_files_retained')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('file_id'),
        )->update(['size_bytes' => 10 * 1024 * 1024]);

        $this->assertSame([2], Photo::query()->where('size_kb', '>', 1000)->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function it_writes_a_mass_update_increment_and_delete_only_to_the_rows_of_its_own_legacy_model(): void
    {
        Photo::query()->where('id', 1)->update(['file_name' => 'renamed.jpg']);
        Photo::query()->whereKey(1)->increment('views', 3);
        Photo::query()->where('id', 2)->delete();

        $this->assertSame([['renamed.jpg', 3], ['elm.png', 0]], Photo::query()->orderBy('id')->get(['file_name', 'views'])->map(fn (Photo $photo) => [$photo->file_name, $photo->views])->all());
        $this->assertSame(['contract.pdf', 'notes.txt'], LegacyFile::query()->orderBy('id')->pluck('file_name')->all());
    }

    #[Test]
    public function it_locks_no_successor_row_of_another_legacy_model_for_a_mass_write(): void
    {
        config(['database.connections.compatibility_other' => config('database.connections.pgsql')]);
        $other = DB::connection('compatibility_other');
        $other->beginTransaction();

        try {
            $other->select('select id from files where id = (select file_id from legacy_files_retained where id = 1) for update');
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
    public function it_keeps_each_inbound_foreign_key_on_the_retained_table_of_its_own_legacy_model(): void
    {
        $legacyFile = LegacyFile::query()->create(['file_name' => 'lease.pdf', 'mime_type' => 'application/pdf']);
        $refusals = [];

        foreach ([999, 3] as $photoId) {
            try {
                DB::table('photo_comments')->insert(['photo_id' => $photoId, 'body' => 'Dangling '.$photoId]);
                $refusals[] = 'stored';
            } catch (QueryException $exception) {
                $refusals[] = $exception->getCode();
            }
        }

        Photo::query()->findOrFail(1)->delete();

        $this->assertSame([3, ['23503', 'stored']], [$legacyFile->id, $refusals]);
        $this->assertSame([3, 3], DB::table('photo_comments')->orderBy('id')->pluck('photo_id')->all());
        $this->assertSame('photos_retained', DB::scalar("select confrelid::regclass::text from pg_constraint where conname = 'photo_comments_photo_id_foreign'"));
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function legacyRows(): array
    {
        $rows = [];

        foreach (['photos', 'legacy_files'] as $table) {
            $rows[$table] = array_map(fn (object $row) => (array) $row, DB::select("select * from {$table} order by id"));
        }

        return $rows;
    }
}
