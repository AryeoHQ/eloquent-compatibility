<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;
use TypeError;

#[CoversClass(RecordWriter::class)]
#[CoversClass(MassWriter::class)]
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
    public function it_hands_a_new_record_value_to_the_successor_before_the_successor_saves(): void
    {
        $saved = [];
        File::saving(function (File $file) use (&$saved): void {
            $saved[] = $file->metadata()->mimeType;
        });

        $photo = Photo::query()->create(['file_name' => 'birch.jpg', 'mime_type' => 'image/webp', 'caption' => 'Birch']);
        $asset = (string) File::query()->whereKey(FileTables::fileOf(table: 'photos', id: $photo->id))->value('zml_asset_id');

        $this->assertSame(['image/webp'], $saved);
        $this->assertEquals(new FileMetadata('image/webp'), FakeMediaLibrary::bound()->stored($asset));
        $this->assertSame(['image/webp', false], [$photo->mime_type, $photo->isDirty()]);
        $this->assertSame('image/webp', Photo::query()->findOrFail($photo->id)->mime_type);
        $this->assertSame([], DB::select("select table_name from information_schema.columns where table_name in ('photos', 'photos_retained') and column_name = 'mime_type'"));
    }

    #[Test]
    public function it_hands_a_changed_value_to_the_successor_and_saves_it_even_when_nothing_else_changes(): void
    {
        $photo = Photo::query()->findOrFail(2);
        $photo->timestamps = false;
        $asset = (string) File::query()->whereKey(FileTables::fileOf(table: 'photos', id: 2))->value('zml_asset_id');

        $photo->update(['mime_type' => 'image/avif']);

        $this->assertEquals(new FileMetadata('image/avif'), FakeMediaLibrary::bound()->stored($asset));
        $this->assertSame(['image/avif', false], [$photo->mime_type, $photo->isDirty()]);
        $this->assertSame(['image/jpeg', 'image/avif', 'image/png'], Photo::query()->orderBy('id')->get()->map(fn (Photo $photo) => $photo->mime_type)->all());
    }

    #[Test]
    public function it_reads_and_writes_an_external_attribute_of_a_successor_related_reaches_through_that_successor(): void
    {
        ListingTables::create();
        ListingTables::seed();
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;
            use SoftDeletes;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->rootPlace(),
                            definition: fn (Successor $place) => $place
                                ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                                ->attribute('city', fn (Attribute $attribute) => $attribute->external(
                                    read: fn (Collection $places): array => $places->mapWithKeys(fn (Model $place) => [$place->getKey() => $place->getAttribute('city')])->all(),
                                    write: fn (Place $place, null|string $city) => $place->setAttribute('city', $city))),
                        ));
            }
        };

        try {
            Compatibility::prepare($listing::class);
            ListingTables::backfill();
            Compatibility::install(
                model: $listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $read = [];
            DB::listen(function (QueryExecuted $query) use (&$read): void {
                $read[] = $query->sql;
            });

            $cities = $listing::query()->orderBy('id')->get()->map(fn (Model $record) => $record->getAttribute('city'))->all();
            $places = count(array_filter($read, fn (string $sql) => str_contains($sql, '"places".*')));
            $listing::query()->findOrFail(3)->update(['city' => 'Tacoma']);

            $this->assertSame([['Seattle', null], 1], [$cities, $places]);
            $this->assertSame([['Seattle', 'Tacoma'], ['1 Oak St' => 'Seattle', '3 Elm St' => 'Tacoma']], [$listing::query()->orderBy('id')->get()->map(fn (Model $record) => $record->getAttribute('city'))->all(), DB::table('places')->orderBy('street')->pluck('city', 'street')->all()]);
        } finally {
            ListingTables::drop();
        }
    }

    #[Test]
    public function it_backfills_an_external_attribute_of_a_successor_related_reaches_through_that_successor_and_installs(): void
    {
        ListingTables::create();
        ListingTables::seed();
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;
            use SoftDeletes;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->rootPlace(),
                            definition: fn (Successor $place) => $place
                                ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                                ->attribute('city', fn (Attribute $attribute) => $attribute->external(
                                    read: fn (Collection $places): array => $places->mapWithKeys(fn (Model $place) => [$place->getKey() => $place->getAttribute('city')])->all(),
                                    write: fn (Place $place, null|string $city) => $place->setAttribute('city', $city))),
                        ));
            }
        };

        try {
            Compatibility::prepare($listing::class);
            $backfiller = new Backfiller(Compatibility::of($listing::class)->resolve(), DB::connection());
            $first = $backfiller->run();
            $second = $backfiller->run();
            Compatibility::install(
                model: $listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $places = DB::table('places')->orderBy('street')->get(['street', 'city'])->map(fn (object $place) => [$place->street, $place->city])->all();
            $cities = $listing::withTrashed()->orderBy('id')->get()->map(fn (Model $record) => [$record->getKey(), $record->getAttribute('city')])->all();
        } finally {
            ListingTables::drop();
        }

        $this->assertSame([[3, 6], [3, 0]], [[$first['read'], $first['changed']], [$second['read'], $second['changed']]]);
        $this->assertSame([['1 Oak St', 'Seattle'], ['3 Elm St', null]], $places);
        $this->assertSame([[1, 'Seattle'], [2, null], [3, null]], $cities);
    }

    #[Test]
    public function it_reads_and_installs_an_external_attribute_of_a_trashed_successor_a_related_relation_with_trashed_reaches(): void
    {
        ListingTables::create();
        ListingTables::seed();
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;
            use SoftDeletes;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->rootPlace()->withTrashed(),
                            definition: fn (Successor $place) => $place
                                ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                                ->attribute('city', fn (Attribute $attribute) => $attribute->external(
                                    read: fn (Collection $places): array => $places->mapWithKeys(fn (Model $place) => [$place->getKey() => $place->getAttribute('city')])->all(),
                                    write: fn (Place $place, null|string $city) => $place->setAttribute('city', $city))),
                        ));
            }
        };

        try {
            Compatibility::prepare($listing::class);
            (new Backfiller(Compatibility::of($listing::class)->resolve(), DB::connection()))->run();
            DB::table('places')->where('street', '1 Oak St')->update(['deleted_at' => '2026-03-01 10:00:00']);
            Compatibility::install(
                model: $listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $served = $listing::withTrashed()->orderBy('id')->get()->map(fn (Model $record) => [$record->getKey(), $record->getAttribute('street'), $record->getAttribute('city')])->all();
        } finally {
            ListingTables::drop();
        }

        $this->assertSame([1, '1 Oak St', 'Seattle'], $served[0]);
    }

    #[Test]
    public function it_serves_the_value_the_external_system_keeps_after_a_write_it_normalizes(): void
    {
        $normalizing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'photos';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return $photo
                    ->retainedIn('photos_retained')
                    ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'file_name',
                            definition: fn (Attribute $attribute) => $attribute->from('filename'),
                        )
                        ->attribute(
                            name: 'mime_type',
                            definition: fn (Attribute $attribute) => $attribute->external(
                                read: fn (Collection $files): array => File::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType)->all(),
                                write: fn (File $file, null|string $mimeType) => $file->metadata()->mimeType = $mimeType === null ? null : strtolower($mimeType)),
                        )
                        ->attribute(
                            name: 'size_kb',
                            definition: fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000),
                        ));
            }
        };
        $seen = [];
        $normalizing::saved(function (Model $photo) use (&$seen): void {
            $seen[] = $photo->getAttribute('mime_type');
        });

        $created = $normalizing::query()->create(['file_name' => 'birch.jpg', 'mime_type' => 'IMAGE/WEBP', 'caption' => 'Birch']);
        $updated = $normalizing::query()->findOrFail(2);
        $updated->update(['mime_type' => 'IMAGE/AVIF']);

        $this->assertSame(['image/webp', 'image/avif'], $seen);
        $this->assertSame(['image/webp', 'image/avif'], [$created->getAttribute('mime_type'), $updated->getAttribute('mime_type')]);
        $this->assertSame(['image/webp', 'image/avif'], [$created->getOriginal('mime_type'), $updated->getOriginal('mime_type')]);
    }

    #[Test]
    public function it_refuses_a_write_the_map_declines_with_the_declared_reason_and_changes_nothing(): void
    {
        $declined = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'photos';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return $photo
                    ->retainedIn('photos_retained')
                    ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'file_name',
                            definition: fn (Attribute $attribute) => $attribute->from('filename'),
                        )
                        ->attribute(
                            name: 'mime_type',
                            definition: fn (Attribute $attribute) => $attribute->external(
                                read: fn (Collection $files): array => File::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType)->all(),
                                write: Attribute::declined(because: 'The media library sets the type from the upload.')),
                        )
                        ->attribute(
                            name: 'size_kb',
                            definition: fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000),
                        ));
            }
        };
        $before = DB::select('select * from files order by id');
        $photo = $declined::query()->findOrFail(1);

        try {
            $photo->update(['file_name' => 'oak-front-2.jpg', 'mime_type' => 'image/png']);
            $this->fail('The declined write was stored.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertSame(['The media library sets the type from the upload.', true], [$refusal->because, str_ends_with($refusal->refused, '::$mime_type cannot take a write')]);
        }

        $this->assertEquals($before, DB::select('select * from files order by id'));
        $this->assertSame([], array_filter(FakeMediaLibrary::bound()->calls, fn (array $call) => $call[0] !== 'fetch'));

        $declined::query()->findOrFail(1)->update(['file_name' => 'oak-front-2.jpg', 'mime_type' => 'image/jpeg']);

        $this->assertSame(['oak-front-2.jpg', 'image/jpeg'], [Photo::query()->findOrFail(1)->file_name, Photo::query()->findOrFail(1)->mime_type]);
    }

    #[Test]
    public function it_writes_no_unchanged_value_it_never_read_and_shows_listeners_the_value_it_replaces(): void
    {
        $seen = [];
        Photo::updating(function (Photo $photo) use (&$seen): void {
            $seen[] = [$photo->getOriginal('mime_type'), $photo->isDirty('mime_type')];
        });
        $assets = DB::table('files')->join(table: 'photos_retained', first: 'photos_retained.file_id', operator: '=', second: 'files.id')->orderBy('photos_retained.id')->pluck('files.zml_asset_id')->all();

        Photo::query()->findOrFail(1)->update(['file_name' => 'oak-front-2.jpg', 'mime_type' => 'image/jpeg']);
        Photo::query()->findOrFail(2)->update(['mime_type' => 'image/avif']);

        $this->assertSame([['image/jpeg', false], ['image/jpeg', true]], $seen);
        $this->assertSame([['put', [$assets[1]]]], array_values(array_filter(FakeMediaLibrary::bound()->calls, fn (array $call) => $call[0] !== 'fetch')));
    }

    #[Test]
    public function it_copies_an_unread_value_into_a_replica_and_writes_it_through_the_new_successor(): void
    {
        $copy = Photo::query()->findOrFail(3)->replicate(['uuid', 'file_id']);
        $copy->save();

        $this->assertSame(['image/png', 'image/png'], [$copy->mime_type, Photo::query()->findOrFail($copy->id)->mime_type]);
    }

    #[Test]
    public function it_keeps_an_unread_value_on_a_deleted_record_for_its_listeners_and_its_serialization(): void
    {
        $seen = [];
        Photo::deleted(function (Photo $photo) use (&$seen): void {
            $seen[] = $photo->mime_type;
        });
        $photo = Photo::query()->findOrFail(3);

        $photo->delete();

        $this->assertSame([['image/png'], 'image/png', false], [$seen, $photo->toArray()['mime_type'] ?? 'missing', $photo->exists]);
    }

    #[Test]
    public function it_refuses_a_mass_write_of_an_external_attribute_and_changes_nothing(): void
    {
        $before = DB::select('select * from files order by id');
        $refusals = [];

        foreach ([
            fn () => Photo::query()->whereKey(1)->update(['mime_type' => 'image/png']),
            fn () => Photo::query()->toBase()->where('id', 1)->update(['file_name' => 'oak.jpg', 'mime_type' => 'image/png']),
            fn () => Video::query()->whereIn('id', [1, 2])->update(['mime_type' => 'video/webm']),
            fn () => Video::query()->whereKey(2)->update(['mime_type' => null]),
        ] as $write) {
            try {
                $write();
                $refusals[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $this->assertSame([
            Photo::class.'::$mime_type cannot take a mass write. Why: It is declared external(), so its value lives outside the database, and no set-based statement can reach an external system. Instead: Write the records one at a time through Photo.',
            Photo::class.'::$mime_type cannot take a mass write. Why: It is declared external(), so its value lives outside the database, and no set-based statement can reach an external system. Instead: Write the records one at a time through Photo.',
            Video::class.'::$mime_type cannot take a mass write. Why: It is declared external(), so its value lives outside the database, and no set-based statement can reach an external system. Instead: Write the records one at a time through Video.',
            Video::class.'::$mime_type cannot take a mass write. Why: It is declared external(), so its value lives outside the database, and no set-based statement can reach an external system. Instead: Write the records one at a time through Video.',
        ], $refusals);
        $this->assertEquals($before, DB::select('select * from files order by id'));
        $this->assertSame([], FakeMediaLibrary::bound()->calls);
    }

    #[Test]
    public function it_refuses_a_raw_expression_for_an_external_attribute(): void
    {
        $this->expectExceptionObject(CompatibilityWriteRefused::rawExpression(Photo::class, 'mime_type', 'It is declared external(), so its value lives outside the database, and no SQL computes it.'));

        Photo::query()->findOrFail(1)->update(['mime_type' => new Expression("'image/png'")]);
    }

    #[Test]
    public function it_serves_the_value_a_write_stores_to_a_record_whose_legacy_id_the_same_write_changes(): void
    {
        $seen = [];
        Video::updated(function (Video $video) use (&$seen): void {
            $seen[] = $video->mime_type;
        });
        Photo::updated(function (Photo $photo) use (&$seen): void {
            $seen[] = $photo->mime_type;
        });
        $updated = Video::query()->findOrFail(1);
        $incremented = Video::query()->findOrFail(3);
        $photo = Photo::query()->findOrFail(2);

        $updated->update(['id' => 10, 'mime_type' => 'video/webm']);
        $incremented->increment('views', 1, ['id' => 30, 'mime_type' => 'video/x-msvideo']);
        $photo->update(['id' => 20, 'mime_type' => 'image/avif']);

        $this->assertSame(['video/webm', 'video/x-msvideo', 'image/avif'], $seen);
        $this->assertSame(
            [[10, 'video/webm'], [30, 'video/x-msvideo'], [20, 'image/avif']],
            array_map(fn (Model $model) => [$model->getKey(), $model->getAttribute('mime_type')], [$updated, $incremented, $photo]),
        );
        $this->assertSame(['video/webm', 'image/avif'], [$updated->getOriginal('mime_type'), $photo->getOriginal('mime_type')]);
        $this->assertSame(
            [[2, null], [10, 'video/webm'], [30, 'video/x-msvideo']],
            Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->mime_type])->all(),
        );
        $this->assertSame('image/avif', Photo::query()->findOrFail(20)->mime_type);
    }

    #[Test]
    public function it_reads_a_value_left_unread_by_the_legacy_id_the_write_gives_its_record(): void
    {
        $seen = [];
        Video::updated(function (Video $video) use (&$seen): void {
            $seen[] = $video->mime_type;
        });
        Photo::updated(function (Photo $photo) use (&$seen): void {
            $seen[] = $photo->mime_type;
        });
        $photo = Photo::query()->findOrFail(2);
        $updated = Video::query()->findOrFail(3);
        $incremented = Video::query()->findOrFail(1);

        $photo->update(['id' => 20]);
        $updated->update(['id' => 30]);
        $incremented->increment('views', 1, ['id' => 10]);

        $this->assertSame(['image/jpeg', 'video/quicktime', 'video/mp4'], $seen);
        $this->assertSame(
            [[20, 'image/jpeg', 'image/jpeg'], [30, 'video/quicktime', 'video/quicktime'], [10, 'video/mp4', 'video/mp4']],
            array_map(fn (Model $model) => [$model->getKey(), $model->getAttribute('mime_type'), $model->getOriginal('mime_type')], [$photo, $updated, $incremented]),
        );
        $this->assertSame([false, false, false], [$photo->wasChanged('mime_type'), $updated->wasChanged('mime_type'), $incremented->wasChanged('mime_type')]);
    }

    #[Test]
    public function it_reads_a_value_the_successor_generates_for_a_new_record_from_the_write_connection(): void
    {
        $generating = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'photos';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return $photo
                    ->retainedIn('photos_retained')
                    ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'file_name',
                            definition: fn (Attribute $attribute) => $attribute->from('filename'),
                        )
                        ->attribute(
                            name: 'mime_type',
                            definition: fn (Attribute $attribute) => $attribute->external(
                                read: fn (Collection $files): array => $files->pluck('mime_type', 'id')->all(),
                                write: fn (File $file, null|string $mimeType) => $file->mime_type = (string) $mimeType),
                        )
                        ->attribute(
                            name: 'size_kb',
                            definition: fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000),
                        ));
            }
        };
        File::creating(function (File $file): void {
            $file->mime_type ??= 'image/'.pathinfo($file->filename, PATHINFO_EXTENSION);
        });
        $seen = [];
        $generating::saved(function (Model $photo) use (&$seen): void {
            if ($photo->getAttribute('file_name') === 'listened.png') {
                $seen[] = $photo->getAttribute('mime_type');
            }
        });
        DB::statement('create schema compatibility_replica');
        DB::statement('create table compatibility_replica.files as table public.files');
        DB::statement('create view compatibility_replica.photos as select * from public.photos');
        config(['database.connections.compatibility_replicated' => [...(array) config('database.connections.pgsql'), 'read' => ['search_path' => 'compatibility_replica'], 'write' => [], 'sticky' => false]]);

        try {
            $listened = $generating::on('compatibility_replicated')->create(['file_name' => 'listened.png', 'caption' => 'Listened']);
            $read = $generating::on('compatibility_replicated')->create(['file_name' => 'read.webp', 'caption' => 'Read']);
            $named = $generating::on('compatibility_replicated')->create(['file_name' => 'named.jpg', 'mime_type' => 'image/avif', 'caption' => 'Named']);

            $values = [$listened->getAttribute('mime_type'), $read->getAttribute('mime_type'), $named->getAttribute('mime_type')];
            $replica = $generating::on('compatibility_replicated')->whereKey([$listened->getKey(), $read->getKey()])->orderBy('id')->get()->map(fn (Model $photo) => $photo->getAttribute('mime_type'))->all();
        } finally {
            DB::purge('compatibility_replicated');
            DB::statement('drop schema compatibility_replica cascade');
        }

        $this->assertSame(['image/png'], $seen);
        $this->assertSame(['image/png', 'image/webp', 'image/avif'], $values);
        $this->assertSame([null, null], $replica);
    }

    #[Test]
    public function it_hands_the_external_write_the_successor_fields_the_same_write_changes_and_keeps_what_the_write_sets(): void
    {
        $named = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'photos';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return $photo
                    ->retainedIn('photos_retained')
                    ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'file_name',
                            definition: fn (Attribute $attribute) => $attribute->from('filename'),
                        )
                        ->attribute(
                            name: 'mime_type',
                            definition: fn (Attribute $attribute) => $attribute->external(
                                read: fn (Collection $files): array => $files->pluck('filename', 'id')->map(fn (mixed $asset) => is_string($asset) ? FakeMediaLibrary::bound()->stored($asset)?->mimeType : null)->all(),
                                write: function (File $file, null|string $mimeType): void {
                                    $file->filename = strtolower($file->filename);
                                    FakeMediaLibrary::bound()->put($file->filename, new FileMetadata($mimeType));
                                }),
                        )
                        ->attribute(
                            name: 'size_kb',
                            definition: fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000),
                        ));
            }
        };
        $library = FakeMediaLibrary::bound();
        $library->put('oak-back.jpg', new FileMetadata('image/jpeg'));
        $library->put('elm.png', new FileMetadata('image/png'));
        $updated = $named::query()->findOrFail(2);
        $incremented = $named::query()->findOrFail(3);

        $updated->update(['file_name' => 'Birch.JPG', 'mime_type' => 'image/avif']);
        $incremented->increment('views', 1, ['file_name' => 'Ash.PNG', 'mime_type' => 'image/webp']);

        $this->assertEquals(
            [new FileMetadata('image/avif'), new FileMetadata('image/webp'), new FileMetadata('image/jpeg'), new FileMetadata('image/png')],
            [$library->stored('birch.jpg'), $library->stored('ash.png'), $library->stored('oak-back.jpg'), $library->stored('elm.png')],
        );
        $this->assertSame(['birch.jpg', 'ash.png'], DB::table('files')->join(table: 'photos_retained', first: 'photos_retained.file_id', operator: '=', second: 'files.id')->whereIn(column: 'photos_retained.id', values: [2, 3])->orderBy('photos_retained.id')->pluck('files.filename')->all());
        $this->assertSame([['birch.jpg', 'image/avif'], ['ash.png', 'image/webp', 1]], [[$updated->getAttribute('file_name'), $updated->getAttribute('mime_type')], [$incremented->getAttribute('file_name'), $incremented->getAttribute('mime_type'), $incremented->getAttribute('views')]]);
    }

    #[Test]
    public function it_refuses_a_query_or_a_relation_for_an_external_attribute_and_changes_nothing(): void
    {
        $before = DB::select('select * from files order by id');
        $source = (string) FileTables::fileOf(table: 'photos', id: 3);
        $refusals = [];

        foreach ([
            fn () => Photo::query()->findOrFail(1)->update(['mime_type' => File::query()->select('mime_type')->whereKey($source)]),
            fn () => Photo::query()->findOrFail(1)->update(['file_name' => 'oak.jpg', 'mime_type' => File::query()->select('mime_type')->whereKey($source)->toBase()]),
            fn () => Photo::query()->findOrFail(1)->update(['mime_type' => (new File)->hasOne(File::class, 'id', 'id')]),
            fn () => Photo::query()->findOrFail(1)->increment('views', 1, ['mime_type' => File::query()->select('mime_type')->whereKey($source)]),
            fn () => Photo::query()->create(['file_name' => 'ash.jpg', 'mime_type' => File::query()->select('mime_type')->whereKey($source)]),
            fn () => Video::query()->findOrFail(1)->update(['mime_type' => File::query()->select('mime_type')->whereKey($source)]),
        ] as $write) {
            try {
                $write();
                $refusals[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $refusals[] = $refusal->getMessage();
            } catch (TypeError $error) {
                $refusals[] = 'TypeError: '.$error->getMessage();
            }
        }

        $refusal = fn (string $model) => CompatibilityWriteRefused::rawExpression($model, 'mime_type', 'It is declared external(), so its value lives outside the database, and no SQL computes it.')->getMessage();

        $this->assertSame([$refusal(Photo::class), $refusal(Photo::class), $refusal(Photo::class), $refusal(Photo::class), $refusal(Photo::class), $refusal(Video::class)], $refusals);
        $this->assertEquals($before, DB::select('select * from files order by id'));
        $this->assertSame([], array_values(array_filter(FakeMediaLibrary::bound()->calls, fn (array $call) => $call[0] !== 'fetch')));
    }

    #[Test]
    public function it_shows_the_listeners_of_a_soft_delete_the_value_the_successor_held_before_its_delete(): void
    {
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;
            use SoftDeletes;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'deleted_at',
                            definition: fn (Attribute $attribute) => $attribute->from('deleted_at'),
                        )
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->attribute(
                            name: 'mls_number',
                            definition: fn (Attribute $attribute) => $attribute->external(
                                read: fn (Collection $jobs): array => $jobs->pluck('id', 'id')->map(fn (mixed $job) => is_string($job) ? FakeMediaLibrary::bound()->stored('mls-'.$job)?->mimeType : null)->all(),
                                write: fn (Job $job, null|string $number) => FakeMediaLibrary::bound()->put('mls-'.$job->getKey(), new FileMetadata($number))),
                        ));
            }
        };

        try {
            ListingTables::create();
            ListingTables::seed();
            Compatibility::prepare($listing::class);
            ListingTables::backfill();

            foreach (DB::table('jobs')->join(table: 'listings', first: 'listings.job_id', operator: '=', second: 'jobs.id')->get(['jobs.id', 'listings.mls_number']) as $row) {
                FakeMediaLibrary::bound()->put('mls-'.$row->id, new FileMetadata(is_string($row->mls_number) ? $row->mls_number : null));
            }

            Compatibility::install(
                model: $listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $job = (string) DB::table('jobs')->where(
                column: 'id',
                operator: '=',
                value: ListingTables::jobOf(1),
            )->value('id');
            Job::deleting(function (Job $deleted): void {
                FakeMediaLibrary::bound()->put('mls-'.$deleted->getKey(), new FileMetadata(null));
            });
            $seen = [];
            $listing::deleted(function (Model $deleted) use (&$seen): void {
                $seen[] = $deleted->getAttribute('mls_number');
            });
            $record = $listing->newQuery()->findOrFail(1);

            $record->delete();

            $this->assertSame([['MLS-1'], 'MLS-1', null], [$seen, $record->getAttribute('mls_number'), FakeMediaLibrary::bound()->stored('mls-'.$job)?->mimeType]);
            $this->assertNotNull(DB::table('jobs')->where('id', $job)->value('deleted_at'));
        } finally {
            ListingTables::drop();
        }
    }
}
