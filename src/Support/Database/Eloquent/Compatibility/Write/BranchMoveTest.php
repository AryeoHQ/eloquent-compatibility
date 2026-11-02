<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Video;
use Tests\TestCase;

#[CoversClass(BranchMove::class)]
#[CoversClass(RecordWriter::class)]
final class BranchMoveTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        FileTables::create();
        FileTables::seed();
        FileTables::seedMedia();
        FileTables::install();
        FileTables::installMedia();
    }

    protected function tearDown(): void
    {
        File::flushEventListeners();
        Link::flushEventListeners();
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_moves_an_upload_to_an_embed_and_keeps_its_identifiers_and_retained_row(): void
    {
        $uuid = Video::query()->findOrFail(1)->uuid;

        Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour']);
        $video = Video::query()->findOrFail(1);

        $this->assertSame([1, $uuid, 'https://youtu.be/tour', null, null, null], [$video->id, $video->uuid, $video->youtube_embed_url, $video->original_file_name, $video->mime_type, $video->size_kb]);
        $this->assertSame(['Tour', 5, '2026-02-01 10:00:00'], [$video->title, $video->views, $video->created_at?->toDateTimeString()]);
        $this->assertSame(0, DB::table('files')->where('legacy_type', 'video')->where('legacy_id', 1)->count());
        $this->assertEquals([(object) ['url' => 'https://youtu.be/tour', 'legacy_uuid' => $uuid]], DB::select("select url, legacy_uuid from links where legacy_type = 'video' and legacy_id = 1"));
        $this->assertSame(3, DB::table('videos')->count());
    }

    #[Test]
    public function it_moves_an_embed_to_an_upload_with_the_values_the_upload_stores(): void
    {
        Video::query()->findOrFail(2)->update(['youtube_embed_url' => null, 'original_file_name' => 'oak.mp4', 'mime_type' => 'video/mp4', 'size_kb' => 300, 'title' => 'Oak upload']);
        $video = Video::query()->findOrFail(2);

        $this->assertSame([null, 'oak.mp4', 'video/mp4', '300.000', 'Oak upload', 2], [$video->youtube_embed_url, $video->original_file_name, $video->mime_type, $video->size_kb, $video->title, $video->views]);
        $this->assertSame(0, DB::table('links')->where('legacy_type', 'video')->where('legacy_id', 2)->count());
        $this->assertSame(300000, DB::table('files')->where('legacy_type', 'video')->where('legacy_id', 2)->value('size_bytes'));
    }

    #[Test]
    public function it_fires_the_old_successors_delete_events_and_the_new_successors_create_events_in_order(): void
    {
        $events = [];
        File::deleting(function (File $file) use (&$events): void {
            $events[] = 'File deleting '.$file->legacy_id;
        });
        File::deleted(function (File $file) use (&$events): void {
            $events[] = 'File deleted '.$file->legacy_id;
        });
        Link::creating(function (Link $link) use (&$events): void {
            $events[] = 'Link creating '.$link->legacy_id;
        });
        Link::created(function (Link $link) use (&$events): void {
            $events[] = 'Link created '.$link->legacy_id;
        });

        Video::query()->findOrFail(3)->update(['youtube_embed_url' => 'https://youtu.be/drone']);

        $this->assertSame(['File deleting 3', 'File deleted 3', 'Link creating 3', 'Link created 3'], $events);
    }

    #[Test]
    public function it_changes_nothing_when_the_new_successor_refuses_the_move(): void
    {
        Link::creating(fn () => false);

        try {
            Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour', 'title' => 'Refused']);
            $this->fail('The move was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringContainsString('a listener of Link refused its save', $refusal->getMessage());
        }

        $this->assertUnmoved();
    }

    #[Test]
    public function it_changes_nothing_when_the_new_successor_row_cannot_be_stored(): void
    {
        try {
            Video::query()->findOrFail(2)->update(['youtube_embed_url' => null]);
            $this->fail('The move was not refused.');
        } catch (QueryException $exception) {
            $this->assertSame('23502', $exception->getCode());
        }

        $this->assertSame([2, 'https://youtu.be/oak', 'Oak walkthrough'], [Video::query()->findOrFail(2)->id, Video::query()->findOrFail(2)->youtube_embed_url, Video::query()->findOrFail(2)->title]);
        $this->assertSame(1, DB::table('links')->where('legacy_type', 'video')->where('legacy_id', 2)->count());
    }

    #[Test]
    public function it_runs_the_legacy_guards_before_a_move(): void
    {
        try {
            Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://vimeo.com/tour']);
            $this->fail('The move was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringStartsWith(Video::class." record 1 cannot take this write, because guard 'embed host' refuses it", $refusal->getMessage());
        }

        $this->assertUnmoved();
    }

    #[Test]
    public function it_refuses_a_move_that_writes_a_value_the_new_successor_cannot_store(): void
    {
        try {
            Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour', 'original_file_name' => 'kept.mp4']);
            $this->fail('The move was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringStartsWith(Video::class.'::$original_file_name cannot take a write', $refusal->getMessage());
        }

        $this->assertUnmoved();
    }

    #[Test]
    public function it_refuses_an_increment_that_would_move_the_record(): void
    {
        try {
            Video::query()->findOrFail(1)->increment('views', 1, ['youtube_embed_url' => 'https://youtu.be/tour']);
            $this->fail('The increment was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringStartsWith(Video::class.' cannot run an increment that moves record 1 to Link', $refusal->getMessage());
        }

        $this->assertUnmoved();
    }

    #[Test]
    public function it_moves_inside_the_callers_transaction_and_rolls_back_with_it(): void
    {
        DB::beginTransaction();
        Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour']);
        $moved = DB::table('links')->where('legacy_type', 'video')->where('legacy_id', 1)->count();
        DB::rollBack();

        $this->assertSame(1, $moved);
        $this->assertUnmoved();
    }

    private function assertUnmoved(): void
    {
        $video = Video::query()->findOrFail(1);

        $this->assertSame([1, 'tour.mp4', null, 'Tour', 5], [$video->id, $video->original_file_name, $video->youtube_embed_url, $video->title, $video->views]);
        $this->assertSame([1, 0], [DB::table('files')->where('legacy_type', 'video')->where('legacy_id', 1)->count(), DB::table('links')->where('legacy_type', 'video')->where('legacy_id', 1)->count()]);
    }
}
