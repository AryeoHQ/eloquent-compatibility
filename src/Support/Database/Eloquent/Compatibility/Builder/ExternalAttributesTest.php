<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityQueryRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\TestCase;

#[CoversClass(ExternalAttributes::class)]
#[CoversClass(ServesCompatibility::class)]
#[CoversClass(LegacyBuilder::class)]
final class ExternalAttributesTest extends TestCase
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
        FakeMediaLibrary::bound()->calls = [];
    }

    protected function tearDown(): void
    {
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_calls_the_external_system_only_when_a_loaded_record_reads_the_attribute(): void
    {
        $library = FakeMediaLibrary::bound();

        $photos = Photo::query()->take(20)->get(); // @phpstan-ignore staticMethod.dynamicCall
        $loaded = count($library->calls);
        $first = $photos->first()?->mime_type;

        $this->assertSame([3, 0, 1], [$photos->count(), $loaded, count($library->calls)]);
        $this->assertSame([1 => 'image/jpeg', 2 => 'image/jpeg', 3 => 'image/png'][$photos->first()?->id], $first);
    }

    #[Test]
    public function it_resolves_a_whole_loaded_collection_with_one_successor_query_and_one_read_and_serves_each_value_clean(): void
    {
        $library = FakeMediaLibrary::bound();
        $photos = Photo::query()->orderBy('id')->get();

        $successorQueries = $this->successorQueries(fn () => $photos[1]->mime_type);
        $types = $photos->map(fn (Photo $photo) => [$photo->mime_type, $photo->isDirty('mime_type'), $photo->getOriginal('mime_type')])->all();

        $this->assertSame([['image/jpeg', false, 'image/jpeg'], ['image/jpeg', false, 'image/jpeg'], ['image/png', false, 'image/png']], $types);
        $this->assertSame([1, 1, 3], [$successorQueries, count($library->calls), count($library->calls[0][1])]);
    }

    #[Test]
    public function it_reads_one_record_through_a_collection_of_one(): void
    {
        $library = FakeMediaLibrary::bound();
        $asset = DB::table('files')->where('legacy_type', 'photo')->where('legacy_id', 3)->value('zml_asset_id');

        $this->assertSame('image/png', Photo::query()->findOrFail(3)->mime_type);
        $this->assertSame([['fetch', [$asset]]], $library->calls);
    }

    #[Test]
    public function it_resolves_the_attribute_when_a_record_is_serialized(): void
    {
        $photos = Photo::query()->orderBy('id')->get()->toArray();

        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png'], array_column($photos, 'mime_type'));
        $this->assertCount(1, FakeMediaLibrary::bound()->calls);
    }

    #[Test]
    public function it_resolves_each_video_through_the_read_of_its_external_branch_and_from_the_value_its_other_branch_fixes(): void
    {
        $videos = Video::query()->orderBy('id')->get();

        $linkQueries = $this->successorQueries(fn () => $videos[1]->mime_type, 'links');

        $this->assertSame([['video/mp4', 'tour.mp4', false], [null, null, false], ['video/quicktime', 'drone.mov', false]], $videos->map(fn (Video $video) => [$video->mime_type, $video->original_file_name, $video->isDirty()])->all());
        $this->assertSame([0, 1], [$linkQueries, count(FakeMediaLibrary::bound()->calls)]);
    }

    #[Test]
    public function it_serves_the_value_an_earlier_branch_fixes_and_refuses_a_filter_its_later_branch_keeps_externally(): void
    {
        $embeds = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'videos';

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
                    ->servedFrom(fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null ? Link::class : File::class)
                    ->as(Link::class, fn (Successor $link) => $link
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->fixed('text/html'))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url')))
                    ->as(File::class, fn (Successor $file) => $file
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->external(
                            read: fn (Collection $files): array => File::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType)->all(),
                            write: fn (File $file, null|string $mimeType) => $file->metadata()->mimeType = $mimeType))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->fixed(null)));
            }
        };

        $this->assertSame(['video/mp4', 'text/html', 'video/quicktime'], $embeds::query()->orderBy('id')->get()->map(fn (Model $video) => $video->getAttribute('mime_type'))->all());
        $this->assertCount(1, FakeMediaLibrary::bound()->calls);

        $this->expectException(CompatibilityQueryRefused::class);

        $embeds::query()->where('mime_type', 'text/html')->get();
    }

    #[Test]
    public function it_refuses_every_filter_sort_and_aggregate_that_would_read_the_attribute_in_sql(): void
    {
        $refusals = [];

        foreach ([
            fn () => Photo::query()->where('mime_type', 'image/jpeg')->get(),
            fn () => Photo::query()->whereIn('mime_type', ['image/jpeg'])->get(),
            fn () => Photo::query()->whereNotNull('mime_type')->get(),
            fn () => Photo::query()->orderBy('mime_type')->get(),
            fn () => Photo::query()->max('mime_type'),
            fn () => Video::query()->where('mime_type', 'video/mp4')->get(),
            fn () => Video::query()->orderByDesc('mime_type')->get(),
        ] as $query) {
            try {
                $query();
                $refusals[] = 'ran';
            } catch (CompatibilityQueryRefused $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $because = 'Why: it is stored outside the database, so no SQL reads it. Instead: filter or sort on the stored column through the successor model.';

        $this->assertSame([
            Photo::class.'::$mime_type cannot take where =. '.$because,
            Photo::class.'::$mime_type cannot take whereIn. '.$because,
            Photo::class.'::$mime_type cannot take whereNotNull. '.$because,
            Photo::class.'::$mime_type cannot take orderBy asc. '.$because,
            Photo::class.'::$mime_type cannot take max(). '.$because,
            Video::class.'::$mime_type cannot take where =. '.$because,
            Video::class.'::$mime_type cannot take orderBy desc. '.$because,
        ], $refusals);
        $this->assertSame([], FakeMediaLibrary::bound()->calls);
    }

    #[Test]
    public function it_reads_the_legacy_column_and_calls_nothing_before_install(): void
    {
        FileTables::create();
        FileTables::seed();
        $library = FakeMediaLibrary::bound();

        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png'], Photo::query()->where('mime_type', 'like', 'image/%')->orderBy('id')->get()->map(fn (Photo $photo) => $photo->mime_type)->all());
        $this->assertNull(Photo::query()->orderBy('id')->firstOrFail(['id', 'file_name'])->mime_type);
        $this->assertSame([], $library->calls);
    }

    #[Test]
    public function it_hands_the_read_the_successors_of_the_loaded_records_only(): void
    {
        $photos = Photo::query()->whereIn('id', [1, 3])->orderBy('id')->get();
        $assets = DB::table('files')->where('legacy_type', 'photo')->whereIn('legacy_id', [1, 3])->orderBy('legacy_id')->pluck('zml_asset_id')->all();

        $this->assertSame('image/png', $photos[1]->mime_type);
        $calls = FakeMediaLibrary::bound()->calls;
        sort($assets);
        sort($calls[0][1]);

        $this->assertSame([['fetch', $assets]], $calls);
    }

    private function successorQueries(Closure $read, string $table = 'files'): int
    {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries, $table): void {
            if (str_contains($query->sql, 'from "'.$table.'"')) {
                $queries++;
            }
        });

        $read();

        return $queries;
    }
}
