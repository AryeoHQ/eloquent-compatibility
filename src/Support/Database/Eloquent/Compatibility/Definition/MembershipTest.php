<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Schema\Statements;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(Membership::class)]
final class MembershipTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        FileTables::create();
        FileTables::seed();
    }

    protected function tearDown(): void
    {
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_names_each_legacy_model_by_its_morph_alias_in_the_discriminator_of_the_successor(): void
    {
        $memberships = array_map(fn (string $model) => Compatibility::of($model)->resolve()->membership, FileTables::MODELS);

        $this->assertSame(
            [['files', 'legacy_type', 'photo'], ['files', 'legacy_type', 'floorplan'], ['files', 'legacy_type', 'legacy_file']],
            array_map(fn (null|Membership $membership) => [$membership?->table, $membership?->column, $membership?->alias], $memberships),
        );
        $this->assertNull(Compatibility::of(Listing::class)->resolve()->membership);
    }

    #[Test]
    public function it_compiles_when_into_a_check_that_refuses_the_alias_on_a_row_it_excludes_from_any_writer(): void
    {
        FileTables::install();
        $refusals = [];

        foreach ([
            fn () => DB::table('files')->insert(['filename' => 'lease.pdf', 'mime_type' => 'application/pdf', 'legacy_type' => 'photo']),
            fn () => DB::table('files')->where('legacy_type', 'legacy_file')->where('legacy_id', 1)->update(['legacy_type' => 'photo']),
            fn () => File::query()->where('legacy_type', 'photo')->where('legacy_id', 1)->firstOrFail()->update(['mime_type' => 'text/plain']),
            fn () => Photo::query()->create(['file_name' => 'scan.txt', 'mime_type' => 'text/plain']),
        ] as $write) {
            try {
                $write();
            } catch (QueryException $exception) {
                $refusals[] = $exception->getCode().' '.(str_contains($exception->getMessage(), 'files_photos_membership') ? 'files_photos_membership' : $exception->getMessage());
            }
        }

        DB::table('files')->insert([['filename' => 'lease.pdf', 'mime_type' => 'application/pdf', 'legacy_type' => null], ['filename' => 'lease-plan.pdf', 'mime_type' => 'application/pdf', 'legacy_type' => 'floorplan']]);

        $this->assertSame(array_fill(0, 4, '23514 files_photos_membership'), $refusals);
        $this->assertSame(
            ['files_floorplans_membership' => true, 'files_photos_membership' => true],
            array_column(DB::select("select conname, convalidated from pg_constraint where conrelid = 'files'::regclass and contype = 'c' and conname like '%membership' order by conname"), 'convalidated', 'conname'),
        );
        $this->assertSame(['lease-plan.pdf'], FloorPlan::query()->where('mime_type', 'application/pdf')->where('file_name', 'like', 'lease%')->pluck('file_name')->all());
    }

    #[Test]
    public function it_refuses_the_alias_on_a_row_whose_when_reads_a_null(): void
    {
        $map = self::photos(fn (Successor $file) => $file
            ->when(fn (Builder $files) => $files->where('size_bytes', '>', 0))
            ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
            ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type')))->resolve();
        Compatibility::prepare(Photo::class);
        FileTables::backfill();
        (new Installer(DB::connection()))->run(Statements::install($map, DB::connection()));

        try {
            DB::table('files')->insert(['filename' => 'blank.jpg', 'mime_type' => 'image/jpeg', 'legacy_type' => 'photo', 'size_bytes' => null]);
            $this->fail('The row was stored.');
        } catch (QueryException $exception) {
            $this->assertSame(['23514', true], [$exception->getCode(), str_contains($exception->getMessage(), 'files_photos_membership')]);
        }

        DB::table('files')->insert(['filename' => 'blank.jpg', 'mime_type' => 'image/jpeg', 'legacy_type' => null, 'size_bytes' => null]);

        $this->assertSame(1, DB::table('files')->where('filename', 'blank.jpg')->count());
    }

    #[Test]
    public function it_recompiles_the_check_to_the_when_the_map_now_declares(): void
    {
        FileTables::install();
        $widened = new class extends Model implements Compatible
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
                        ->when(fn (Builder $files) => $files->where('mime_type', 'like', 'image/%')->orWhere('mime_type', 'application/pdf'))
                        ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type'))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000)));
            }
        };
        $narrowed = new class extends Model implements Compatible
        {
            use ServesCompatibility;

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
                    ->timestamps()
                    ->servedFrom(File::class, fn (Successor $file) => $file
                        ->when(fn (Builder $files) => $files->where('mime_type', 'image/jpeg'))
                        ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type'))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000)));
            }
        };

        Compatibility::recompile($widened::class);
        $widened::query()->create(['file_name' => 'lease.pdf', 'mime_type' => 'application/pdf']);

        try {
            Compatibility::recompile($narrowed::class);
            $this->fail('recompile() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'photos' cannot recompile: 2 files rows carry legacy_type 'photo' and fail its when(); the first are id ", $exception->getMessage());
        }

        $this->assertSame(['elm.png', 'lease.pdf', 'oak-back.jpg', 'oak-front.jpg'], Photo::query()->orderBy('file_name')->pluck('file_name')->all());
        $this->assertSame(
            [true, true],
            [str_contains((string) DB::scalar("select pg_get_constraintdef(oid) from pg_constraint where conname = 'files_photos_membership'"), 'application/pdf'), DB::scalar("select convalidated from pg_constraint where conname = 'files_photos_membership'")],
        );
    }

    #[Test]
    public function it_adds_no_check_for_a_legacy_model_that_declares_no_when(): void
    {
        FileTables::install();

        DB::table('files')->insert(['filename' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'legacy_type' => 'legacy_file']);

        $this->assertNull(DB::scalar("select conname from pg_constraint where conname = 'files_legacy_files_membership'"));
        $this->assertSame(['contract.pdf', 'notes.txt', 'photo.jpg'], LegacyFile::query()->orderBy('id')->pluck('file_name')->all());
    }

    #[Test]
    public function it_refuses_to_install_while_a_row_carries_the_alias_and_fails_when(): void
    {
        Compatibility::prepare(Photo::class);
        FileTables::backfill();
        DB::table('files')->where('legacy_type', 'photo')->where('legacy_id', 2)->update(['mime_type' => 'application/pdf']);

        try {
            Compatibility::install(Photo::class);
            $this->fail('install() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'photos' cannot install: 1 files rows carry legacy_type 'photo' and fail its when(); the first are id ", $exception->getMessage());
        }

        $this->assertSame('r', DB::scalar("select relkind::text from pg_class where oid = 'photos'::regclass"));
        $this->assertNull(DB::scalar("select conname from pg_constraint where conname = 'files_photos_membership'"));
    }

    #[Test]
    public function it_drops_the_check_install_added_when_it_uninstalls(): void
    {
        FileTables::install();

        Compatibility::uninstall(Photo::class);
        DB::table('files')->insert(['filename' => 'lease.pdf', 'mime_type' => 'application/pdf', 'legacy_type' => 'photo']);

        $this->assertNull(DB::scalar("select conname from pg_constraint where conname = 'files_photos_membership'"));
        $this->assertNotNull(DB::scalar("select conname from pg_constraint where conname = 'files_floorplans_membership'"));
    }

    #[Test]
    public function it_refuses_a_when_scope_that_compiles_to_no_plain_condition(): void
    {
        $map = self::photos(fn (Successor $file) => $file
            ->when(fn (Builder $files) => $files->where('mime_type', 'like', 'image/%')->orderBy('filename'))
            ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
            ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type')))->resolve();
        Compatibility::prepare(Photo::class);
        FileTables::backfill();

        $this->expectExceptionObject(InvalidCompatibilityMap::because('photos', 'when() on File compiles to no plain condition on files; it takes where clauses only, because it becomes a CHECK constraint on files'));

        Statements::install($map, DB::connection());
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string, string}>
     */
    public static function invalidMemberships(): iterable
    {
        $unaliased = new class extends Model
        {
            protected $table = 'scans';
        };

        yield 'when() without a discriminator' => [fn () => (new Compatibility(Photo::class))
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(File::class, fn (Successor $file) => $file->when(fn (Builder $files) => $files->whereNotNull('mime_type')))
            ->resolve(), 'photos', 'when() on File states which rows may carry the morph alias, and the map declares no discriminatedBy()'];
        yield 'when() twice' => [fn () => self::photos(fn (Successor $file) => $file->when(fn (Builder $files) => $files)->when(fn (Builder $files) => $files)), 'photos', 'when() on File is declared twice'];
        yield 'when() on a related successor' => [fn () => (new Compatibility(Listing::class))
            ->discriminatedBy('legacy_type')
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(Job::class, fn (Successor $job) => $job->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place->when(fn (Builder $places) => $places->whereNotNull('street'))))
            ->resolve(), 'listings', 'when() on the relation from Job to Place decides membership, and only the servedFrom() successor carries the discriminator'];
        yield 'a model without a morph alias' => [fn () => (new Compatibility($unaliased::class))
            ->discriminatedBy('legacy_type')
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(File::class, fn (Successor $file) => $file)
            ->resolve(), 'scans', 'discriminatedBy() stores the morph alias of '.class_basename($unaliased).', which has none; register one with Relation::morphMap(), because a class name changes when the class moves'];
        yield 'a captured variable' => [function () {
            $type = 'image/%';

            return (new Successor('photos', File::class))->when(fn (Builder $files) => $files->where('mime_type', 'like', $type));
        }, 'photos', 'the when() scope on File captures $type, and a map reads nothing but its own arguments'];
    }

    /**
     * @param  Closure(): mixed  $declare
     */
    #[DataProvider('invalidMemberships')]
    #[Test]
    public function it_refuses_a_membership_it_cannot_enforce_and_names_the_map(Closure $declare, string $map, string $problem): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because($map, $problem));

        $declare();
    }

    #[Test]
    public function it_keeps_a_place_model_free_of_any_membership(): void
    {
        $this->assertNull((new Successor('listings', Place::class))->membershipScope());
    }

    /**
     * @param  Closure(Successor): mixed  $file
     */
    private static function photos(Closure $file): Compatibility
    {
        return (new Compatibility(Photo::class))
            ->discriminatedBy('legacy_type')
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
            ->retainedIn('photos_retained')
            ->servedFrom(File::class, $file);
    }
}
