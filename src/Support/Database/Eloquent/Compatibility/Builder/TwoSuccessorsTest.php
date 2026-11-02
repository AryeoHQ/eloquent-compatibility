<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Write\MassWriter;
use Support\Database\Eloquent\Compatibility\Write\RecordWriter;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\ListingStatus;
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
        Link::flushEventListeners();
        File::flushEventListeners();
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

    #[Test]
    public function it_refuses_an_explicit_identifier_the_other_successor_already_holds_as_a_native_table_refuses_a_duplicate_key(): void
    {
        $embedUuid = Video::query()->findOrFail(2)->uuid;
        $codes = [];

        foreach ([
            fn () => Video::query()->create(['id' => 1, 'youtube_embed_url' => 'https://youtu.be/dup']),
            fn () => Video::query()->create(['id' => 2, 'original_file_name' => 'dup.mp4', 'mime_type' => 'video/mp4']),
            fn () => Video::query()->create(['uuid' => strtoupper($embedUuid), 'original_file_name' => 'dup.mp4', 'mime_type' => 'video/mp4']),
        ] as $insert) {
            try {
                $insert();
                $codes[] = 'stored';
            } catch (UniqueConstraintViolationException $exception) {
                $codes[] = $exception->getCode();
            }
        }

        $ignored = [
            Video::query()->toBase()->insertOrIgnore(['id' => 3, 'youtube_embed_url' => 'https://youtu.be/dup']),
            Video::query()->toBase()->insertOrIgnore(['id' => 40, 'youtube_embed_url' => 'https://youtu.be/fresh']),
        ];

        $this->assertSame(['23505', '23505', '23505'], $codes);
        $this->assertSame([0, 1], $ignored);
        $this->assertSame([1, 2, 3, 40], Video::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame([['files', 1], ['links', 2], ['files', 3], ['links', 40]], $this->holders([1, 2, 3, 40]));
    }

    #[Test]
    public function it_refuses_an_allocated_identifier_an_explicit_insert_already_gave_a_record_of_the_other_successor(): void
    {
        $next = (int) DB::scalar('select case when is_called then last_value + 1 else last_value end from compatibility_legacy_id_seq');
        Video::query()->create(['id' => $next, 'youtube_embed_url' => 'https://youtu.be/ahead', 'title' => 'Ahead']);

        try {
            Video::query()->create(['original_file_name' => 'next.mp4', 'mime_type' => 'video/mp4']);
            $code = 'stored';
        } catch (UniqueConstraintViolationException $exception) {
            $code = $exception->getCode();
        }

        $retried = Video::query()->create(['original_file_name' => 'next.mp4', 'mime_type' => 'video/mp4']);

        $this->assertSame('23505', $code);
        $this->assertSame([['links', $next], ['files', $next + 1]], $this->holders([$next, $next + 1]));
        $this->assertSame([$next + 1, 'next.mp4'], [$retried->id, Video::query()->findOrFail($next + 1)->original_file_name]);
    }

    #[Test]
    public function it_refuses_an_increment_of_an_identifier_through_a_record_or_a_mass_write(): void
    {
        $messages = [];

        foreach ([
            fn () => Video::query()->findOrFail(1)->increment('id'),
            fn () => Video::query()->whereKey(1)->increment('id'),
            fn () => Video::query()->findOrFail(1)->decrement('id'),
            fn () => Video::query()->whereKey(1)->toBase()->incrementEach(['videos.id' => 1]),
        ] as $write) {
            try {
                $write();
                $messages[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $messages[] = strstr($refusal->getMessage(), '. Why:', true);
            }
        }

        $this->assertSame(array_fill(0, 4, Video::class.' cannot run an increment of its identifier id through its compatibility map'), $messages);
        $this->assertSame([['files', 1], ['links', 2], ['files', 3]], $this->holders([1, 2, 3]));
        $this->assertSame([1, 2, 3], DB::table('videos_retained')->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function it_chooses_the_branch_from_the_value_a_retained_expression_gives(): void
    {
        $video = $this->titledVideo();
        $incremented = Video::query()->create(['youtube_embed_url' => 'https://youtu.be/pine', 'title' => 'Pine tour'])->id;
        $mass = Video::query()->create(['youtube_embed_url' => 'https://youtu.be/elm', 'title' => 'Elm tour'])->id;
        $outcomes = [];

        foreach ([
            fn () => $video->newQuery()->findOrFail(2)->update(['title' => DB::raw("'Upload only'"), 'original_file_name' => 'oak.mp4', 'mime_type' => 'video/mp4']),
            fn () => $video->newQuery()->findOrFail($incremented)->increment('views', 1, ['title' => DB::raw("'Upload only'")]),
            fn () => $video->newQuery()->whereKey($mass)->update(['title' => DB::raw("'Upload only'")]),
        ] as $write) {
            try {
                $write();
                $outcomes[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $outcomes[] = strstr($refusal->getMessage(), '. Why:', true);
            }
        }

        $this->assertSame([
            'stored',
            $video::class.' cannot run an increment that moves record '.$incremented.' to File through its compatibility map',
            $video::class.' cannot run a mass write that changes what servedFrom() chooses through its compatibility map',
        ], $outcomes);
        $this->assertSame([['files', 2], ['links', $incremented], ['links', $mass]], $this->holders([2, $incremented, $mass]));
        $this->assertSame([['Upload only', 2], ['Pine tour', 0], ['Elm tour', 0]], Video::query()->whereIn('id', [2, $incremented, $mass])->orderBy('id')->get()->map(fn (Video $video) => [$video->title, $video->views])->all());
    }

    #[Test]
    public function it_stores_the_value_a_volatile_retained_expression_gave_the_branch_choice(): void
    {
        $video = $this->titledVideo();
        $ids = array_map(fn (string $name) => Video::query()->create(['youtube_embed_url' => 'https://youtu.be/'.$name, 'title' => ucfirst($name).' tour'])->id, ['pine', 'elm', 'fir']);
        DB::statement('create temporary sequence volatile_title');
        $volatile = DB::raw("case when nextval('volatile_title') = 1 then 'Kept as a link' else 'Upload only' end");
        $draws = [];

        try {
            foreach ([
                fn (int $id) => $video->newQuery()->findOrFail($id)->update(['title' => $volatile]),
                fn (int $id) => $video->newQuery()->findOrFail($id)->increment('views', 1, ['title' => $volatile]),
                fn (int $id) => $video->newQuery()->whereKey($id)->update(['title' => $volatile]),
            ] as $index => $write) {
                DB::statement("select setval('volatile_title', 1, false)");
                $write($ids[$index]);
                $draws[] = (int) DB::scalar('select last_value from volatile_title');
            }
        } finally {
            DB::statement('drop sequence if exists volatile_title');
        }

        $this->assertSame([1, 1, 1], $draws);
        $this->assertSame(array_map(fn (int $id) => ['links', $id], $ids), $this->holders($ids));
        $this->assertSame(array_fill(0, 3, 'Kept as a link'), Video::query()->whereIn('id', $ids)->orderBy('id')->pluck('title')->all());
    }

    #[Test]
    public function it_writes_each_record_a_joined_mass_write_matches_once(): void
    {
        $joined = function () {
            $query = Video::query()->whereKey(1);
            $query->getQuery()->crossJoin(DB::raw('generate_series(1, 2) as copies'));

            return $query;
        };
        $outcomes = [];

        foreach ([
            fn () => $joined()->update(['title' => 'Joined']),
            fn () => $joined()->toBase()->update(['title' => 'Joined through the base']),
            fn () => $joined()->update(['id' => 50]),
        ] as $write) {
            try {
                $outcomes[] = $write();
            } catch (QueryException $exception) {
                $outcomes[] = $exception->getCode();
            }
        }

        $this->assertSame([1, 1, 1], $outcomes);
        $this->assertSame([['files', 50]], $this->holders([1, 50]));
        $this->assertSame([2, 3, 50], DB::table('videos_retained')->orderBy('id')->pluck('id')->all());
        $this->assertSame('Joined through the base', Video::query()->findOrFail(50)->title);
    }

    #[Test]
    public function it_refuses_an_identifier_a_successor_listener_gives_a_new_record_when_the_other_successor_holds_it(): void
    {
        $upload = Video::query()->findOrFail(1);
        Link::creating(function (Link $link) use ($upload): void {
            match ($link->url) {
                'https://youtu.be/uuid' => $link->legacy_uuid = $upload->uuid,
                'https://youtu.be/id' => $link->legacy_id = $upload->id,
                default => null,
            };
        });
        $codes = [];

        foreach ([
            fn () => Video::query()->create(['uuid' => (string) Str::uuid(), 'youtube_embed_url' => 'https://youtu.be/uuid']),
            fn () => Video::query()->create(['id' => 60, 'youtube_embed_url' => 'https://youtu.be/id']),
            fn () => Video::query()->findOrFail(3)->update(['youtube_embed_url' => 'https://youtu.be/uuid']),
        ] as $insert) {
            try {
                $insert();
                $codes[] = 'stored';
            } catch (UniqueConstraintViolationException $exception) {
                $codes[] = $exception->getCode();
            }
        }

        $this->assertSame(['23505', '23505', '23505'], $codes);
        $this->assertSame(0, DB::table('links')->whereIn('url', ['https://youtu.be/uuid', 'https://youtu.be/id'])->count());
        $this->assertSame([['files', 1], ['files', 3]], $this->holders([1, 3]));
        $this->assertSame([1], Video::query()->where('uuid', $upload->uuid)->pluck('id')->all());
    }

    #[Test]
    public function it_chooses_the_branch_of_an_insert_from_the_value_a_retained_expression_gives(): void
    {
        $video = $this->titledVideo();
        $outcomes = [];

        foreach ([
            fn () => $video->newQuery()->create(['youtube_embed_url' => 'https://youtu.be/pine', 'title' => DB::raw("'Upload only'")]),
            fn () => $video->newQuery()->create(['original_file_name' => 'pine.mp4', 'mime_type' => 'video/mp4', 'title' => DB::raw("'Upload ' || 'only'")]),
        ] as $insert) {
            try {
                $outcomes[] = $insert()->getKey();
            } catch (CompatibilityWriteRefused $refusal) {
                $outcomes[] = strstr($refusal->getMessage(), '. Why:', true);
            }
        }

        $this->assertSame($video::class.'::$youtube_embed_url cannot take a write', $outcomes[0]);
        $this->assertIsInt($outcomes[1]);
        $this->assertSame([['files', $outcomes[1]]], $this->holders([$outcomes[1]]));
        $this->assertSame(0, DB::table('links')->where('url', 'https://youtu.be/pine')->count());
        $this->assertSame([['pine.mp4', 'Upload only']], Video::query()->where('id', '>', 3)->get()->map(fn (Video $video) => [$video->original_file_name, $video->title])->all());
    }

    #[Test]
    public function it_refuses_an_identifier_a_successor_listener_gives_an_existing_record_when_the_other_successor_holds_it(): void
    {
        $embed = Video::query()->findOrFail(2);
        File::updating(function (File $file) use ($embed): void {
            match ($file->filename) {
                'uuid.mp4' => $file->legacy_uuid = $embed->uuid,
                'id.mp4' => $file->legacy_id = $embed->id,
                default => null,
            };
        });
        $codes = [];

        foreach ([
            fn () => Video::query()->findOrFail(1)->update(['original_file_name' => 'uuid.mp4']),
            fn () => Video::query()->findOrFail(1)->increment('views', 1, ['original_file_name' => 'uuid.mp4']),
            fn () => Video::query()->findOrFail(1)->update(['original_file_name' => 'id.mp4']),
        ] as $write) {
            try {
                $write();
                $codes[] = 'stored';
            } catch (UniqueConstraintViolationException $exception) {
                $codes[] = $exception->getCode();
            }
        }

        $this->assertSame(['23505', '23505', '23505'], $codes);
        $this->assertSame([['files', 1], ['links', 2]], $this->holders([1, 2]));
        $this->assertSame([2], Video::query()->where('uuid', $embed->uuid)->pluck('id')->all());
        $this->assertSame(['tour.mp4', 5], [Video::query()->findOrFail(1)->original_file_name, Video::query()->findOrFail(1)->views]);
    }

    #[Test]
    public function it_refuses_a_successor_listener_that_changes_the_legacy_id_of_a_record_it_writes_or_moves(): void
    {
        File::updating(function (File $file): void {
            if ($file->filename === 'sixty.mp4') {
                $file->legacy_id = 60;
            }
        });
        Link::creating(function (Link $link): void {
            if ($link->url === 'https://youtu.be/sixty') {
                $link->legacy_id = 60;
            }
        });
        $instances = [Video::query()->findOrFail(1), Video::query()->findOrFail(1), Video::query()->findOrFail(1)];
        $messages = [];

        foreach ([
            fn () => $instances[0]->update(['original_file_name' => 'sixty.mp4']),
            fn () => $instances[1]->increment('views', 1, ['original_file_name' => 'sixty.mp4']),
            fn () => $instances[2]->update(['youtube_embed_url' => 'https://youtu.be/sixty']),
        ] as $write) {
            try {
                $write();
                $messages[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $messages[] = $refusal->getMessage();
            }
        }

        $refusal = fn (string $successor) => CompatibilityWriteRefused::unsupported(Video::class, 'a successor listener that changes the id of record 1', "{$successor} stored id 60 where the write gives 1, and the retained values and the instance follow the id the write gives.")->getMessage();
        $this->assertSame([$refusal('File'), $refusal('File'), $refusal('Link')], $messages);
        $this->assertSame([['files', 1]], $this->holders([1, 60]));
        $this->assertSame([1, 2, 3], DB::table('videos_retained')->orderBy('id')->pluck('id')->all());
        $this->assertSame([['tour.mp4', 'Tour', 5]], Video::query()->whereKey(1)->get()->map(fn (Video $video) => [$video->original_file_name, $video->title, $video->views])->all());
    }

    #[Test]
    public function it_predicts_the_branch_of_an_increment_from_the_value_an_explicit_write_of_the_incremented_column_stores(): void
    {
        $video = $this->viewedVideo();
        $outcomes = [];

        foreach ([
            fn () => $video->newQuery()->findOrFail(2)->increment('views', 1, ['views' => 1]),
            fn () => $video->newQuery()->whereKey(2)->increment('views', 1, ['views' => 1]),
            fn () => $video->newQuery()->whereKey(2)->toBase()->incrementEach(['videos.views' => 1], ['views' => 1]),
        ] as $write) {
            DB::table('videos_retained')->where('id', 2)->update(['views' => 2]);

            try {
                $write();
                $outcomes[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $outcomes[] = strstr($refusal->getMessage(), '. Why:', true);
            }
        }

        $this->assertSame([
            $video::class.' cannot run an increment that moves record 2 to File through its compatibility map',
            $video::class.' cannot run a mass write that changes what servedFrom() chooses through its compatibility map',
            $video::class.' cannot run a mass write that changes what servedFrom() chooses through its compatibility map',
        ], $outcomes);
        $this->assertSame([['links', 2]], $this->holders([2]));
        $this->assertSame(2, Video::query()->findOrFail(2)->views);
    }

    #[Test]
    public function it_stores_the_backing_value_of_an_enum_a_branched_mass_write_gives_a_legacy_only_column(): void
    {
        Video::query()->whereIn('id', [1, 2])->update(['title' => ListingStatus::Sold]);
        Video::query()->whereKey(3)->toBase()->update(['title' => ListingStatus::Draft]);

        $this->assertSame(['Sold', 'Sold', 'Draft'], Video::query()->orderBy('id')->pluck('title')->all());
    }

    #[Test]
    public function it_returns_the_column_an_insert_get_id_names_for_a_record_either_successor_stores(): void
    {
        $uuid = Video::query()->toBase()->insertGetId(['youtube_embed_url' => 'https://youtu.be/cedar', 'title' => 'Cedar'], 'uuid');
        $id = Video::query()->toBase()->insertGetId(['original_file_name' => 'cedar.mp4', 'mime_type' => 'video/mp4'], 'id');

        $this->assertSame(['Cedar', 'cedar.mp4'], [Video::query()->where('uuid', $uuid)->value('title'), Video::query()->whereKey($id)->value('original_file_name')]);
        $this->assertIsString($uuid);
        $this->assertIsInt($id);
    }

    #[Test]
    public function it_reads_a_retained_expression_of_a_mass_update_against_the_record_before_the_update(): void
    {
        Video::query()->whereKey(1)->update(['original_file_name' => 'new.mp4', 'title' => DB::raw('original_file_name')]);

        $this->assertSame(['new.mp4', 'tour.mp4'], [Video::query()->findOrFail(1)->original_file_name, Video::query()->findOrFail(1)->title]);
    }

    #[Test]
    public function it_keeps_the_retained_values_of_a_record_whose_legacy_id_a_write_changes(): void
    {
        Video::query()->findOrFail(1)->update(['id' => 10]);
        Video::query()->whereKey(2)->update(['id' => 20]);
        $moved = Video::query()->findOrFail(3);
        $moved->update(['id' => 30, 'youtube_embed_url' => 'https://youtu.be/drone']);

        $this->assertSame(
            [[10, 'Tour', 5], [20, 'Oak walkthrough', 2], [30, null, 0]],
            Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->title, $video->views])->all(),
        );
        $this->assertSame([10, 20, 30], DB::table('videos_retained')->orderBy('id')->pluck('id')->all());
        $this->assertSame([['files', 10], ['links', 20], ['links', 30]], $this->holders([10, 20, 30]));
        $this->assertSame([30, 'https://youtu.be/drone'], [$moved->id, $moved->youtube_embed_url]);
    }

    #[Test]
    public function it_refuses_a_legacy_id_change_to_an_id_another_record_holds_in_either_successor(): void
    {
        $codes = [];

        foreach ([
            fn () => Video::query()->findOrFail(1)->update(['id' => 2]),
            fn () => Video::query()->whereKey(3)->update(['id' => 2]),
            fn () => Video::query()->whereIn('id', [1, 2])->update(['id' => 50]),
        ] as $write) {
            try {
                $write();
                $codes[] = 'stored';
            } catch (UniqueConstraintViolationException $exception) {
                $codes[] = $exception->getCode();
            }
        }

        $this->assertSame(['23505', '23505', '23505'], $codes);
        $this->assertSame([['files', 1], ['links', 2], ['files', 3]], $this->holders([1, 2, 3, 50]));
        $this->assertSame([1, 2, 3], DB::table('videos_retained')->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function it_refuses_a_raw_expression_for_a_successor_attribute_before_it_chooses_a_branch(): void
    {
        $messages = [];

        foreach ([
            fn () => Video::query()->findOrFail(1)->update(['youtube_embed_url' => DB::raw("'https://youtu.be/raw'")]),
            fn () => Video::query()->findOrFail(3)->increment('views', 1, ['youtube_embed_url' => DB::raw("'https://youtu.be/raw'")]),
            fn () => Video::query()->whereKey(1)->update(['youtube_embed_url' => DB::raw("'https://youtu.be/raw'")]),
        ] as $write) {
            try {
                $write();
                $messages[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $messages[] = strstr($refusal->getMessage(), '. Why:', true);
            }
        }

        $this->assertSame(array_fill(0, 3, Video::class.'::$youtube_embed_url cannot take a raw expression'), $messages);
        $this->assertSame([['files', 1], ['files', 3]], $this->holders([1, 3]));
        $this->assertSame([5, 0], Video::query()->whereIn('id', [1, 3])->orderBy('id')->pluck('views')->all());
    }

    #[Test]
    public function it_stores_each_timestamp_of_a_branched_write_in_the_application_time_zone_whatever_the_session_time_zone(): void
    {
        DB::statement("set time zone 'America/Denver'");
        Date::setTestNow('2026-06-07 10:00:00');

        Video::query()->whereIn('id', [1, 2])->update(['title' => 'Featured']);
        Video::query()->findOrFail(2)->update(['title' => 'Oak tour']);
        Video::query()->findOrFail(3)->update(['youtube_embed_url' => 'https://youtu.be/drone']);
        $session = DB::scalar("select current_setting('TimeZone')");
        DB::statement("set time zone 'UTC'");

        $this->assertSame('America/Denver', $session);
        $this->assertSame(
            [[1, '2026-06-07 10:00:00'], [2, '2026-06-07 10:00:00'], [3, '2026-06-07 10:00:00']],
            Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->updated_at?->format('Y-m-d H:i:s')])->all(),
        );
    }

    private function titledVideo(): Model
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
                    ->servedFrom(fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null && $video->getAttribute('title') !== 'Upload only' ? Link::class : File::class)
                    ->as(File::class, fn (Successor $file) => $file
                        ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type'))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->fixed(null)))
                    ->as(Link::class, fn (Successor $link) => $link
                        ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url')));
            }
        };
    }

    private function viewedVideo(): Model
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
                    ->servedFrom(fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null && $video->getAttribute('views') > 1 ? Link::class : File::class)
                    ->as(File::class, fn (Successor $file) => $file
                        ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type'))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->fixed(null)))
                    ->as(Link::class, fn (Successor $link) => $link
                        ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url')));
            }
        };
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
