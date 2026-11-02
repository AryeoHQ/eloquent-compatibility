<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Write\MassWriter;
use Support\Database\Eloquent\Compatibility\Write\RecordWriter;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\TestCase;

#[CoversClass(LegacyBuilder::class)]
#[CoversClass(RecordWriter::class)]
#[CoversClass(MassWriter::class)]
final class TwoSuccessorsTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    /** @var list<array<string, mixed>> */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        FileTables::create();
        FileTables::seed();
        FileTables::seedMedia();
        $this->before = array_map(fn (object $row) => (array) $row, DB::select('select * from videos order by id'));
        FileTables::install();
        FileTables::installMedia();
    }

    protected function tearDown(): void
    {
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_serves_each_video_from_whichever_successor_holds_it(): void
    {
        $this->assertSame('v', DB::scalar("select relkind::text from pg_class where oid = to_regclass('videos')"));
        $this->assertSame($this->before, array_map(fn (object $row) => (array) $row, DB::select('select * from videos order by id')));
        $this->assertSame(
            [[1, 'tour.mp4', null], [2, null, 'https://youtu.be/oak'], [3, 'drone.mov', null]],
            Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->original_file_name, $video->youtube_embed_url])->all(),
        );
        $this->assertSame([['files', 1], ['links', 2], ['files', 3]], $this->holders([1, 2, 3]));
    }

    #[Test]
    public function it_prepares_both_successors_and_the_legacy_table_to_draw_ids_from_one_sequence(): void
    {
        $this->assertSame(
            [['files', "nextval('compatibility_legacy_id_seq'::regclass)"], ['links', "nextval('compatibility_legacy_id_seq'::regclass)"], ['videos_retained', "nextval('compatibility_legacy_id_seq'::regclass)"]],
            array_map(fn (object $row) => [(string) $row->table_name, (string) $row->column_default], DB::select(
                "select table_name, column_default from information_schema.columns
                 where (table_name in ('files', 'links') and column_name = 'legacy_id') or (table_name = 'videos_retained' and column_name = 'id')
                 order by table_name",
            )),
        );
    }

    #[Test]
    public function it_serves_a_link_a_successor_writer_classifies_as_a_video(): void
    {
        $link = Link::query()->create(['url' => 'https://youtu.be/elm', 'legacy_type' => 'video']);
        $legacyId = DB::table('links')->where('id', $link->id)->value('legacy_id');

        $video = Video::query()->find($legacyId);

        $this->assertInstanceOf(Video::class, $video);
        $this->assertSame([$legacyId, 'https://youtu.be/elm', null], [$video->id, $video->youtube_embed_url, $video->original_file_name]);
        $this->assertNull(InteractiveContent::query()->where('url', 'https://youtu.be/elm')->first());
    }

    #[Test]
    public function it_serves_neither_legacy_model_of_a_shared_successor_the_rows_of_the_other(): void
    {
        $this->assertSame(['https://tour.example/oak', 'https://tour.example/elm'], InteractiveContent::query()->orderBy('id')->pluck('url')->all());
        $this->assertSame([1, 2, 3], Video::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 2, 3], Photo::query()->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function it_keeps_every_video_id_unique_across_both_successors(): void
    {
        $upload = Video::query()->create(['original_file_name' => 'garden.mp4', 'mime_type' => 'video/mp4', 'title' => 'Garden']);
        $embed = Video::query()->create(['youtube_embed_url' => 'https://youtu.be/garden', 'title' => 'Garden embed']);
        Link::query()->create(['url' => 'https://youtu.be/pine', 'legacy_type' => 'video']);
        File::query()->create(['filename' => 'pine.mp4', 'mime_type' => 'video/mp4', 'legacy_type' => 'video']);

        $ids = Video::query()->orderBy('id')->pluck('id')->all();

        $this->assertSame([['files', $upload->id], ['links', $embed->id]], $this->holders([$upload->id, $embed->id]));
        $this->assertCount(7, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertGreaterThan(3, min($upload->id, $embed->id));
        $this->assertSame([['Garden', 0], ['Garden embed', 0]], [[$upload->refresh()->title, $upload->views], [$embed->refresh()->title, $embed->views]]);
    }

    #[Test]
    public function it_writes_a_record_through_the_successor_that_holds_it(): void
    {
        Video::query()->findOrFail(1)->update(['original_file_name' => 'tour-v2.mp4', 'title' => 'Tour v2']);
        Video::query()->findOrFail(2)->update(['youtube_embed_url' => 'https://youtu.be/oak-v2']);

        $this->assertSame([['files', 1], ['links', 2]], $this->holders([1, 2]));
        $this->assertSame(['tour-v2.mp4', 'Tour v2', 'https://youtu.be/oak-v2'], [Video::query()->findOrFail(1)->original_file_name, Video::query()->findOrFail(1)->title, Video::query()->findOrFail(2)->youtube_embed_url]);
    }

    #[Test]
    public function it_chooses_the_branch_from_the_stored_record_when_the_model_was_loaded_without_the_selectors_inputs(): void
    {
        Video::query()->findOrFail(2, ['id', 'title'])->update(['title' => 'Oak tour']);

        $this->assertSame([['links', 2]], $this->holders([2]));
        $this->assertSame(['Oak tour', 'https://youtu.be/oak'], [Video::query()->findOrFail(2)->title, Video::query()->findOrFail(2)->youtube_embed_url]);
    }

    #[Test]
    public function it_increments_and_deletes_a_record_in_either_successor(): void
    {
        Video::query()->findOrFail(1)->increment('views', 4);
        Video::query()->findOrFail(2)->increment('views', 3);
        $views = Video::query()->whereIn('id', [1, 2])->orderBy('id')->pluck('views')->all();
        Video::query()->findOrFail(1)->delete();
        Video::query()->findOrFail(2)->delete();

        $this->assertSame([9, 5], $views);
        $this->assertSame([3], Video::query()->pluck('id')->all());
        $this->assertSame([[], []], [$this->holders([1, 2]), DB::table('videos_retained')->whereIn('id', [1, 2])->pluck('id')->all()]);
    }

    #[Test]
    public function it_writes_a_mass_update_to_the_records_of_both_successors(): void
    {
        $updated = Video::query()->whereIn('id', [1, 2])->update(['title' => 'Featured']);
        Video::query()->whereIn('id', [2, 3])->increment('views', 10);
        Video::query()->where('id', 3)->update(['original_file_name' => 'drone-v2.mov']);

        $this->assertSame(2, $updated);
        $this->assertSame(
            [[1, 'Featured', 5, 'tour.mp4'], [2, 'Featured', 12, null], [3, null, 10, 'drone-v2.mov']],
            Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->title, $video->views, $video->original_file_name])->all(),
        );
    }

    #[Test]
    public function it_refuses_a_mass_write_that_would_move_records_between_successors(): void
    {
        try {
            Video::query()->whereIn('id', [1, 3])->update(['youtube_embed_url' => 'https://youtu.be/moved', 'title' => 'Moved']);
            $this->fail('The mass write was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringContainsString('It would move record 1 from File to Link', $refusal->getMessage());
        }

        $this->assertSame([['files', 1], ['files', 3]], $this->holders([1, 3]));
        $this->assertSame(['Tour', null], Video::query()->whereIn('id', [1, 3])->orderBy('id')->pluck('title')->all());
    }

    #[Test]
    public function it_refuses_a_mass_write_of_a_value_the_holding_successor_cannot_store(): void
    {
        try {
            Video::query()->whereIn('id', [1, 2])->update(['size_kb' => 1]);
            $this->fail('The mass write was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringStartsWith(Video::class.'::$size_kb cannot take a write', $refusal->getMessage());
        }

        $this->assertSame(['2048.000', null], Video::query()->whereIn('id', [1, 2])->orderBy('id')->pluck('size_kb')->all());
    }

    #[Test]
    public function it_writes_a_value_only_one_successor_stores_to_the_records_that_successor_holds(): void
    {
        Video::query()->whereIn('id', [1, 3])->update(['size_kb' => 1]);

        $this->assertSame(['1.000', null, '1.000'], Video::query()->orderBy('id')->pluck('size_kb')->all());
    }

    #[Test]
    public function it_mass_deletes_the_records_of_both_successors_and_their_retained_rows(): void
    {
        $deleted = Video::query()->where('title', 'like', '%o%')->delete();

        $this->assertSame(2, $deleted);
        $this->assertSame([3], Video::query()->pluck('id')->all());
        $this->assertSame([[], [3]], [$this->holders([1, 2]), DB::table('videos_retained')->pluck('id')->all()]);
        $this->assertSame(2, DB::table('interactive_content')->count());
    }

    #[Test]
    public function it_filters_a_converted_attribute_across_both_successors(): void
    {
        $this->assertSame([2], Video::query()->whereNull('size_kb')->pluck('id')->all());
        $this->assertSame([1], Video::query()->where('size_kb', '>', 1000)->pluck('id')->all());
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{string, int}>
     */
    private function holders(array $ids): array
    {
        return array_map(fn (object $row) => [(string) $row->holder, (int) $row->legacy_id], DB::select(
            "select 'files' as holder, legacy_id from files where legacy_type = 'video' and legacy_id = any(?::int[])
             union all
             select 'links', legacy_id from links where legacy_type = 'video' and legacy_id = any(?::int[])
             order by legacy_id, holder",
            ['{'.implode(',', $ids).'}', '{'.implode(',', $ids).'}'],
        ));
    }
}
