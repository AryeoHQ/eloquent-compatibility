<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Closure;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
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
#[CoversClass(LegacyBuilder::class)]
#[CoversTrait(ServesCompatibility::class)]
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
        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png'], Photo::query()->orderBy('id')->pluck('mime_type')->all());
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

    #[Test]
    public function it_refuses_every_select_group_having_and_count_of_the_attribute_before_its_sql_runs_even_after_to_base(): void
    {
        $refusals = [];

        foreach ([
            fn () => Photo::query()->select('mime_type')->get(), // @phpstan-ignore staticMethod.dynamicCall
            fn () => Photo::query()->addSelect('photos.mime_type as type')->get(), // @phpstan-ignore staticMethod.dynamicCall
            fn () => Photo::query()->get(['id', 'mime_type']),
            fn () => Photo::query()->pluck('mime_type'),
            fn () => Photo::query()->value('mime_type'),
            fn () => Photo::query()->select('id')->groupBy('mime_type')->get(), // @phpstan-ignore staticMethod.dynamicCall, staticMethod.dynamicCall
            fn () => Photo::query()->select('id')->groupBy('id')->having('mime_type', '=', 'image/png')->get(), // @phpstan-ignore staticMethod.dynamicCall, staticMethod.dynamicCall, staticMethod.dynamicCall
            fn () => Photo::query()->count('mime_type'), // @phpstan-ignore staticMethod.dynamicCall
            fn () => Photo::query()->select('mime_type')->exists(), // @phpstan-ignore staticMethod.dynamicCall, staticMethod.dynamicCall
            fn () => Photo::query()->toBase()->where('mime_type', 'image/jpeg')->get(),
            fn () => Photo::query()->toBase()->where(fn (QueryBuilder $query) => $query->whereNull('mime_type'))->count(),
            fn () => Photo::query()->toBase()->orderByDesc('mime_type')->get(),
            fn () => Photo::query()->toBase()->max('mime_type'),
            fn () => Video::query()->pluck('mime_type', 'id'),
        ] as $query) {
            try {
                $query();
                $refusals[] = 'ran';
            } catch (CompatibilityQueryRefused $refusal) {
                $refusals[] = $refusal->getMessage();
            } catch (QueryException $exception) {
                $refusals[] = 'reached SQL: '.$exception->getPrevious()?->getMessage();
            }
        }

        $because = 'Why: it is stored outside the database, so no SQL reads it. Instead: filter or sort on the stored column through the successor model.';

        $this->assertSame([
            Photo::class.'::$mime_type cannot take select. '.$because,
            Photo::class.'::$mime_type cannot take select. '.$because,
            Photo::class.'::$mime_type cannot take select. '.$because,
            Photo::class.'::$mime_type cannot take select. '.$because,
            Photo::class.'::$mime_type cannot take select. '.$because,
            Photo::class.'::$mime_type cannot take groupBy. '.$because,
            Photo::class.'::$mime_type cannot take having. '.$because,
            Photo::class.'::$mime_type cannot take count(). '.$because,
            Photo::class.'::$mime_type cannot take select. '.$because,
            Photo::class.'::$mime_type cannot take where. '.$because,
            Photo::class.'::$mime_type cannot take where. '.$because,
            Photo::class.'::$mime_type cannot take orderBy desc. '.$because,
            Photo::class.'::$mime_type cannot take max(). '.$because,
            Video::class.'::$mime_type cannot take select. '.$because,
        ], $refusals);
        $this->assertSame([], FakeMediaLibrary::bound()->calls);
    }

    #[Test]
    public function it_refuses_a_read_that_returns_no_value_for_a_successor_it_receives_or_a_value_for_one_it_does_not(): void
    {
        $photos = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            /** @var null|Closure(Collection<array-key, File>): array<array-key, mixed> */
            public static null|Closure $read = null;

            protected $table = 'photos';

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return $photo
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('photos_retained')
                    ->servedFrom(File::class, fn (Successor $file) => $file
                        ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->external(
                            read: fn (Collection $files): array => (self::$read ?? throw new LogicException('The test sets no read.'))($files),
                            write: fn (File $file, null|string $mimeType) => null)));
            }
        };
        $third = (string) DB::table('files')->where('legacy_type', 'photo')->where('legacy_id', 3)->value('id');
        $mimeTypes = fn (): array => $photos::query()->orderBy('id')->get()->map(fn (Model $photo) => $photo->getAttribute('mime_type'))->all();
        $failures = [];

        foreach ([
            fn (Collection $files): array => array_fill_keys($files->where('legacy_id', '!=', 3)->modelKeys(), 'image/jpeg'),
            fn (Collection $files): array => [...array_fill_keys($files->modelKeys(), 'image/jpeg'), 'zml-unknown' => 'image/png'],
        ] as $read) {
            $photos::$read = $read;

            try {
                $mimeTypes();
                $failures[] = 'read';
            } catch (LogicException $failure) {
                $failures[] = $failure->getMessage();
            }
        }

        $photos::$read = fn (Collection $files): array => [...array_fill_keys($files->modelKeys(), 'image/jpeg'), ...array_fill_keys($files->where('legacy_id', 3)->modelKeys(), null)];

        $this->assertSame([
            "The external() read closure of attribute 'mime_type' on photos returns no value for successor key {$third}; it returns one value, or null, for each successor it receives.",
            "The external() read closure of attribute 'mime_type' on photos returns a value for successor key zml-unknown, which it did not receive; it returns one value, or null, for each successor it receives.",
        ], $failures);
        $this->assertSame(['image/jpeg', 'image/jpeg', null], $mimeTypes());
    }

    #[Test]
    public function it_resolves_a_record_read_from_the_write_connection_through_the_write_connection(): void
    {
        DB::statement('create schema compatibility_replica');
        DB::statement('create table compatibility_replica.files (like public.files)');
        DB::statement('create view compatibility_replica.photos as select * from public.photos');
        config(['database.connections.compatibility_replicated' => [...(array) config('database.connections.pgsql'), 'read' => ['search_path' => 'compatibility_replica'], 'write' => [], 'sticky' => false]]);
        $replicated = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $connection = 'compatibility_replicated';

            protected $table = 'photos';

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return Photo::compatibility($photo);
            }
        };

        try {
            $written = $replicated::query()->useWritePdo()->findOrFail(1)->getAttribute('mime_type'); // @phpstan-ignore staticMethod.dynamicCall
            $loaded = $replicated::query()->orderBy('id')->findMany([2, 3]);
            $loaded[1]->refresh();
            $replica = $loaded[0]->getAttribute('mime_type');
            $refreshed = $loaded[1]->getAttribute('mime_type');
        } finally {
            DB::purge('compatibility_replicated');
            DB::statement('drop schema compatibility_replica cascade');
        }

        $this->assertSame(['image/jpeg', 'image/png', null], [$written, $refreshed, $replica]);
    }

    #[Test]
    public function it_resolves_a_collection_larger_than_the_bind_parameter_limit_with_one_successor_query(): void
    {
        DB::statement("insert into files (legacy_type, legacy_id, legacy_uuid, filename, mime_type) select 'photo', nextval('compatibility_legacy_id_seq'), gen_random_uuid(), 'bulk-' || n || '.jpg', 'image/jpeg' from generate_series(1, 65536) as n");
        $photos = Photo::query()->get(['id']);

        $successorQueries = $this->successorQueries(fn () => $photos[0]->mime_type);

        $this->assertSame([65539, 1, 3], [$photos->count(), $successorQueries, $photos->filter(fn (Photo $photo) => $photo->mime_type !== null)->count()]);
    }

    #[Test]
    public function it_keeps_the_mutable_cast_object_of_each_loaded_record_when_another_record_resolves_the_attribute(): void
    {
        Photo::query()->findOrFail(1)->update(['caption' => '{"a":1}']);
        $cast = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'photos';

            protected $guarded = [];

            protected $casts = ['caption' => AsArrayObject::class];

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return Photo::compatibility($photo);
            }
        };
        $photos = $cast::query()->orderBy('id')->get();

        $caption = $photos[0]->getAttribute('caption');
        $photos[1]->getAttribute('mime_type');
        $caption['b'] = 2;
        $photos[0]->save();

        $this->assertSame('{"a":1,"b":2}', DB::table('photos')->where('id', 1)->value('caption'));
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
