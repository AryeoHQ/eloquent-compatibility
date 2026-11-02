<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
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
    public function it_treats_a_move_that_writes_an_external_or_read_only_attribute_as_an_update_in_place_treats_it(): void
    {
        $video = $this->restrictedVideo();
        $outcomes = [];

        foreach ([
            fn () => $video->newQuery()->findOrFail(1)->update(['original_file_name' => 'renamed.mp4']),
            fn () => $video->newQuery()->findOrFail(2)->update(['youtube_embed_url' => null, 'original_file_name' => 'oak.mp4', 'mime_type' => 'video/mp4']),
            fn () => $video->newQuery()->findOrFail(3)->update(['thumbnail' => 'drone.jpg']),
            fn () => $video->newQuery()->findOrFail(3)->update(['youtube_embed_url' => 'https://youtu.be/drone', 'thumbnail' => 'drone.jpg']),
        ] as $write) {
            try {
                $write();
                $outcomes[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $outcomes[] = strstr($refusal->getMessage(), '. Why:', true);
            }
        }

        $this->assertSame([
            $video::class.' record 1 cannot change $original_file_name',
            $video::class.' record 2 cannot change $original_file_name',
            'stored',
            'stored',
        ], $outcomes);
        $this->assertSame([[1, 'tour.mp4']], DB::table('files')->where('legacy_type', 'video')->orderBy('legacy_id')->get(['legacy_id', 'filename'])->map(fn (object $row) => [(int) $row->legacy_id, (string) $row->filename])->all());
        $this->assertSame([[2, 'https://youtu.be/oak'], [3, 'https://youtu.be/drone']], DB::table('links')->where('legacy_type', 'video')->orderBy('legacy_id')->get(['legacy_id', 'url'])->map(fn (object $row) => [(int) $row->legacy_id, (string) $row->url])->all());
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
    public function it_gives_the_legacy_record_the_values_the_new_successor_stored_before_its_own_events_run(): void
    {
        File::saving(function (File $file): void {
            $file->filename = strtoupper($file->filename);
        });
        $seen = [];
        Event::listen('eloquent.updated: '.Video::class, function (Video $video) use (&$seen): void {
            $seen[] = $video->original_file_name;
        });
        $video = Video::query()->findOrFail(2);

        $video->update(['youtube_embed_url' => null, 'original_file_name' => 'oak.mp4', 'mime_type' => 'video/mp4']);

        $this->assertSame([['OAK.MP4'], 'OAK.MP4', 'OAK.MP4'], [$seen, $video->original_file_name, Video::query()->findOrFail(2)->original_file_name]);
    }

    #[Test]
    public function it_gives_the_legacy_record_every_value_the_new_successor_serves_after_a_move_before_its_own_events_run(): void
    {
        $seen = [];
        Event::listen('eloquent.updated: '.Video::class, function (Video $video) use (&$seen): void {
            $seen[] = [$video->original_file_name, $video->mime_type, $video->size_kb];
        });
        $video = Video::query()->findOrFail(1);

        $video->update(['youtube_embed_url' => 'https://youtu.be/tour']);
        $served = Video::query()->findOrFail(1);

        $this->assertSame([[[null, null, null]], [null, null, null]], [$seen, [$video->original_file_name, $video->mime_type, $video->size_kb]]);
        $this->assertSame([null, null, null], [$served->original_file_name, $served->mime_type, $served->size_kb]);
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

    private function restrictedVideo(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'videos';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'video';
            }

            public static function compatibility(Compatibility $video): Compatibility
            {
                return $video
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('videos_retained')
                    ->timestamps()
                    ->servedFrom(fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null ? Link::class : File::class)
                    ->as(File::class, fn (Successor $file) => $file
                        ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename')->readOnly('The upload names its own file.'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type'))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('thumbnail', fn (Attribute $attribute) => $attribute->external(read: fn (Collection $files): array => $files->mapWithKeys(fn (Model $file) => [$file->getKey() => null])->all(), write: fn (File $file, mixed $value) => $value)))
                    ->as(Link::class, fn (Successor $link) => $link
                        ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url'))
                        ->attribute('thumbnail', fn (Attribute $attribute) => $attribute->external(read: fn (Collection $links): array => $links->mapWithKeys(fn (Model $link) => [$link->getKey() => null])->all(), write: fn (Link $link, mixed $value) => $value)));
            }
        };
    }

    private function assertUnmoved(): void
    {
        $video = Video::query()->findOrFail(1);

        $this->assertSame([1, 'tour.mp4', null, 'Tour', 5], [$video->id, $video->original_file_name, $video->youtube_embed_url, $video->title, $video->views]);
        $this->assertSame([1, 0], [DB::table('files')->where('legacy_type', 'video')->where('legacy_id', 1)->count(), DB::table('links')->where('legacy_type', 'video')->where('legacy_id', 1)->count()]);
    }
}
