<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\TestCase;

#[CoversClass(RecordWriter::class)]
#[CoversClass(MassWriter::class)]
#[CoversClass(BranchMove::class)]
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
        $asset = (string) File::query()->where('legacy_type', 'photo')->where('legacy_id', $photo->id)->value('zml_asset_id');

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
        $asset = (string) File::query()->where('legacy_type', 'photo')->where('legacy_id', 2)->value('zml_asset_id');

        $photo->update(['mime_type' => 'image/avif']);

        $this->assertEquals(new FileMetadata('image/avif'), FakeMediaLibrary::bound()->stored($asset));
        $this->assertSame(['image/avif', false], [$photo->mime_type, $photo->isDirty()]);
        $this->assertSame(['image/jpeg', 'image/avif', 'image/png'], Photo::query()->orderBy('id')->get()->map(fn (Photo $photo) => $photo->mime_type)->all());
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
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('photos_retained')
                    ->timestamps()
                    ->servedFrom(File::class, fn (Successor $file) => $file
                        ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->external(
                            read: fn (Collection $files): array => File::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType)->all(),
                            write: fn (File $file, null|string $mimeType) => $file->metadata()->mimeType = $mimeType === null ? null : strtolower($mimeType)))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000)));
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
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('photos_retained')
                    ->timestamps()
                    ->servedFrom(File::class, fn (Successor $file) => $file
                        ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->external(
                            read: fn (Collection $files): array => File::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType)->all(),
                            write: Attribute::declined(because: 'The media library sets the type from the upload.')))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000)));
            }
        };
        $before = DB::select('select * from files order by legacy_type, legacy_id');
        $photo = $declined::query()->findOrFail(1);

        try {
            $photo->update(['file_name' => 'oak-front-2.jpg', 'mime_type' => 'image/png']);
            $this->fail('The declined write was stored.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertSame(['The media library sets the type from the upload.', true], [$refusal->because, str_ends_with($refusal->refused, '::$mime_type cannot take a write')]);
        }

        $this->assertEquals($before, DB::select('select * from files order by legacy_type, legacy_id'));
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
        $assets = DB::table('files')->where('legacy_type', 'photo')->orderBy('legacy_id')->pluck('zml_asset_id')->all();

        Photo::query()->findOrFail(1)->update(['file_name' => 'oak-front-2.jpg', 'mime_type' => 'image/jpeg']);
        Photo::query()->findOrFail(2)->update(['mime_type' => 'image/avif']);

        $this->assertSame([['image/jpeg', false], ['image/jpeg', true]], $seen);
        $this->assertSame([['put', [$assets[1]]]], array_values(array_filter(FakeMediaLibrary::bound()->calls, fn (array $call) => $call[0] !== 'fetch')));
    }

    #[Test]
    public function it_copies_an_unread_value_into_a_replica_and_writes_it_through_the_new_successor(): void
    {
        $copy = Photo::query()->findOrFail(3)->replicate(['uuid']);
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
        $before = DB::select('select * from files order by legacy_type, legacy_id');
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
        $this->assertEquals($before, DB::select('select * from files order by legacy_type, legacy_id'));
        $this->assertSame([], FakeMediaLibrary::bound()->calls);
    }

    #[Test]
    public function it_refuses_a_raw_expression_for_an_external_attribute(): void
    {
        $this->expectExceptionObject(CompatibilityWriteRefused::rawExpression(Photo::class, 'mime_type', 'It is declared external(), so its value lives outside the database, and no SQL computes it.'));

        Photo::query()->findOrFail(1)->update(['mime_type' => new Expression("'image/png'")]);
    }

    #[Test]
    public function it_writes_a_video_value_in_the_branch_that_keeps_it_externally_and_refuses_one_the_other_branch_fixes(): void
    {
        $upload = Video::query()->create(['original_file_name' => 'garden.mp4', 'mime_type' => 'video/mp4', 'title' => 'Garden']);
        Video::query()->findOrFail(3)->update(['mime_type' => 'video/x-msvideo']);
        $embed = Video::query()->findOrFail(2);
        $embed->update(['mime_type' => null, 'title' => 'Oak tour']);

        try {
            Video::query()->findOrFail(2)->update(['mime_type' => 'video/mp4']);
            $this->fail('The embed stored a mime type.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertSame(Video::class.'::$mime_type cannot take a write. Why: The attribute always reads NULL, so it cannot store \'video/mp4\'. Instead: Write a value its conversions can store, or write the stored column through the successor model.', $refusal->getMessage());
        }

        $this->assertSame(['Oak tour', null], [$embed->title, $embed->mime_type]);
        $this->assertSame(
            [[1, 'video/mp4'], [2, null], [3, 'video/x-msvideo'], [$upload->id, 'video/mp4']],
            Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->mime_type])->all(),
        );
    }

    #[Test]
    public function it_carries_a_value_the_write_names_into_the_successor_a_move_creates(): void
    {
        Video::query()->findOrFail(2)->update(['youtube_embed_url' => null, 'original_file_name' => 'oak.mp4', 'mime_type' => 'video/mp4']);
        Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour']);
        $asset = (string) File::query()->where('legacy_type', 'video')->where('legacy_id', 2)->value('zml_asset_id');

        $this->assertEquals(new FileMetadata('video/mp4'), FakeMediaLibrary::bound()->stored($asset));
        $this->assertSame([[1, null, 'https://youtu.be/tour'], [2, 'video/mp4', null], [3, 'video/quicktime', null]], Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->mime_type, $video->youtube_embed_url])->all());
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
    public function it_serves_the_value_the_new_branch_holds_to_a_record_that_read_the_attribute_before_its_move(): void
    {
        $seen = [];
        Video::updated(function (Video $video) use (&$seen): void {
            $seen[] = $video->mime_type;
        });
        $video = Video::query()->findOrFail(1);
        $read = $video->mime_type;

        $video->update(['youtube_embed_url' => 'https://youtu.be/tour']);

        $this->assertSame(
            ['video/mp4', [null], null, ['mime_type' => null], false, true],
            [$read, $seen, $video->mime_type, array_intersect_key($video->toArray(), ['mime_type' => true]), $video->isDirty(), $video->wasChanged('mime_type')],
        );
    }

    #[Test]
    public function it_reads_a_value_left_unread_through_a_move_from_the_write_connection(): void
    {
        DB::statement('create schema compatibility_replica');
        DB::statement('create table compatibility_replica.files as table public.files');
        DB::statement('create table compatibility_replica.links as table public.links');
        DB::statement('create view compatibility_replica.photos as select * from public.photos');
        DB::statement('create view compatibility_replica.videos as select * from public.videos');
        config(['database.connections.compatibility_replicated' => [...(array) config('database.connections.pgsql'), 'read' => ['search_path' => 'compatibility_replica'], 'write' => [], 'sticky' => false]]);

        try {
            $videos = Video::on('compatibility_replicated')->whereKey([1, 3])->orderBy('id')->get();

            $videos[0]->update(['youtube_embed_url' => 'https://youtu.be/tour']);

            $read = [$videos[1]->mime_type, $videos[0]->mime_type];
            $replica = Video::on('compatibility_replicated')->whereKey([1, 3])->orderBy('id')->get()->map(fn (Video $video) => $video->mime_type)->all();
        } finally {
            DB::purge('compatibility_replicated');
            DB::statement('drop schema compatibility_replica cascade');
        }

        $this->assertSame(['video/quicktime', null], $read);
        $this->assertSame(['video/mp4', 'video/quicktime'], $replica);
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
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('photos_retained')
                    ->timestamps()
                    ->servedFrom(File::class, fn (Successor $file) => $file
                        ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->external(
                            read: fn (Collection $files): array => $files->pluck('mime_type', 'id')->all(),
                            write: fn (File $file, null|string $mimeType) => $file->mime_type = (string) $mimeType))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000)));
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
    public function it_serves_the_value_a_move_stores_to_a_record_whose_new_legacy_id_the_database_normalizes(): void
    {
        $seen = [];
        Video::updated(function (Video $video) use (&$seen): void {
            $seen[] = $video->mime_type;
        });
        $video = Video::query()->findOrFail(2);

        $video->update(['id' => '010', 'youtube_embed_url' => null, 'original_file_name' => 'oak.mp4', 'mime_type' => 'video/webm']);

        $this->assertSame(['video/webm'], $seen);
        $this->assertSame([10, 'video/webm', 'video/webm'], [$video->getKey(), $video->mime_type, $video->getOriginal('mime_type')]);
        $this->assertSame('video/webm', Video::query()->findOrFail(10)->mime_type);
    }

    #[Test]
    public function it_reads_a_value_left_unread_through_a_move_by_the_legacy_id_the_database_normalizes(): void
    {
        $generating = new class extends Model implements Compatible
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
                        ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->external(
                            read: fn (Collection $files): array => $files->pluck('mime_type', 'id')->all(),
                            write: fn (File $file, null|string $mimeType) => $file->mime_type = (string) $mimeType))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->fixed(null)))
                    ->as(Link::class, fn (Successor $link) => $link
                        ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->fixed(null))
                        ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url')));
            }
        };
        File::creating(function (File $file): void {
            $file->mime_type ??= 'video/'.pathinfo($file->filename, PATHINFO_EXTENSION);
        });
        $video = $generating::query()->findOrFail(2);

        $video->update(['id' => '020', 'youtube_embed_url' => null, 'original_file_name' => 'oak.mp4']);

        $this->assertSame([20, 'video/mp4'], [$video->getKey(), $video->getAttribute('mime_type')]);
        $this->assertSame('video/mp4', $generating::query()->findOrFail(20)->getAttribute('mime_type'));
    }
}
