<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provider;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Backfills\CompletedPass;
use Tests\Fixtures\Support\Files\ArchiveLegacyFile;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\LegacyFileSaving;
use Tests\Fixtures\Support\Files\LegacyFileUpdated;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\JobUpdated;
use Tests\Fixtures\Support\Listings\LifecycleStatus;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\NumberedJob;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\RecordUpdated;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\Fixtures\Support\Listings\TransitionListingStatus;
use Tests\Fixtures\Support\Listings\WritesWhileBooting;
use Tests\TestCase;

#[CoversClass(Forwarder::class)]
#[CoversClass(Provider::class)]
final class ForwarderTest extends TestCase
{
    private const array EVENTS = ['saving', 'creating', 'created', 'updating', 'updated', 'saved', 'deleting', 'deleted', 'restoring', 'restored', 'forceDeleting', 'forceDeleted'];

    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected $enablesPackageDiscoveries = false;

    /** @var list<array<int, mixed>> */
    private array $seen = [];

    private null|string $bootstrap = null;

    private bool $tenant = false;

    private null|bool $jobUpdatedReturns = null;

    /** @var null|array{string, string} */
    private null|array $writesWhileBooting = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $_SERVER['APP_SERVICES_CACHE'] = $_ENV['APP_SERVICES_CACHE'] = sys_get_temp_dir().'/compatibility-services-'.bin2hex(random_bytes(8)).'.php';
    }

    public static function tearDownAfterClass(): void
    {
        $services = $_SERVER['APP_SERVICES_CACHE'] ?? null;
        unset($_SERVER['APP_SERVICES_CACHE'], $_ENV['APP_SERVICES_CACHE']);

        if (is_string($services) && is_file($services)) {
            unlink($services);
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        SyncListingToMls::$synced = [];
        RecordUpdated::$constructed = 0;
        ListingTables::create();

        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
    }

    protected function tearDown(): void
    {
        if ($this->tenant) {
            DB::purge('tenant_b');
            DB::statement('drop schema if exists tenant_b cascade');
        }

        WritesWhileBooting::$writes = null;
        LegacyFileUpdated::$broadcasts = false;
        ArchiveLegacyFile::$archived = [];
        ListingTables::drop();
        FileTables::drop();

        if ($this->bootstrap !== null) {
            (new Filesystem)->deleteDirectory($this->bootstrap);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_forwards_a_job_create_and_then_a_place_create_as_the_events_of_a_listing_create_and_update(): void
    {
        $this->listen(Listing::class, ['saving', 'creating', 'created', 'updating', 'updated', 'saved'], fn (Listing $listing) => [$listing->id, $listing->title, $listing->street, $listing->exists]);

        $job = DB::transaction(fn () => Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]));
        Place::query()->create(['job_id' => $job->id, 'street' => '9 Birch St']);

        $this->assertSame([
            ['saving', null, 'Birch House', null, false],
            ['creating', null, 'Birch House', null, false],
            ['created', 4, 'Birch House', null, true],
            ['saved', 4, 'Birch House', null, true],
            ['saving', 4, 'Birch House', '9 Birch St', true],
            ['updating', 4, 'Birch House', '9 Birch St', true],
            ['updated', 4, 'Birch House', '9 Birch St', true],
            ['saved', 4, 'Birch House', '9 Birch St', true],
        ], $this->seen);
        $this->assertSame([$job->id, '9 Birch St'], [ListingTables::jobOf(4), Listing::query()->findOrFail(4)->street]);
    }

    #[Test]
    public function it_keeps_the_record_of_an_outer_write_while_a_successor_listener_saves_the_same_instance_again(): void
    {
        Event::listen('eloquent.created: '.Job::class, function (Job $job): void {
            $job->update(['price_cents' => 5]);
        });
        $this->listen(Listing::class, ['saving', 'creating', 'created', 'updating', 'updated', 'saved'], fn (Listing $listing) => [spl_object_id($listing), $listing->price]);

        DB::transaction(fn () => Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]));

        $instances = array_values(array_unique(array_column($this->seen, 1)));
        $this->assertSame([
            ['saving', 0, null], ['creating', 0, null], ['created', 0, null],
            ['saving', 1, '0.05'], ['updating', 1, '0.05'], ['updated', 1, '0.05'], ['saved', 1, '0.05'],
            ['saved', 0, '0.05'],
        ], array_map(fn (array $seen) => [$seen[0], array_search($seen[1], $instances, true), $seen[2]], $this->seen));
    }

    #[Test]
    public function it_writes_a_read_only_fact_a_legacy_listener_supplies_for_a_successor_create_as_a_legacy_create_does(): void
    {
        Event::listen('eloquent.creating: '.Listing::class, function (Listing $listing): void {
            $listing->company_id = ListingTables::COMPANY;
        });

        $job = Job::query()->findOrFail(DB::transaction(fn () => Job::query()->create(['name' => 'Birch House']))->id);
        $legacy = DB::table('listings_retained')->where('job_id', $job->id)->value('id');

        $this->assertSame([ListingTables::COMPANY, ListingTables::COMPANY], [$job->company_id, Listing::query()->findOrFail($legacy)->company_id]);
    }

    #[Test]
    public function it_keeps_a_computed_fact_a_legacy_saving_listener_assigns_for_the_later_events_of_a_successor_create(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $this->app->instance(ServedModels::class, $this->servedOnly([Listing::class, Photo::class]));
        Event::listen('eloquent.saving: '.Photo::class, function (Photo $photo): void {
            $photo->mime_type = 'image/webp';
        });
        $this->listen(Photo::class, ['creating', 'created', 'saved'], fn (Photo $photo) => [$photo->file_name, $photo->mime_type]);

        DB::transaction(fn () => File::query()->create(['filename' => 'birch.png', 'mime_type' => 'image/png']));

        $this->assertSame([['creating', 'birch.png', 'image/webp'], ['created', 'birch.png', 'image/webp'], ['saved', 'birch.png', 'image/webp']], $this->seen);
        $this->assertSame('image/webp', Photo::query()->where('file_name', 'birch.png')->firstOrFail()->mime_type);
    }

    #[Test]
    public function it_gives_the_after_events_of_a_successor_create_the_computed_fact_the_create_stored(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $this->app->instance(ServedModels::class, $this->servedOnly([Listing::class, Photo::class]));
        Event::listen('eloquent.saving: '.Photo::class, function (Photo $photo): void {
            $photo->mime_type = 'image/webp';
        });
        Event::listen('eloquent.creating: '.File::class, function (File $file): void {
            FakeMediaLibrary::bound()->put((string) $file->zml_asset_id, new FileMetadata('image/avif'));
        });
        $this->listen(Photo::class, ['creating', 'created', 'saved'], fn (Photo $photo) => [$photo->mime_type]);

        DB::transaction(fn () => File::query()->create(['filename' => 'birch.png', 'mime_type' => 'image/png']));

        $this->assertSame([['creating', 'image/webp'], ['created', 'image/avif'], ['saved', 'image/avif']], $this->seen);
        $this->assertSame('image/avif', Photo::query()->where('file_name', 'birch.png')->firstOrFail()->mime_type);
    }

    #[Test]
    public function it_refuses_a_computed_fact_a_legacy_saving_listener_assigns_for_a_successor_create_on_a_shared_root_after_the_successor_listeners_ran(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        Event::listen('eloquent.saving: '.Photo::class, function (Photo $photo): void {
            $photo->mime_type = 'image/webp';
        });

        try {
            File::query()->create(['filename' => 'birch.png', 'mime_type' => 'image/png']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame('A listener of a '.Photo::class.' event that File forwarded cannot change $mime_type. Why: It is declared computedAs(), and File raised its saving event after its own saving listeners ran, so its operation reaches no save. Instead: Write mime_type through Photo, or change File or the model that stores mime_type directly.', $refusal);
        $this->assertFalse(DB::table('files')->where('filename', 'birch.png')->exists());
    }

    #[Test]
    public function it_forwards_a_direct_successor_save_when_the_search_path_reaches_the_view_through_a_later_schema(): void
    {
        $this->listen(Listing::class, ['updating', 'updated'], fn (Listing $listing) => [$listing->id, $listing->title, $listing->street]);
        DB::statement('create schema compatibility_empty');
        $searchPath = DB::scalar("select current_setting('search_path')");

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_empty, '.$searchPath]);
            Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_empty');
        }

        $this->assertSame([['updating', 1, 'Oak Lodge', '1 Oak St'], ['updated', 1, 'Oak Lodge', '1 Oak St']], $this->seen);
        $this->assertSame('Oak Lodge', Listing::query()->findOrFail(1)->title);
    }

    #[Test]
    public function it_forwards_nothing_for_a_successor_save_when_the_search_path_resolves_the_successor_to_another_table_than_the_view_reads(): void
    {
        $this->listen(Listing::class, ['updating', 'updated'], fn (Listing $listing) => [$listing->id, $listing->title, $listing->street]);
        DB::statement('create schema compatibility_tenant');
        DB::statement('create table compatibility_tenant.jobs as select * from public.jobs');
        $searchPath = DB::scalar("select current_setting('search_path')");
        $oak = ListingTables::jobOf(1);

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_tenant, '.$searchPath]);
            Job::query()->whereKey($oak)->firstOrFail()->update(['name' => 'Tenant Lodge']);
            $tenant = DB::table('compatibility_tenant.jobs')->where(column: 'id', operator: '=', value: $oak)->value('name');
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_tenant cascade');
        }

        $this->assertSame([[], 'Tenant Lodge', 'Oak House'], [$this->seen, $tenant, Listing::query()->findOrFail(1)->title]);
    }

    #[Test]
    public function it_forwards_nothing_for_a_successor_save_when_the_search_path_resolves_the_retained_table_to_another_table_than_the_view_reads(): void
    {
        $retained = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'mls_number',
                            definition: fn (Attribute $attribute) => $attribute->readAs('"listings_retained"."mls_number"')->readOnly(because: 'The legacy table keeps the MLS number.', ignoreWrites: false),
                        ));
            }
        };
        Forwarder::register($this->app->make('events'), $retained::class);
        $this->listen($retained::class, ['updating', 'updated'], fn (Model $listing) => [$listing->getAttribute('id'), $listing->getAttribute('title'), $listing->getAttribute('mls_number')]);
        DB::statement('create schema compatibility_tenant');
        DB::statement('create table compatibility_tenant.listings_retained (like public.listings_retained including all)');
        DB::statement('insert into compatibility_tenant.listings_retained select * from public.listings_retained');
        DB::update("update compatibility_tenant.listings_retained set mls_number = 'MLS-T'");
        $searchPath = DB::scalar("select current_setting('search_path')");

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_tenant, '.$searchPath]);
            Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_tenant cascade');
        }

        $listing = Listing::query()->findOrFail(1);

        $this->assertSame([[], 'Oak Lodge', 'MLS-1'], [$this->seen, $listing->title, $listing->mls_number]);
    }

    #[Test]
    public function it_runs_no_query_of_its_own_when_no_listing_listener_takes_the_event(): void
    {
        Event::listen('eloquent.updated: '.Listing::class, SyncListingToMls::class);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $site = JobSite::query()->where('job_id', $job->id)->firstOrFail();
        $pine = ListingTables::jobOf(2);
        DB::enableQueryLog();

        $job->update(['name' => 'Oak Lodge']);
        $site->update(['latitude' => 1.5]);
        Place::query()->create(['job_id' => Job::withTrashed()->whereKey($pine)->value('id'), 'street' => '2 Pine St']);

        $this->assertSame(
            ['update "jobs"', 'update "job_sites"', 'select "id" from "jobs"', 'insert into "places"'],
            array_map(fn (array $query) => preg_replace('/^((update|insert into|select \S+ from) "\w+").*$/s', '$1', (string) $query['query']), DB::getQueryLog()),
        );
        $this->assertSame([], SyncListingToMls::$synced);
    }

    #[Test]
    public function it_fires_each_legacy_event_once_for_a_write_through_the_legacy_model(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->title]);

        Listing::query()->findOrFail(1)->update(['title' => 'Oak Lodge', 'street' => '5 Oak St', 'latitude' => 2.5]);
        Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'street' => '9 Birch St']);
        Listing::query()->findOrFail(3)->delete();
        Listing::withTrashed()->findOrFail(3)->restore(); // @phpstan-ignore staticMethod.dynamicCall

        $this->assertSame([
            ['saving', 'Oak Lodge'], ['updating', 'Oak Lodge'], ['updated', 'Oak Lodge'], ['saved', 'Oak Lodge'],
            ['saving', 'Birch House'], ['creating', 'Birch House'], ['created', 'Birch House'], ['saved', 'Birch House'],
            ['deleting', 'Elm Cottage'], ['deleted', 'Elm Cottage'],
            ['restoring', 'Elm Cottage'], ['saving', 'Elm Cottage'], ['updating', 'Elm Cottage'], ['updated', 'Elm Cottage'], ['saved', 'Elm Cottage'], ['restored', 'Elm Cottage'],
        ], $this->seen);
    }

    #[Test]
    public function it_forwards_no_change_a_successor_listener_makes_to_the_record_a_legacy_write_is_writing(): void
    {
        Event::listen('eloquent.saved: '.Job::class, function (Job $job): void {
            Place::query()->where('job_id', $job->id)->firstOrFail()->update(['city' => 'Tacoma']);
        });
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);

        Listing::query()->findOrFail(1)->update(['title' => 'Oak Lodge']);

        $this->assertSame([['saving', 1], ['updating', 1], ['updated', 1], ['saved', 1]], $this->seen);
        $this->assertSame('Tacoma', Listing::query()->findOrFail(1)->city);
    }

    #[Test]
    public function it_forwards_no_change_a_successor_listener_makes_to_the_record_a_legacy_write_is_writing_when_the_instance_swaps_its_key_name(): void
    {
        Event::listen('eloquent.saved: '.Job::class, function (Job $job): void {
            Place::query()->where('job_id', $job->id)->firstOrFail()->update(['city' => 'Tacoma']);
        });
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->getAttribute('id')]);
        $listing = Listing::query()->findOrFail(1);
        $listing->setKeyName('uuid');
        $listing->setKeyType('string');

        $listing->update(['title' => 'Oak Lodge']);

        $this->assertSame([['saving', 1], ['updating', 1], ['updated', 1], ['saved', 1]], $this->seen);
        $this->assertSame(['Oak Lodge', 'Tacoma'], [Listing::query()->findOrFail(1)->title, Listing::query()->findOrFail(1)->city]);
    }

    #[Test]
    public function it_forwards_no_save_a_successor_listener_makes_through_another_instance_of_the_primary_a_legacy_write_is_writing(): void
    {
        Event::listen('eloquent.saved: '.Place::class, function (Place $place): void {
            $job = Job::query()->findOrFail($place->job_id);

            if ($job->price_cents !== 1) {
                $job->update(['price_cents' => 1]);
            }
        });
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);

        Listing::query()->findOrFail(1)->update(['street' => '5 Oak St']);

        $this->assertSame([['saving', 1], ['updating', 1], ['updated', 1], ['saved', 1]], $this->seen);
        $this->assertSame('0.01', Listing::query()->findOrFail(1)->price);
    }

    #[Test]
    public function it_forwards_a_successor_write_a_legacy_write_causes_to_another_legacy_model_with_the_same_key(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        Event::listen('eloquent.saved: '.Job::class, function (): void {
            File::query()->whereKey(FileTables::fileOf(table: 'photos', id: 1))->firstOrFail()->update(['filename' => 'oak-porch.jpg']);
        });
        $this->listen(Photo::class, ['updated'], fn (Photo $photo) => [$photo->id, $photo->file_name]);

        Listing::query()->findOrFail(1)->update(['title' => 'Oak Lodge']);

        $this->assertSame([['updated', 1, 'oak-porch.jpg']], $this->seen);
    }

    #[Test]
    public function it_gives_every_event_of_one_successor_write_the_same_listing_instance(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [spl_object_id($listing)]);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $writes = [];

        foreach ([fn () => $job->update(['name' => 'Oak Lodge']), fn () => $job->delete(), fn () => $job->restore(), fn () => $job->forceDelete()] as $write) { // @phpstan-ignore staticMethod.dynamicCall
            $this->seen = [];
            $write();
            $writes[] = [count($this->seen), count(array_unique(array_column($this->seen, 1)))];
        }

        $this->assertSame([[4, 1], [2, 1], [6, 1], [4, 1]], $writes);
    }

    #[Test]
    public function it_forwards_to_a_faked_legacy_event(): void
    {
        Event::fake(['eloquent.updated: '.Listing::class]);

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);

        Event::assertDispatchedTimes('eloquent.updated: '.Listing::class, 1);
        Event::assertDispatched('eloquent.updated: '.Listing::class, fn (string $event, Listing $listing) => [$listing->id, $listing->title] === [1, 'Oak Lodge']);
    }

    #[Test]
    public function it_forwards_nothing_for_a_mass_write_or_a_quiet_write_of_a_successor(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $place = Place::query()->where('job_id', $job->id)->firstOrFail();

        Job::query()->whereKey(ListingTables::jobOf(1))->update(['name' => 'Oak Lodge']);
        Place::query()->whereKey($place->id)->update(['street' => '5 Oak St']);
        $job->fill(['price_cents' => 1])->saveQuietly();
        $place->fill(['city' => 'Tacoma'])->saveQuietly();
        $place->deleteQuietly();
        Job::query()->whereKey(ListingTables::jobOf(3))->firstOrFail()->deleteQuietly();

        $this->assertSame([], $this->seen);
        $this->assertSame(['Oak Lodge', '0.01', null, null], [Listing::query()->findOrFail(1)->title, Listing::query()->findOrFail(1)->price, Listing::query()->findOrFail(1)->street, Listing::query()->find(3)]);
    }

    #[Test]
    public function it_forwards_the_delete_restore_and_force_delete_of_a_job_as_the_same_listing_events(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->deleted_at !== null, $listing->exists]);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();

        $job->delete();
        $job->restore(); // @phpstan-ignore staticMethod.dynamicCall
        $job->forceDelete();

        $this->assertSame([
            ['deleting', 1, false, true], ['deleted', 1, true, true],
            ['restoring', 1, true, true], ['saving', 1, false, true], ['updating', 1, false, true], ['updated', 1, false, true], ['saved', 1, false, true], ['restored', 1, false, true],
            ['forceDeleting', 1, false, true], ['deleting', 1, false, true], ['deleted', 1, false, false], ['forceDeleted', 1, false, false],
        ], $this->seen);
    }

    #[RequiresMethod(Job::class, 'softDeleted')]
    #[Test]
    public function it_forwards_the_trashed_event_of_a_soft_deleted_job(): void
    {
        $this->listen(Listing::class, ['deleting', 'trashed', 'deleted'], fn (Listing $listing) => [$listing->deleted_at !== null]);

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->delete();

        $this->assertSame([['deleting', false], ['trashed', true], ['deleted', true]], $this->seen);
    }

    #[Test]
    public function it_cancels_the_successor_write_a_legacy_listener_refuses(): void
    {
        $before = $this->snapshot();
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $place = Place::query()->where('job_id', $job->id)->firstOrFail();

        Event::listen('eloquent.saving: '.Listing::class, fn (Listing $listing) => $listing->title !== 'Refused');
        Event::listen('eloquent.updating: '.Listing::class, fn (Listing $listing) => $listing->street !== 'Refused');
        Event::listen('eloquent.deleting: '.Listing::class, fn () => false);
        Event::listen('eloquent.restoring: '.Listing::class, fn () => false);

        $this->assertSame([false, false, false, false], [
            $job->fill(['name' => 'Refused'])->save(),
            $place->fill(['street' => 'Refused'])->save(),
            $job->refresh()->delete(),
            Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail()->restore(), // @phpstan-ignore staticMethod.dynamicCall
        ]);
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_writes_a_change_a_legacy_listener_makes_to_a_fact_the_saving_model_owns(): void
    {
        Event::listen('eloquent.saving: '.Listing::class, function (Listing $listing): void {
            if ($listing->title === 'Oak Lodge') {
                $listing->title = 'OAK LODGE';
                $listing->price = '15.25';
            }
        });
        Event::listen('eloquent.updating: '.Listing::class, function (Listing $listing): void {
            if ($listing->street === '5 Oak St') {
                $listing->city = 'Tacoma';
            }
        });
        $seen = [];
        Event::listen('eloquent.updated: '.Listing::class, function (Listing $listing) use (&$seen): void {
            $seen[] = [$listing->title, $listing->price, $listing->street, $listing->city];
        });
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();

        $job->update(['name' => 'Oak Lodge']);
        Place::query()->where('job_id', $job->id)->firstOrFail()->update(['street' => '5 Oak St']);

        $this->assertSame([['OAK LODGE', 1525], ['street' => '5 Oak St', 'city' => 'Tacoma']], [[$job->name, $job->price_cents], Place::query()->where('job_id', $job->id)->firstOrFail()->only(['street', 'city'])]);
        $this->assertSame([['OAK LODGE', '15.25', '1 Oak St', 'Seattle'], ['OAK LODGE', '15.25', '5 Oak St', 'Tacoma']], $seen);
    }

    #[Test]
    public function it_refuses_a_change_a_legacy_listener_makes_to_a_fact_the_saving_model_does_not_own(): void
    {
        $before = $this->snapshot();
        $cases = [
            ['saving', fn (Listing $listing) => $listing->street = '5 Oak St', 'street', 'It lives on Place, and the forwarded event belongs to a save of Job.'],
            ['saving', fn (Listing $listing) => $listing->mls_number = 'MLS-9', 'mls_number', 'No successor model stores it, so the forwarded write has nowhere to put it.'],
            ['saving', fn (Listing $listing) => $listing->id = 9, 'id', 'It identifies the record, and a forwarded event cannot move it.'],
            ['saving', fn (Listing $listing) => $listing->company_id = 'a7c3e5f1-2b4d-4e6f-8a0b-1c2d3e4f5a6b', 'company_id', 'A job never changes company.'],
            ['saving', fn (Listing $listing) => $listing->price = 'cheap', 'price', "'cheap' is not a number, so it cannot be multiplied by 100."],
            ['updating', fn (Listing $listing) => $listing->status = ListingStatus::Sold, 'status', 'It is writtenBy() TransitionListingStatus, and only a write through Listing runs its operation, which may stage other successors.'],
            ['deleting', fn (Listing $listing) => $listing->title = 'Gone', 'title', 'The forwarded event belongs to a delete, which writes no value.'],
        ];
        $refusals = [];
        $expected = [];

        foreach ($cases as [$event, $change, $attribute, $because]) {
            Event::forget("eloquent.{$event}: ".Listing::class);
            Event::listen("eloquent.{$event}: ".Listing::class, $change);
            $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();

            try {
                $event === 'deleting' ? $job->delete() : $job->update(['name' => 'Oak Lodge']);
                $refusals[] = null;
            } catch (CompatibilityWriteRefused $refusal) {
                $refusals[] = $refusal->getMessage();
            }

            Event::forget("eloquent.{$event}: ".Listing::class);
            $expected[] = 'A listener of a '.Listing::class." event that Job forwarded cannot change \${$attribute}. Why: {$because} Instead: Write {$attribute} through Listing, or change Job or the model that stores {$attribute} directly.";
        }

        $this->assertSame($expected, $refusals);
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_gives_a_listener_named_in_without_forwarding_to_only_the_writes_made_through_the_legacy_model(): void
    {
        Event::listen('eloquent.updated: '.Listing::class, SyncListingToMls::class);
        Event::listen('eloquent.updated: '.Listing::class, SyncListingToMls::class.'@handle');
        Event::listen('eloquent.updated: '.Listing::class, [SyncListingToMls::class, 'handle']);
        Event::listen('eloquent.updated: '.Listing::class, [new SyncListingToMls, 'handle']);
        Event::listen('eloquent.updated: '.Listing::class, new SyncListingToMls);
        $this->listen(Listing::class, ['updated'], fn (Listing $listing) => [$listing->id]);

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);
        $forwarded = SyncListingToMls::$synced;
        Listing::query()->findOrFail(3)->update(['title' => 'Elm Lodge']);

        $this->assertSame([[], [3, 3, 3, 3, 3]], [$forwarded, SyncListingToMls::$synced]);
        $this->assertSame([['updated', 1], ['updated', 3]], $this->seen);
    }

    #[Test]
    public function it_forwards_a_dependent_write_only_when_it_changes_a_value_the_listing_serves(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->street, $listing->latitude]);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $root = Place::query()->where('job_id', $job->id)->firstOrFail();
        $site = JobSite::query()->where('job_id', $job->id)->firstOrFail();

        Place::query()->create(['job_id' => $job->id, 'parent_id' => $root->id, 'street' => 'Unit 2']);
        $root->touch();
        $root->update(['street' => '5 Oak St']);
        $site->update(['latitude' => 1.5]);
        $root->delete();
        $site->delete();

        $this->assertSame([
            ['saving', 1, '5 Oak St', 47.6062], ['updating', 1, '5 Oak St', 47.6062], ['updated', 1, '5 Oak St', 47.6062], ['saved', 1, '5 Oak St', 47.6062],
            ['saving', 1, '5 Oak St', 1.5], ['updating', 1, '5 Oak St', 1.5], ['updated', 1, '5 Oak St', 1.5], ['saved', 1, '5 Oak St', 1.5],
            ['saving', 1, null, 1.5], ['updating', 1, null, 1.5], ['updated', 1, null, 1.5], ['saved', 1, null, 1.5],
            ['saving', 1, null, null], ['updating', 1, null, null], ['updated', 1, null, null], ['saved', 1, null, null],
        ], $this->seen);
    }

    #[Test]
    public function it_forwards_a_dependent_that_moves_to_another_record_to_both_records(): void
    {
        $this->listen(Listing::class, ['updating', 'updated'], fn (Listing $listing) => [$listing->id, $listing->getOriginal('street'), $listing->street]);
        $elm = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(3))->value('id'),
        )->firstOrFail();

        $elm->update(['job_id' => Job::withTrashed()->whereKey(ListingTables::jobOf(2))->value('id')]);

        $this->assertSame([
            ['updating', 2, null, '3 Elm St'], ['updating', 3, '3 Elm St', null],
            ['updated', 2, null, '3 Elm St'], ['updated', 3, '3 Elm St', null],
        ], $this->seen);
    }

    #[Test]
    public function it_forwards_a_write_of_a_shared_root_only_to_the_legacy_model_whose_retained_table_holds_it(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $describe = fn (Model $legacy) => [class_basename($legacy), $legacy->getAttribute('id'), $legacy->getAttribute('file_name')];

        foreach ([Photo::class, LegacyFile::class] as $model) {
            $this->listen($model, self::EVENTS, $describe);
        }

        $photo = File::query()->whereKey(FileTables::fileOf(table: 'photos', id: 1))->firstOrFail();
        DB::enableQueryLog();
        $photo->update(['filename' => 'oak-porch.jpg']);
        $read = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        $updated = $this->seen;
        $this->seen = [];
        $lease = File::query()->create(['filename' => 'birch-lease.pdf', 'mime_type' => 'application/pdf']);
        $tour = File::query()->create(['filename' => 'tour.mp4', 'mime_type' => 'video/mp4']);

        $this->assertStringContainsString('"photos_retained"', $read);
        $this->assertStringNotContainsString('"legacy_files_retained"', $read);
        $this->assertSame([
            ['saving', 'Photo', 1, 'oak-porch.jpg'], ['updating', 'Photo', 1, 'oak-porch.jpg'], ['updated', 'Photo', 1, 'oak-porch.jpg'], ['saved', 'Photo', 1, 'oak-porch.jpg'],
        ], $updated);
        $this->assertSame([
            ['saving', 'LegacyFile', null, 'birch-lease.pdf'], ['creating', 'LegacyFile', null, 'birch-lease.pdf'], ['created', 'LegacyFile', 3, 'birch-lease.pdf'], ['saved', 'LegacyFile', 3, 'birch-lease.pdf'],
        ], $this->seen);
        $this->assertSame([[true, false], [false, false]], array_map(fn (File $file) => [
            DB::table('legacy_files_retained')->where('file_id', $file->id)->exists(),
            DB::table('photos_retained')->where('file_id', $file->id)->exists(),
        ], [$lease, $tour]));
    }

    #[Test]
    public function it_forwards_a_native_create_of_a_shared_root_to_no_legacy_model_whose_when_leaves_the_new_row_out_and_reads_each_legacy_record_once(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $standIns = [];
        DB::listen(function (QueryExecuted $query) use (&$standIns): void {
            if (preg_match('/, "(\w+)" AS \(SELECT \* FROM jsonb_populate_record\(NULL::"\1"/', $query->sql, $match) === 1) {
                $standIns[] = $match[1];
            }
        });
        $this->listen(Photo::class, self::EVENTS, fn (Photo $photo) => ['Photo', $photo->getAttribute('file_name')]);
        $this->listen(LegacyFile::class, self::EVENTS, fn (LegacyFile $file) => ['LegacyFile', $file->getAttribute('file_name')]);
        $creates = [];

        foreach (['birch-lease.pdf' => 'application/pdf', 'birch.jpg' => 'image/jpeg'] as $filename => $mimeType) {
            $this->seen = [];
            $standIns = [];
            File::query()->create(['filename' => $filename, 'mime_type' => $mimeType]);
            $read = array_count_values($standIns);
            ksort($read);
            $creates[$filename] = [array_map(fn (array $seen) => "{$seen[1]} {$seen[0]}", $this->seen), $read];
        }

        $this->assertSame([
            'birch-lease.pdf' => [['LegacyFile saving', 'LegacyFile creating', 'LegacyFile created', 'LegacyFile saved'], ['legacy_files_retained' => 1, 'photos_retained' => 1]],
            'birch.jpg' => [['Photo saving', 'Photo creating', 'Photo created', 'Photo saved'], ['legacy_files_retained' => 1, 'photos_retained' => 1]],
        ], $creates);
    }

    #[Test]
    public function it_reads_no_stored_root_or_retained_row_in_the_stand_in_query_of_a_native_create(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $standIns = [];
        DB::listen(function (QueryExecuted $query) use (&$standIns): void {
            if (str_starts_with($query->sql, 'WITH ') && str_contains($query->sql, 'jsonb_populate_record')) {
                $standIns[] = [$query->sql, $query->bindings];
            }
        });
        $this->listen(Photo::class, ['saving'], fn (Photo $photo) => [$photo->getAttribute('file_name')]);
        $this->listen(LegacyFile::class, ['saving'], fn (LegacyFile $file) => [$file->getAttribute('file_name')]);
        $this->listen(Listing::class, ['saving'], fn (Listing $listing) => [$listing->title]);

        File::query()->create(['filename' => 'birch.jpg', 'mime_type' => 'image/jpeg']);
        Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]);
        $scans = [];

        foreach ($standIns as [$sql, $bindings]) {
            foreach (DB::select('EXPLAIN (COSTS OFF) '.$sql, $bindings) as $line) {
                $plan = (string) (((array) $line)['QUERY PLAN'] ?? '');

                if (preg_match('/CTE Scan|Scan(?: using \S+)? on (?:files|photos_retained|legacy_files_retained|jobs|listings_retained)\b/', $plan) === 1) {
                    $scans[] = trim($plan);
                }
            }
        }

        $this->assertSame([3, []], [count($standIns), $scans]);
    }

    #[Test]
    public function it_reads_the_stand_in_row_of_a_native_create_whose_root_key_is_a_scaled_numeric(): void
    {
        DB::statement('create sequence numbered_jobs_id_seq');
        DB::statement("create table numbered_jobs (id numeric(20, 0) primary key default nextval('numbered_jobs_id_seq'), name text not null)");
        DB::statement('create table holders (id serial primary key, name text not null, job_id numeric(20, 0), created_at timestamptz, updated_at timestamptz)');
        DB::table('holders')->insert([['name' => 'Oak']]);
        $holder = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'holders';

            protected $guarded = [];

            public static function compatibility(Compatibility $holder): Compatibility
            {
                return $holder
                    ->retainedIn('holders_retained')
                    ->servedFrom(root: NumberedJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'name', definition: fn (Attribute $attribute) => $attribute->from('name')));
            }
        };

        try {
            Compatibility::prepare($holder::class);
            DB::statement("update holders set job_id = nextval('numbered_jobs_id_seq')");
            DB::statement('insert into numbered_jobs (id, name) select job_id, name from holders');
            Compatibility::install(
                model: $holder::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            Forwarder::register($this->app->make('events'), $holder::class);
            $this->listen($holder::class, ['saving'], fn (Model $legacy) => [$legacy->getAttribute('name')]);

            NumberedJob::query()->create(['name' => 'Birch']);
        } finally {
            DB::statement('drop view if exists holders');
            DB::statement('drop table if exists holders, holders_retained, holders_backfill, numbered_jobs cascade');
            DB::statement('drop sequence if exists numbered_jobs_id_seq');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type IN ('holders'); END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }

        $this->assertSame([['saving', 'Birch']], $this->seen);
    }

    #[Test]
    public function it_forwards_the_saving_and_creating_of_a_native_create_on_a_root_one_map_serves_ahead_of_the_successor_creating_listeners(): void
    {
        Event::listen('eloquent.creating: '.Job::class, function (Job $job): void {
            $this->seen[] = ['Job creating', $job->name];
        });
        $this->listen(Listing::class, ['saving', 'creating', 'created', 'saved'], fn (Listing $listing) => [$listing->title]);

        Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame([['saving', 'Birch House'], ['creating', 'Birch House'], ['Job creating', 'Birch House'], ['created', 'Birch House'], ['saved', 'Birch House']], $this->seen);
    }

    #[Test]
    public function it_forwards_a_write_of_the_file_of_a_video_to_the_video_it_serves(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::seedMedia();
        FileTables::install();
        FileTables::installMedia();
        $this->listen(Video::class, ['creating', 'created', 'updating', 'updated'], fn (Video $video) => [$video->id, $video->original_file_name, $video->youtube_embed_url, $video->getOriginal('youtube_embed_url')]);
        $this->listen(InteractiveContent::class, self::EVENTS, fn (InteractiveContent $content) => [$content->id]);

        File::query()->whereKey(FileTables::fileOf(table: 'videos', id: 2))->firstOrFail()->update(['embed_url' => 'https://youtu.be/oak-2']);
        File::query()->whereKey(FileTables::fileOf(table: 'videos', id: 3))->firstOrFail()->update(['filename' => 'drone-2.mov']);
        $walk = File::query()->create(['filename' => 'walk.mp4', 'mime_type' => 'video/mp4']);

        $this->assertSame([
            ['updating', 2, null, 'https://youtu.be/oak-2', 'https://youtu.be/oak'], ['updated', 2, null, 'https://youtu.be/oak-2', 'https://youtu.be/oak'],
            ['updating', 3, 'drone-2.mov', null, null], ['updated', 3, 'drone-2.mov', null, null],
            ['creating', null, 'walk.mp4', null, null], ['created', 4, 'walk.mp4', null, null],
        ], $this->seen);
        $this->assertSame([true, false, false], [
            DB::table('videos_retained')->where('file_id', $walk->id)->exists(),
            DB::table('photos_retained')->where('file_id', $walk->id)->exists(),
            DB::table('legacy_files_retained')->where('file_id', $walk->id)->exists(),
        ]);
    }

    #[Test]
    public function it_forwards_nothing_while_the_legacy_table_is_still_a_table(): void
    {
        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);
        Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame([], $this->seen);
        $this->assertSame('Oak House', DB::table('listings')->where('id', 1)->value('title'));
    }

    #[DefineEnvironment('servesOutsideTestingFromACache')]
    #[Test]
    public function it_forwards_from_the_first_successor_write_outside_local_and_testing_from_a_cache_that_names_a_removed_class(): void
    {
        $this->listen(Listing::class, ['updated'], fn (Listing $listing) => [$listing->id, $listing->title]);

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);

        $this->assertSame(['staging', [['updated', 1, 'Oak Lodge']]], [$this->app->environment(), $this->seen]);
    }

    #[DefineEnvironment('writesWhileBooting')]
    #[Test]
    public function it_forwards_a_successor_write_another_provider_makes_while_the_application_boots(): void
    {
        $this->writesWhileBooting = [DB::connection()->getName(), DB::connection()->getDatabaseName()];

        $this->refreshApplication();

        $this->assertSame([['updated', 1, 'Oak Lodge']], $this->seen);
    }

    #[Test]
    public function it_forwards_no_save_a_successor_listener_makes_to_the_record_a_legacy_create_is_creating(): void
    {
        Event::listen('eloquent.saved: '.Place::class, function (Place $place): void {
            $job = Job::query()->findOrFail($place->job_id);

            if ($job->price_cents !== 1) {
                $job->update(['price_cents' => 1]);
            }
        });
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);

        Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'street' => '9 Birch St']);

        $this->assertSame([['saving', null], ['creating', null], ['created', 4], ['saved', 4]], $this->seen);
        $this->assertSame('0.01', Listing::query()->findOrFail(4)->price);
    }

    #[Test]
    public function it_reads_a_fresh_record_for_the_next_write_of_an_instance_after_a_legacy_listener_refuses_one(): void
    {
        Event::listen('eloquent.restoring: '.Listing::class, fn () => false);
        $this->listen(Listing::class, ['updating'], fn (Listing $listing) => [$listing->getOriginal('title'), $listing->title]);
        $pine = Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail();

        $restored = $pine->restore(); // @phpstan-ignore staticMethod.dynamicCall
        DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: ListingTables::jobOf(2),
        )->update(['name' => 'Pine Lodge']);
        $pine->fill(['price_cents' => 7])->save();

        $this->assertSame([false, [['updating', 'Pine Lodge', 'Pine Lodge']]], [$restored, $this->seen]);
    }

    #[Test]
    public function it_forwards_nothing_for_a_successor_write_that_moves_a_record_into_or_out_of_a_hide_rule(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);
        $oak = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();

        $oak->update(['merged_into_job_id' => Job::query()->whereKey(ListingTables::jobOf(3))->value('id')]);
        $hidden = Listing::query()->find(1);
        $oak->update(['merged_into_job_id' => null]);
        $birch = Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'merged_into_job_id' => Job::query()->whereKey(ListingTables::jobOf(3))->value('id')]);
        $created = DB::table('listings')->where('title', 'Birch House')->exists();
        $birch->update(['merged_into_job_id' => null]);

        $this->assertSame([[], null, true], [$this->seen, $hidden, Listing::query()->find(1) !== null]);
        $this->assertSame([false, true], [$created, DB::table('listings')->where('title', 'Birch House')->exists()]);
    }

    #[Test]
    public function it_writes_a_change_a_legacy_listener_makes_to_an_external_fact_of_the_saving_model_through_its_write_closure(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        Event::listen('eloquent.saving: '.Photo::class, function (Photo $photo): void {
            if ($photo->file_name === 'oak-porch.jpg') {
                $photo->mime_type = 'image/webp';
            }
        });
        $file = File::query()->whereKey(FileTables::fileOf(table: 'photos', id: 1))->firstOrFail();

        $file->update(['filename' => 'oak-porch.jpg']);

        $this->assertSame(
            ['image/webp', 'image/webp', 'image/webp'],
            [FakeMediaLibrary::bound()->stored((string) $file->zml_asset_id)?->mimeType, File::query()->findOrFail($file->id)->mime_type, Photo::query()->findOrFail(1)->mime_type],
        );
    }

    #[Test]
    public function it_forwards_an_event_object_a_legacy_model_dispatches_even_when_only_that_object_has_a_listener(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $seen = [];
        Event::listen(LegacyFileUpdated::class, function (LegacyFileUpdated $event) use (&$seen): void {
            $seen[] = [$event->legacyFile->id, $event->legacyFile->file_name];
        });

        File::query()->whereKey(FileTables::fileOf(table: 'legacy_files', id: 1))->firstOrFail()->update(['filename' => 'contract-2.pdf']);

        $this->assertSame([[1, 'contract-2.pdf']], $seen);
    }

    #[Test]
    public function it_forwards_an_event_object_before_the_string_event_and_lets_its_listener_refuse_the_write(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $seen = [];
        Event::listen(LegacyFileSaving::class, function (LegacyFileSaving $event) use (&$seen): null|false {
            $seen[] = [LegacyFileSaving::class, $event->legacyFile->file_name];

            return $event->legacyFile->file_name === 'refused.pdf' ? false : null;
        });
        Event::listen('eloquent.saving: '.LegacyFile::class, function (LegacyFile $legacyFile) use (&$seen): void {
            $seen[] = ['saving', $legacyFile->file_name];
        });
        $file = File::query()->whereKey(FileTables::fileOf(table: 'legacy_files', id: 1))->firstOrFail();

        $saved = [$file->fill(['filename' => 'contract-2.pdf'])->save(), $file->fill(['filename' => 'refused.pdf'])->save()];

        $this->assertSame([true, false], $saved);
        $this->assertSame([[LegacyFileSaving::class, 'contract-2.pdf'], ['saving', 'contract-2.pdf'], [LegacyFileSaving::class, 'refused.pdf']], $seen);
        $this->assertSame('contract-2.pdf', LegacyFile::query()->findOrFail(1)->file_name);
    }

    #[Test]
    public function it_keeps_a_listener_named_in_without_forwarding_to_from_forwarded_events_while_another_event_is_faked(): void
    {
        Event::fake(['compatibility.unrelated']);
        Event::listen('eloquent.updated: '.Listing::class, SyncListingToMls::class);
        $this->listen(Listing::class, ['updated'], fn (Listing $listing) => [$listing->id]);

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);

        $this->assertSame([[], [['updated', 1]]], [SyncListingToMls::$synced, $this->seen]);
    }

    #[Test]
    public function it_forwards_a_dependent_write_whose_own_later_listener_changes_a_value_the_listing_serves(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->city]);
        Event::listen('eloquent.saving: '.Place::class, function (Place $place): void {
            $place->city = 'Tacoma';
        });

        Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail()->touch();

        $this->assertSame([['saving', 1, 'Tacoma'], ['updating', 1, 'Tacoma'], ['updated', 1, 'Tacoma'], ['saved', 1, 'Tacoma']], $this->seen);
    }

    #[Test]
    public function it_accepts_a_legacy_listener_assignment_the_legacy_casts_hold_equal_to_the_stored_value(): void
    {
        Event::listen('eloquent.saving: '.Listing::class, function (Listing $listing): void {
            $listing->price = '1250.5';
        });

        Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail()->update(['street' => '5 Oak St']);

        $this->assertSame(['5 Oak St', '1250.50'], [Listing::query()->findOrFail(1)->street, Listing::query()->findOrFail(1)->price]);
    }

    #[Test]
    public function it_gives_the_listing_the_force_deleting_state_of_the_job_write_it_forwards(): void
    {
        $this->listen(Listing::class, ['forceDeleting', 'deleting', 'deleted', 'forceDeleted'], fn (Listing $listing) => [$listing->id, $listing->isForceDeleting()]);

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->forceDelete();
        Job::query()->whereKey(ListingTables::jobOf(3))->firstOrFail()->delete();

        $this->assertSame([
            ['forceDeleting', 1, false], ['deleting', 1, true], ['deleted', 1, true], ['forceDeleted', 1, false],
            ['deleting', 3, false], ['deleted', 3, false],
        ], $this->seen);
    }

    #[Test]
    public function it_gives_the_listing_the_connection_the_job_write_it_forwards_runs_on(): void
    {
        config(['database.connections.tenant_b' => config('database.connections.'.DB::getDefaultConnection())]);
        $this->listen(Listing::class, ['saving', 'created', 'updated', 'saved'], fn (Listing $listing) => [$listing->getConnectionName()]);

        Job::on('tenant_b')->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);

        $this->assertSame([['saving', 'tenant_b'], ['updated', 'tenant_b'], ['saved', 'tenant_b']], $this->seen);
    }

    #[Test]
    public function it_gives_the_saved_event_of_a_job_write_to_its_own_listing_after_an_updated_listener_increments_the_job(): void
    {
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $incremented = false;
        Event::listen('eloquent.updated: '.Job::class, function () use ($job, &$incremented): void {
            if (! $incremented) {
                $incremented = true;
                $job->increment('price_cents', 5);
            }
        });
        $this->listen(Listing::class, ['saving', 'updating', 'updated', 'saved'], fn (Listing $listing) => [spl_object_id($listing), $listing->price]);

        $job->update(['name' => 'Oak Lodge']);

        $instances = array_values(array_unique(array_column($this->seen, 1)));
        $this->assertSame([
            ['saving', 0, '1250.50'], ['updating', 0, '1250.50'], ['updated', 0, '1250.50'],
            ['updating', 1, '1250.55'], ['updated', 1, '1250.55'],
            ['saved', 0, '1250.55'],
        ], array_map(fn (array $seen) => [$seen[0], array_search($seen[1], $instances, true), $seen[2]], $this->seen));
    }

    #[Test]
    public function it_forwards_the_saved_event_of_a_job_write_whose_updated_listener_increments_the_job_without_a_listing_listener_for_the_increment(): void
    {
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $incremented = false;
        Event::listen('eloquent.updated: '.Job::class, function () use ($job, &$incremented): void {
            if (! $incremented) {
                $incremented = true;
                $job->increment('price_cents', 5);
            }
        });
        $this->listen(Listing::class, ['saved'], fn (Listing $listing) => [$listing->id, $listing->title, $listing->price]);

        $job->update(['name' => 'Oak Lodge']);

        $this->assertSame([['saved', 1, 'Oak Lodge', '1250.55']], $this->seen);
    }

    #[Test]
    public function it_runs_no_query_of_its_own_for_a_job_increment_when_the_only_listing_listener_takes_the_saved_event_an_increment_never_raises(): void
    {
        $this->listen(Listing::class, ['saved'], fn (Listing $listing) => [$listing->id]);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        DB::enableQueryLog();

        $job->increment('price_cents', 5);

        $this->assertSame(['update "jobs"'], array_map(fn (array $query) => preg_replace('/^(update "\w+").*$/s', '$1', (string) $query['query']), DB::getQueryLog()));
        $this->assertSame([], $this->seen);
    }

    #[Test]
    public function it_reads_a_fresh_record_for_the_next_write_of_an_instance_after_a_job_listener_refuses_a_restore_or_a_force_delete(): void
    {
        Event::listen('eloquent.restoring: '.Job::class, fn () => false);
        Event::listen('eloquent.forceDeleting: '.Job::class, fn () => false);
        $this->listen(Listing::class, ['restoring', 'forceDeleting', 'deleting', 'updating'], fn (Listing $listing) => [$listing->getOriginal('title'), $listing->title]);
        $pine = Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail();
        $oak = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();

        $refused = [$pine->restore(), $oak->forceDelete()]; // @phpstan-ignore staticMethod.dynamicCall
        DB::table('jobs')->whereIn(column: 'id', values: [ListingTables::jobOf(1), ListingTables::jobOf(2)])->update(['name' => DB::raw("name || ' II'")]);
        $pine->fill(['price_cents' => 7])->save();
        $oak->delete();

        $this->assertSame([false, false], $refused);
        $this->assertSame([
            ['restoring', 'Pine Flat', 'Pine Flat'], ['forceDeleting', 'Oak House', 'Oak House'],
            ['updating', 'Pine Flat II', 'Pine Flat II'], ['deleting', 'Oak House II', 'Oak House II'],
        ], $this->seen);
    }

    #[Test]
    public function it_forwards_a_dependent_write_only_to_the_records_whose_served_values_it_changes(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->street]);
        $oak = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $root = Place::query()->where('job_id', $oak->id)->firstOrFail();
        $unit = Place::query()->create(['job_id' => $oak->id, 'parent_id' => $root->id, 'street' => 'Unit 2']);

        $unit->update(['job_id' => Job::withTrashed()->whereKey(ListingTables::jobOf(2))->value('id'), 'parent_id' => null]);

        $this->assertSame([['saving', 2, 'Unit 2'], ['updating', 2, 'Unit 2'], ['updated', 2, 'Unit 2'], ['saved', 2, 'Unit 2']], $this->seen);
    }

    #[Test]
    public function it_forwards_no_write_a_job_listener_makes_to_the_record_a_legacy_create_is_creating_before_the_create_reads_its_key(): void
    {
        Event::listen('eloquent.created: '.Job::class, function (Job $job): void {
            Place::query()->create(['job_id' => $job->id, 'street' => '9 Birch St']);
            Job::query()->findOrFail($job->id)->update(['price_cents' => 1]);
        });
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);

        Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame([['saving', null], ['creating', null], ['created', 4], ['saved', 4]], $this->seen);
        $this->assertSame(['9 Birch St', '0.01'], [Listing::query()->findOrFail(4)->street, Listing::query()->findOrFail(4)->price]);
    }

    #[Test]
    public function it_forwards_no_write_a_job_saved_listener_makes_to_the_record_a_legacy_create_is_creating_before_the_create_reads_its_key(): void
    {
        Event::listen('eloquent.saved: '.Job::class, function (Job $job): void {
            if ($job->wasRecentlyCreated && ! Place::query()->where('job_id', $job->id)->exists()) {
                Place::query()->create(['job_id' => $job->id, 'street' => '9 Birch St']);
                Job::query()->findOrFail($job->id)->update(['price_cents' => 1]);
            }
        });
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);

        Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame([['saving', null], ['creating', null], ['created', 4], ['saved', 4]], $this->seen);
        $this->assertSame(['9 Birch St', '0.01'], [Listing::query()->findOrFail(4)->street, Listing::query()->findOrFail(4)->price]);
    }

    #[Test]
    public function it_forwards_no_write_a_file_listener_makes_to_the_photo_a_legacy_create_is_creating_before_the_create_reads_its_key(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        Event::listen('eloquent.created: '.File::class, function (File $file): void {
            File::query()->findOrFail($file->id)->update(['size_bytes' => 4000]);
        });
        $this->listen(Photo::class, self::EVENTS, fn (Photo $photo) => [$photo->id]);

        Photo::query()->create(['file_name' => 'birch.jpg', 'mime_type' => 'image/jpeg']);

        $this->assertSame([['saving', null], ['creating', null], ['created', 4], ['saved', 4]], $this->seen);
        $this->assertSame('4.000', Photo::query()->findOrFail(4)->size_kb);
    }

    #[Test]
    public function it_refuses_a_change_a_legacy_listener_makes_to_an_external_fact_after_the_saving_model_ran_its_saving_listeners(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $assigned = [];

        foreach (['creating', 'updating'] as $event) {
            Event::listen("eloquent.{$event}: ".Photo::class, function (Photo $photo) use ($event, &$assigned): void {
                $assigned[] = $event;
                $photo->mime_type = 'image/webp';
            });
        }

        $file = File::query()->whereKey(FileTables::fileOf(table: 'photos', id: 1))->firstOrFail();
        $refusals = [];

        foreach ([fn () => $file->update(['filename' => 'oak-porch.jpg'])] as $write) {
            try {
                $write();
                $refusals[] = null;
            } catch (CompatibilityWriteRefused $refused) {
                $refusals[] = $refused->getMessage();
            }
        }

        $this->assertSame(['updating'], $assigned);
        $this->assertSame(array_map(
            callback: fn (string $event) => 'A listener of a '.Photo::class." event that File forwarded cannot change \$mime_type. Why: It is declared computedAs(), and File raised its {$event} event after its own saving listeners ran, so its operation reaches no save. Instead: Write mime_type through Photo, or change File or the model that stores mime_type directly.",
            array: ['updating'],
        ), $refusals);
        $this->assertSame(['oak-front.jpg', 'image/jpeg', 0], [File::query()->findOrFail($file->id)->filename, Photo::query()->findOrFail(1)->mime_type, File::query()->where('filename', 'birch.png')->count()]);
    }

    #[Test]
    public function it_raises_no_after_event_for_a_record_a_later_successor_listener_moves_into_a_hide_rule(): void
    {
        $elm = Job::query()->whereKey(ListingTables::jobOf(3))->value('id');
        Event::listen('eloquent.updating: '.Job::class, function (Job $job) use ($elm): void {
            $job->merged_into_job_id = $elm;
        });
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);

        $this->assertSame([['saving', 1], ['updating', 1]], $this->seen);
        $this->assertNull(Listing::query()->find(1));
    }

    #[DefineEnvironment('listensForJobUpdatesFirst')]
    #[Test]
    public function it_forwards_a_job_update_once_ahead_of_the_listeners_of_its_event_object_whatever_they_return(): void
    {
        $this->listen(Listing::class, ['updated'], fn (Listing $listing) => [$listing->id, $listing->title]);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();

        $job->update(['name' => 'Oak Lodge']);
        $this->jobUpdatedReturns = true;
        $job->update(['name' => 'Oak Manor']);

        $this->assertSame([
            ['updated', 1, 'Oak Lodge'], [JobUpdated::class, 'Oak Lodge'],
            ['updated', 1, 'Oak Manor'], [JobUpdated::class, 'Oak Manor'],
        ], $this->seen);
    }

    #[Test]
    public function it_keeps_a_listener_named_in_without_forwarding_to_from_forwarded_events_when_a_wildcard_registers_it(): void
    {
        Event::listen('eloquent.updated: *', SyncListingToMls::class.'@every');
        Event::listen('eloquent.updated: *', [new SyncListingToMls, 'every']);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        DB::enableQueryLog();

        $job->update(['name' => 'Oak Lodge']);
        $queries = array_map(fn (array $query) => preg_replace('/^(update "\w+").*$/s', '$1', (string) $query['query']), DB::getQueryLog());
        DB::disableQueryLog();
        $this->listen(Listing::class, ['updated'], fn (Listing $listing) => [$listing->id]);
        $job->update(['name' => 'Oak Manor']);
        $forwarded = SyncListingToMls::$synced;
        Listing::query()->findOrFail(3)->update(['title' => 'Elm Lodge']);

        $this->assertSame(['update "jobs"'], $queries);
        $this->assertSame([[], [3, 3]], [$forwarded, SyncListingToMls::$synced]);
        $this->assertSame([['updated', 1], ['updated', 3]], $this->seen);
    }

    #[Test]
    public function it_forwards_a_job_write_on_another_connection_while_a_legacy_write_holds_the_same_key_on_this_one(): void
    {
        $default = DB::getDefaultConnection();
        $this->tenant = true;
        DB::statement('create schema tenant_b');
        config(['database.connections.tenant_b' => ['search_path' => 'tenant_b'] + (array) config("database.connections.{$default}")]);
        DB::setDefaultConnection('tenant_b');

        try {
            ListingTables::create();
            ListingTables::seed();
            Compatibility::prepare(Listing::class);
            ListingTables::backfill();
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
        } finally {
            DB::setDefaultConnection($default);
        }

        Event::listen('eloquent.saved: '.Job::class, function (Job $job): void {
            if ($job->getConnection()->getName() !== 'tenant_b') {
                Job::on('tenant_b')->whereKey(DB::connection('tenant_b')->table('listings_retained')->where(column: 'id', operator: '=', value: 1)->value('job_id'))->firstOrFail()->update(['name' => 'Oak Lodge']);
            }
        });
        $this->listen(Listing::class, ['saving', 'updating', 'updated', 'saved'], fn (Listing $listing) => [$listing->getConnection()->getName(), $listing->id, $listing->title]);

        Listing::query()->findOrFail(1)->update(['title' => 'Oak Cabin']);

        $this->assertSame([
            ['saving', $default, 1, 'Oak Cabin'], ['updating', $default, 1, 'Oak Cabin'],
            ['saving', 'tenant_b', 1, 'Oak Lodge'], ['updating', 'tenant_b', 1, 'Oak Lodge'], ['updated', 'tenant_b', 1, 'Oak Lodge'], ['saved', 'tenant_b', 1, 'Oak Lodge'],
            ['updated', $default, 1, 'Oak Cabin'], ['saved', $default, 1, 'Oak Cabin'],
        ], $this->seen);
        $this->assertSame(['Oak Cabin', 'Oak Lodge'], [Listing::query()->findOrFail(1)->title, Listing::on('tenant_b')->findOrFail(1)->title]);
    }

    #[Test]
    public function it_forwards_each_update_once_when_a_listener_of_the_event_object_of_a_job_update_increments_the_job(): void
    {
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $incremented = false;
        Event::listen(JobUpdated::class, function (JobUpdated $event) use (&$incremented): void {
            if (! $incremented) {
                $incremented = true;
                $event->job->increment('price_cents');
            }
        });
        $this->listen(Listing::class, ['updating', 'updated'], fn (Listing $listing) => [$listing->id, $listing->title, $listing->price]);

        $job->update(['name' => 'Oak Lodge']);

        $this->assertSame([
            ['updating', 1, 'Oak Lodge', '1250.50'], ['updated', 1, 'Oak Lodge', '1250.50'],
            ['updating', 1, 'Oak Lodge', '1250.51'], ['updated', 1, 'Oak Lodge', '1250.51'],
        ], $this->seen);
    }

    #[DefineEnvironment('listensForJobUpdatesFirst')]
    #[Test]
    public function it_forwards_a_job_update_whose_event_object_is_faked_after_a_listener_of_that_object_answered_an_earlier_update(): void
    {
        $this->listen(Listing::class, ['updated'], fn (Listing $listing) => [$listing->id, $listing->title]);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $this->jobUpdatedReturns = true;

        $job->update(['name' => 'Oak Lodge']);
        Event::fake([JobUpdated::class]);
        $job->update(['name' => 'Oak Manor']);

        $this->assertSame([['updated', 1, 'Oak Lodge'], [JobUpdated::class, 'Oak Lodge'], ['updated', 1, 'Oak Manor']], $this->seen);
    }

    #[Test]
    public function it_forwards_a_dependent_write_of_an_expression_to_a_column_the_listing_view_reads(): void
    {
        DB::statement('alter table places add column note text');
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->street]);
        $root = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();

        $root->update(['street' => DB::raw("'5 Oak St'")]);
        $root->update(['note' => DB::raw("'gate code 4411'")]);

        $this->assertSame([['saving', 1, '1 Oak St'], ['updating', 1, '1 Oak St'], ['updated', 1, '5 Oak St'], ['saved', 1, '5 Oak St']], $this->seen);
    }

    #[Test]
    public function it_gives_a_listing_listener_a_json_number_of_a_dependent_write_at_its_full_precision(): void
    {
        ListingTables::create();
        ListingTables::seed();
        DB::statement('alter table listings alter column city type jsonb using to_jsonb(city)');
        DB::statement('alter table places alter column city type jsonb using to_jsonb(city)');
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $this->listen(Listing::class, ['saving', 'saved'], fn (Listing $listing) => [$listing->id, $listing->city]);
        $root = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();

        $root->update(['city' => '{"n": 9223372036854775808123456789}']);

        $this->assertSame([['saving', 1, '{"n": 9223372036854775808123456789}'], ['saved', 1, '{"n": 9223372036854775808123456789}']], $this->seen);
    }

    #[Test]
    public function it_gives_a_listing_listener_the_json_null_a_dependent_write_stores_over_an_sql_null(): void
    {
        ListingTables::create();
        ListingTables::seed();
        DB::statement('alter table listings alter column city type jsonb using to_jsonb(city)');
        DB::statement('alter table places alter column city type jsonb using to_jsonb(city)');
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $this->listen(Listing::class, ['saving', 'updating', 'updated', 'saved'], fn (Listing $listing) => [$listing->id, $listing->getOriginal('city'), $listing->city]);
        $elm = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(3))->value('id'),
        )->firstOrFail();

        $elm->update(['city' => 'null']);

        $this->assertSame([['saving', 3, null, 'null'], ['updating', 3, null, 'null'], ['updated', 3, null, 'null'], ['saved', 3, null, 'null']], $this->seen);
        $this->assertSame('null', DB::table('places')->where('id', $elm->id)->value('city'));
    }

    #[Test]
    public function it_forwards_a_dependent_write_of_a_record_loaded_without_its_relationship_key_to_the_records_it_leaves_and_reaches(): void
    {
        $this->listen(Listing::class, ['updating', 'updated'], fn (Listing $listing) => [$listing->id, $listing->getOriginal('street'), $listing->street]);
        $oak = Place::query()->select(['id', 'street'])->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();
        $elm = Place::query()->select(['id', 'street'])->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(3))->value('id'),
        )->firstOrFail();

        $oak->update(['street' => '5 Oak St']);
        $elm->update(['job_id' => Job::withTrashed()->whereKey(ListingTables::jobOf(2))->value('id')]);

        $this->assertSame([
            ['updating', 1, '1 Oak St', '5 Oak St'], ['updated', 1, '1 Oak St', '5 Oak St'],
            ['updating', 2, null, '3 Elm St'], ['updating', 3, '3 Elm St', null],
            ['updated', 2, null, '3 Elm St'], ['updated', 3, '3 Elm St', null],
        ], $this->seen);
    }

    #[Test]
    public function it_forwards_the_after_events_of_a_dependent_write_whose_updating_listener_changes_a_value_the_listing_serves_after_the_forwarder_ran(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->getOriginal('city'), $listing->city]);
        Event::listen('eloquent.updating: '.Place::class, function (Place $place): void {
            $place->city = 'Tacoma';
        });

        Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail()->touch();

        $this->assertSame([['updated', 1, 'Seattle', 'Tacoma'], ['saved', 1, 'Seattle', 'Tacoma']], $this->seen);
    }

    #[Test]
    public function it_keeps_a_listener_named_in_without_forwarding_to_from_a_forwarded_event_object_without_dispatching_it_before_the_commit_or_skipping_its_broadcast(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $queue = Queue::fake();
        LegacyFileUpdated::$broadcasts = true;
        $seen = [];
        Event::listen(LegacyFileUpdated::class, ArchiveLegacyFile::class);
        Event::listen(LegacyFileUpdated::class, function (LegacyFileUpdated $event) use (&$seen): void {
            $seen[] = $event->legacyFile->file_name;
        });
        $file = fn (): File => File::query()->whereKey(FileTables::fileOf(table: 'legacy_files', id: 1))->firstOrFail();

        try {
            DB::transaction(function () use ($file): void {
                $file()->update(['filename' => 'rolled-back.pdf']);

                throw new RuntimeException('Roll the write back.');
            });
        } catch (RuntimeException) {
        }

        DB::transaction(function () use ($file, &$seen): void {
            $file()->update(['filename' => 'contract-2.pdf']);
            $seen[] = 'committing';
        });

        $this->assertSame([['committing', 'contract-2.pdf'], [], 1], [$seen, ArchiveLegacyFile::$archived, count($queue->pushed(BroadcastEvent::class))]);
    }

    #[Test]
    public function it_forwards_a_dependent_write_to_the_record_a_later_saving_listener_moves_it_to(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->street]);
        $pine = Job::withTrashed()->whereKey(ListingTables::jobOf(2))->value('id');
        Event::listen('eloquent.saving: '.Place::class, function (Place $place) use ($pine): void {
            $place->job_id = (string) $pine;
        });

        Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail()->touch();

        $this->assertSame([
            ['saving', 1, null], ['saving', 2, '1 Oak St'], ['updating', 1, null], ['updating', 2, '1 Oak St'],
            ['updated', 1, null], ['updated', 2, '1 Oak St'], ['saved', 1, null], ['saved', 2, '1 Oak St'],
        ], $this->seen);
    }

    #[Test]
    public function it_forwards_nothing_for_a_dependent_write_that_trips_a_hide_rule_over_the_dependent(): void
    {
        $withheld = $this->withheldListing();
        Forwarder::register($this->app->make('events'), $withheld::class);
        $this->listen($withheld::class, self::EVENTS, fn (Model $listing) => [$listing->getAttribute('id'), $listing->getAttribute('street'), $listing->getAttribute('city')]);
        Event::listen('eloquent.updating: '.Place::class, function (Place $place): void {
            if ($place->street === '7 Oak St') {
                $place->city = 'Withheld';
            }
        });
        $root = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();

        $root->update(['city' => 'Withheld']);
        $root->update(['city' => 'Seattle']);
        $root->update(['street' => '7 Oak St']);

        $this->assertSame([['saving', 1, '7 Oak St', 'Seattle'], ['updating', 1, '7 Oak St', 'Seattle']], $this->seen);
    }

    #[Test]
    public function it_raises_no_after_event_for_a_record_another_write_during_a_dependent_write_moves_out_of_the_legacy_shape(): void
    {
        $withheld = $this->withheldListing();
        Forwarder::register($this->app->make('events'), $withheld::class);
        $this->listen($withheld::class, self::EVENTS, fn (Model $listing) => [$listing->getAttribute('id'), $listing->getAttribute('street'), $listing->getAttribute('city')]);
        $root = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();
        Event::listen('eloquent.updating: '.Place::class, function (Place $place) use ($root): void {
            DB::table('places')->where('id', $root->id)->update(['city' => 'Withheld']);
        });
        $root->timestamps = false;

        $root->update(['street' => '8 Oak St']);

        $this->assertSame([['saving', 1, '8 Oak St', 'Seattle'], ['updating', 1, '8 Oak St', 'Seattle']], $this->seen);
    }

    #[Test]
    public function it_gives_the_saved_event_of_a_job_write_the_values_an_updated_listener_saves_quietly_to_the_same_instance(): void
    {
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        Event::listen('eloquent.updated: '.Job::class, function (Job $updated): void {
            $updated->price_cents = (int) $updated->price_cents + 5;
            $updated->saveQuietly();
        });
        $this->listen(Listing::class, ['updated', 'saved'], fn (Listing $listing) => [$listing->id, $listing->title, $listing->price]);

        $job->update(['name' => 'Oak Lodge']);

        $this->assertSame([['updated', 1, 'Oak Lodge', '1250.50'], ['saved', 1, 'Oak Lodge', '1250.55']], $this->seen);
        $this->assertSame('1250.55', Listing::query()->findOrFail(1)->price);
    }

    #[RequiresMethod(Job::class, 'softDeleted')]
    #[Test]
    public function it_gives_the_trashed_and_deleted_events_of_a_soft_deleted_job_the_synchronized_timestamp_originals_a_native_soft_delete_gives(): void
    {
        $this->listen(Listing::class, ['trashed', 'deleted'], fn (Listing $listing) => [
            $listing->deleted_at !== null,
            $listing->getRawOriginal('deleted_at') === $listing->getAttributes()['deleted_at'],
            $listing->getRawOriginal('updated_at') === $listing->getAttributes()['updated_at'],
            $listing->isDirty(['deleted_at', 'updated_at']),
        ]);

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->delete();

        $this->assertSame([['trashed', true, true, true, false], ['deleted', true, true, true, false]], $this->seen);
    }

    #[Test]
    public function it_gives_the_after_events_of_a_successor_write_the_external_fact_it_held_before_the_write_as_the_original(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $this->listen(Photo::class, ['updated'], fn (Photo $photo) => [$photo->mime_type, $photo->getOriginal('mime_type'), $photo->wasChanged('mime_type')]);
        $file = File::query()->whereKey(FileTables::fileOf(table: 'photos', id: 1))->firstOrFail();

        $file->update(['zml_asset_id' => FakeMediaLibrary::bound()->store(new FileMetadata('image/webp'))]);

        $this->assertSame([['updated', 'image/webp', 'image/jpeg', true]], $this->seen);
    }

    #[Test]
    public function it_forwards_a_dependent_write_of_an_expression_to_its_relationship_key_to_the_records_it_leaves_and_reaches(): void
    {
        $this->listen(Listing::class, ['updating', 'updated'], fn (Listing $listing) => [$listing->id, $listing->getOriginal('street'), $listing->street]);
        $root = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();

        $root->update(['job_id' => DB::raw('job_id')]);
        $root->update(['job_id' => DB::raw('(select job_id from listings_retained where id = 2)')]);

        $this->assertSame([
            ['updating', 1, '1 Oak St', '1 Oak St'], ['updated', 1, '1 Oak St', '1 Oak St'],
            ['updating', 1, '1 Oak St', '1 Oak St'], ['updating', 2, null, null],
            ['updated', 1, '1 Oak St', null], ['updated', 2, null, '1 Oak St'],
        ], $this->seen);
    }

    #[Test]
    public function it_forwards_no_write_a_job_saved_listener_makes_to_the_record_a_legacy_write_moves_to_another_key(): void
    {
        Event::listen('eloquent.saved: '.Job::class, function (Job $job): void {
            Place::query()->where('job_id', $job->id)->firstOrFail()->update(['city' => 'Tacoma']);
        });
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);
        DB::table('listing_photos')->where('listing_id', 1)->delete();

        Listing::query()->findOrFail(1)->update(['id' => 99]);

        $this->assertSame([['saving', 99], ['updating', 99], ['updated', 99], ['saved', 99]], $this->seen);
        $this->assertSame('Tacoma', Listing::query()->findOrFail(99)->city);
    }

    #[Test]
    public function it_refuses_a_change_a_legacy_listener_makes_to_a_fact_of_a_dependent_row_the_write_moves_away_from_its_record(): void
    {
        $assigns = 3;
        Event::listen('eloquent.updating: '.Listing::class, function (Listing $listing) use (&$assigns): void {
            if ($listing->id === $assigns) {
                $listing->street = '9 Elm St';
            }
        });
        $elm = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(3))->value('id'),
        )->firstOrFail();
        $pine = (string) Job::withTrashed()->whereKey(ListingTables::jobOf(2))->value('id');

        try {
            $elm->update(['job_id' => $pine]);
            $refused = null;
        } catch (CompatibilityWriteRefused $refusal) {
            $refused = $refusal->getMessage();
        }

        $unmoved = [Place::query()->findOrFail($elm->id)->job_id, Place::query()->findOrFail($elm->id)->street];
        $assigns = 2;
        Place::query()->findOrFail($elm->id)->update(['job_id' => $pine]);

        $this->assertSame('A listener of a '.Listing::class.' event that Place forwarded cannot change $street. Why: The written Place no longer serves this Listing, so the forwarded write cannot store it there. Instead: Write street through Listing, or change Place or the model that stores street directly.', $refused);
        $this->assertSame([(string) Job::query()->whereKey(ListingTables::jobOf(3))->value('id'), '3 Elm St'], $unmoved);
        $this->assertSame(['9 Elm St', null], [Listing::withTrashed()->findOrFail(2)->street, Listing::query()->findOrFail(3)->street]);
    }

    #[Test]
    public function it_forwards_a_write_of_a_root_loaded_with_some_columns_to_the_legacy_model_whose_retained_table_holds_it(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $this->listen(Photo::class, ['updated'], fn (Photo $photo) => [$photo->id, $photo->file_name]);
        $file = File::query()->select(['id', 'filename'])->whereKey(FileTables::fileOf(table: 'photos', id: 1))->firstOrFail();

        $file->fill(['filename' => 'oak-porch.jpg'])->save();

        $this->assertSame([['updated', 1, 'oak-porch.jpg']], $this->seen);
    }

    #[Test]
    public function it_raises_the_restored_event_of_a_job_restore_a_listing_saving_or_updating_listener_refuses_as_a_native_listing_restore_does(): void
    {
        /** @var list<string> $refuses */
        $refuses = [];
        Event::listen('eloquent.saving: '.Listing::class, function () use (&$refuses): null|false {
            return in_array('saving', $refuses, true) ? false : null;
        });
        Event::listen('eloquent.updating: '.Listing::class, function () use (&$refuses): null|false {
            return in_array('updating', $refuses, true) ? false : null;
        });
        $this->listen(Listing::class, ['restoring', 'saving', 'updating', 'restored'], fn (Listing $listing) => [$listing->id, $listing->deleted_at === null, $listing->isDirty('deleted_at'), $listing->exists]);
        $native = [];
        $forwarded = [];

        foreach (['saving', 'updating'] as $event) {
            $refuses = [$event];
            $this->seen = [];
            $native[$event] = [Listing::withTrashed()->findOrFail(2)->restore()]; // @phpstan-ignore staticMethod.dynamicCall
            $native[$event][] = $this->seen;
            $this->seen = [];
            $forwarded[$event] = [Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail()->restore(), $this->seen]; // @phpstan-ignore staticMethod.dynamicCall
        }

        $this->assertSame($native, $forwarded);
        $this->assertTrue(Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail()->trashed()); // @phpstan-ignore staticMethod.dynamicCall
    }

    #[Test]
    public function it_keeps_a_listener_named_in_without_forwarding_to_and_registered_on_an_interface_of_a_forwarded_event_object_from_that_object(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $seen = [];
        Event::listen(LegacyFileUpdated::class, function (LegacyFileUpdated $event) use (&$seen): void {
            $seen[] = $event->legacyFile->file_name;
        });
        Event::listen(ShouldDispatchAfterCommit::class, ArchiveLegacyFile::class);
        Event::listen(ShouldDispatchAfterCommit::class, function (object $event) use (&$seen): void {
            $seen[] = $event instanceof LegacyFileUpdated ? 'interface' : $event::class;
        });

        File::query()->whereKey(FileTables::fileOf(table: 'legacy_files', id: 1))->firstOrFail()->update(['filename' => 'contract-2.pdf']);

        $this->assertSame([['contract-2.pdf', 'interface'], []], [$seen, ArchiveLegacyFile::$archived]);
    }

    #[Test]
    public function it_evaluates_an_expression_on_a_dependent_relationship_key_once_and_forwards_the_write_to_the_record_it_stores(): void
    {
        $pine = Job::withTrashed()->whereKey(ListingTables::jobOf(2))->value('id'); // @phpstan-ignore staticMethod.dynamicCall
        DB::statement('create temporary sequence forwarding_probe');
        $this->listen(Listing::class, ['updating', 'updated'], fn (Listing $listing) => [$listing->id, $listing->getOriginal('street'), $listing->street]);
        $root = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();

        try {
            $root->update(['job_id' => DB::raw("(select job_id from listings_retained order by id offset nextval('forwarding_probe') limit 1)")]);
            $probe = DB::scalar('select last_value from forwarding_probe');
        } finally {
            DB::statement('drop sequence forwarding_probe');
        }

        $this->assertSame([
            ['updating', 1, '1 Oak St', '1 Oak St'], ['updating', 2, null, null],
            ['updated', 1, '1 Oak St', null], ['updated', 2, null, '1 Oak St'],
        ], $this->seen);
        $this->assertSame([1, $pine], [$probe, Place::query()->findOrFail($root->id)->job_id]);
    }

    #[Test]
    public function it_reads_the_relationship_key_a_dependent_was_loaded_without_from_the_write_connection(): void
    {
        $root = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();
        DB::statement('create schema compatibility_replica');
        DB::statement('create table compatibility_replica.places as table public.places');

        foreach (['jobs', 'job_sites', 'listings_retained', 'listings'] as $table) {
            DB::statement("create view compatibility_replica.{$table} as select * from public.{$table}");
        }

        DB::table('compatibility_replica.places')->where(
            column: 'id',
            operator: '=',
            value: $root->id,
        )->update(['job_id' => Job::query()->whereKey(ListingTables::jobOf(3))->value('id')]);
        config(['database.connections.compatibility_replicated' => [...(array) config('database.connections.'.DB::getDefaultConnection()), 'read' => ['search_path' => 'compatibility_replica'], 'write' => [], 'sticky' => false]]);
        $this->listen(Listing::class, ['saving', 'updated'], fn (Listing $listing) => [$listing->id, $listing->street]);

        try {
            Place::on('compatibility_replicated')->useWritePdo()->select(['id', 'street'])->whereKey($root->id)->firstOrFail()->update(['street' => '2 Oak St']); // @phpstan-ignore staticMethod.dynamicCall
        } finally {
            DB::purge('compatibility_replicated');
            DB::statement('drop schema compatibility_replica cascade');
        }

        $this->assertSame([['saving', 1, '2 Oak St'], ['updated', 1, '2 Oak St']], $this->seen);
    }

    #[Test]
    public function it_forwards_no_write_a_job_created_listener_makes_through_another_instance_to_the_record_a_legacy_create_retries_after_a_rollback(): void
    {
        $fails = true;
        Event::listen('eloquent.created: '.Job::class, function (Job $job): void {
            Job::query()->findOrFail($job->id)->update(['price_cents' => 7]);
        });
        Event::listen('eloquent.saved: '.Job::class, function () use (&$fails): void {
            if ($fails) {
                $fails = false;

                throw new RuntimeException('Roll the create back.');
            }
        });
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);
        $listing = new Listing(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        try {
            $listing->save();
        } catch (RuntimeException) {
        }

        $listing->save();

        $this->assertSame([['saving', null], ['creating', null], ['saving', null], ['creating', null], ['created', $listing->id], ['saved', $listing->id]], $this->seen);
        $this->assertSame('0.07', Listing::query()->findOrFail($listing->id)->price);
    }

    #[Test]
    public function it_gives_the_restored_event_of_a_job_restore_the_synchronized_originals_a_native_listing_restore_gives(): void
    {
        $this->listen(Listing::class, ['restored'], fn (Listing $listing) => [$listing->getOriginal('deleted_at') === null, $listing->isDirty(), $listing->wasChanged('deleted_at')]);

        Listing::withTrashed()->findOrFail(2)->restore(); // @phpstan-ignore staticMethod.dynamicCall
        $native = $this->seen;
        Listing::query()->findOrFail(2)->delete();
        $this->seen = [];
        Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail()->restore(); // @phpstan-ignore staticMethod.dynamicCall

        $this->assertSame([['restored', true, false, true]], $native);
        $this->assertSame($native, $this->seen);
    }

    #[Test]
    public function it_forwards_the_after_events_of_a_dependent_insert_without_timestamps_whose_served_value_comes_from_a_database_default(): void
    {
        DB::statement("alter table places alter column street set default '2 Pine St'");
        $this->listen(Listing::class, ['saving', 'updating', 'updated', 'saved'], fn (Listing $listing) => [$listing->id, $listing->getOriginal('street'), $listing->street]);
        $place = new Place(['job_id' => Job::withTrashed()->whereKey(ListingTables::jobOf(2))->value('id')]); // @phpstan-ignore staticMethod.dynamicCall
        $place->timestamps = false;

        $place->save();

        $this->assertSame([['updated', 2, null, '2 Pine St'], ['saved', 2, null, '2 Pine St']], $this->seen);
    }

    #[Test]
    public function it_lets_only_the_listing_a_dependent_moves_to_through_an_expression_on_its_relationship_key_change_its_facts(): void
    {
        $assigns = 1;
        Event::listen('eloquent.updating: '.Listing::class, function (Listing $listing) use (&$assigns): void {
            if ($listing->id === $assigns) {
                $listing->street = '9 Pine St';
            }
        });
        $root = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();

        try {
            $root->update(['job_id' => DB::raw('(select job_id from listings_retained where id = 2)')]);
            $refused = null;
        } catch (CompatibilityWriteRefused $refusal) {
            $refused = $refusal->getMessage();
        }

        $unmoved = [Place::query()->findOrFail($root->id)->job_id, Place::query()->findOrFail($root->id)->street];
        $assigns = 2;
        Place::query()->findOrFail($root->id)->update(['job_id' => DB::raw('(select job_id from listings_retained where id = 2)')]);

        $this->assertSame('A listener of a '.Listing::class.' event that Place forwarded cannot change $street. Why: The written Place no longer serves this Listing, so the forwarded write cannot store it there. Instead: Write street through Listing, or change Place or the model that stores street directly.', $refused);
        $this->assertSame([(string) Job::query()->whereKey(ListingTables::jobOf(1))->value('id'), '1 Oak St'], $unmoved);
        $this->assertSame(['9 Pine St', null], [Listing::withTrashed()->findOrFail(2)->street, Listing::query()->findOrFail(1)->street]); // @phpstan-ignore staticMethod.dynamicCall
    }

    #[Test]
    public function it_forwards_the_after_events_of_a_dependent_write_without_timestamps_that_changes_the_input_of_a_stored_generated_column_the_listing_serves(): void
    {
        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        CompletedPass::recordHandBackfill(model: Listing::class);
        DB::statement('alter table places add column town text');
        DB::statement('update places set town = city');
        DB::statement('alter table places drop column city');
        DB::statement('alter table places add column city text generated always as (town) stored');
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $this->listen(Listing::class, ['saving', 'updating', 'updated', 'saved'], fn (Listing $listing) => [$listing->id, $listing->getOriginal('city'), $listing->city]);
        $root = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();
        $root->timestamps = false;

        $root->update(['town' => 'Tacoma']);

        $this->assertSame([['updated', 1, 'Seattle', 'Tacoma'], ['saved', 1, 'Seattle', 'Tacoma']], $this->seen);
    }

    #[Test]
    public function it_forwards_nothing_for_a_job_write_that_trips_a_hide_rule_over_its_sibling_jobs(): void
    {
        $outpriced = $this->outpricedListing();
        Forwarder::register($this->app->make('events'), $outpriced::class);
        $this->listen($outpriced::class, self::EVENTS, fn (Model $listing) => [$listing->getAttribute('id'), $listing->getAttribute('price')]);

        Job::query()->whereKey(ListingTables::jobOf(3))->firstOrFail()->update(['price_cents' => 1000]);
        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);

        $this->assertSame([['saving', 1, '1250.50'], ['updating', 1, '1250.50'], ['updated', 1, '1250.50'], ['saved', 1, '1250.50']], $this->seen);
    }

    #[Test]
    public function it_gives_a_listener_after_the_commit_of_a_forwarded_save_or_dependent_delete_the_synchronized_originals_a_native_save_gives(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        DB::connection()->setTransactionManager($this->app->make('db.transactions'));
        $files = [];
        Event::listen(LegacyFileUpdated::class, function (LegacyFileUpdated $event) use (&$files): void {
            $files[] = [$event->legacyFile->getOriginal('file_name'), $event->legacyFile->isDirty('file_name'), $event->legacyFile->wasChanged('file_name')];
        });
        $listings = [];
        Event::listen('eloquent.saved: '.Listing::class, function (Listing $listing) use (&$listings): void {
            $listings[] = $listing;
        });

        DB::transaction(fn () => LegacyFile::query()->findOrFail(1)->update(['file_name' => 'contract-2.pdf']));
        DB::transaction(fn () => File::query()->whereKey(FileTables::fileOf(table: 'legacy_files', id: 1))->firstOrFail()->update(['filename' => 'contract-3.pdf']));
        DB::transaction(fn () => Listing::query()->findOrFail(3)->update(['street' => '4 Elm St']));
        DB::transaction(fn () => Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail()->delete());

        $this->assertSame([['contract-2.pdf', false, true], ['contract-3.pdf', false, true]], $files);
        $this->assertSame([[3, '4 Elm St', false, true], [1, null, false, true]], array_map(fn (Listing $listing) => [$listing->id, $listing->getOriginal('street'), $listing->isDirty('street'), $listing->wasChanged('street')], $listings));
    }

    #[Test]
    public function it_forwards_a_job_write_to_the_listing_a_later_job_listener_keeps_in_the_legacy_shape(): void
    {
        $elm = Job::query()->whereKey(ListingTables::jobOf(3))->value('id');
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->getOriginal('title'), $listing->title]);
        $resets = 'saving';

        foreach (['saving', 'updating'] as $event) {
            Event::listen("eloquent.{$event}: ".Job::class, function (Job $job) use ($event, &$resets): void {
                if ($event === $resets) {
                    $job->merged_into_job_id = null;
                }
            });
        }

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['merged_into_job_id' => $elm, 'name' => 'Oak Lodge']);
        $resets = 'updating';
        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['merged_into_job_id' => $elm, 'name' => 'Oak Cabin']);

        $this->assertSame([
            ['saving', 1, 'Oak House', 'Oak Lodge'], ['updating', 1, 'Oak House', 'Oak Lodge'], ['updated', 1, 'Oak House', 'Oak Lodge'], ['saved', 1, 'Oak House', 'Oak Lodge'],
            ['updated', 1, 'Oak Lodge', 'Oak Cabin'], ['saved', 1, 'Oak Lodge', 'Oak Cabin'],
        ], $this->seen);
        $this->assertSame([null, 'Oak Cabin'], [Job::query()->whereKey(ListingTables::jobOf(1))->value('merged_into_job_id'), Listing::query()->findOrFail(1)->title]);
    }

    #[Test]
    public function it_forwards_a_dependent_write_to_the_listing_a_later_place_listener_keeps_visible_and_never_to_one_hidden_before_the_write(): void
    {
        $withheld = $this->withheldListing();
        Forwarder::register($this->app->make('events'), $withheld::class);
        $this->listen($withheld::class, self::EVENTS, fn (Model $listing) => [$listing->getAttribute('id'), $listing->getOriginal('street'), $listing->getAttribute('street'), $listing->getAttribute('city')]);
        $restores = 'saving';

        foreach (['saving', 'updating'] as $event) {
            Event::listen("eloquent.{$event}: ".Place::class, function (Place $place) use ($event, &$restores): void {
                if ($event === $restores) {
                    $place->city = 'Seattle';
                }
            });
        }

        $root = Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail();

        $root->update(['city' => 'Withheld', 'street' => '7 Oak St']);
        $restores = 'updating';
        $root->update(['city' => 'Withheld', 'street' => '8 Oak St']);
        $restores = null;
        $root->update(['city' => 'Withheld', 'street' => '9 Oak St']);
        $root->update(['city' => 'Seattle', 'street' => '10 Oak St']);

        $this->assertSame([
            ['saving', 1, '1 Oak St', '7 Oak St', 'Seattle'], ['updating', 1, '1 Oak St', '7 Oak St', 'Seattle'], ['updated', 1, '1 Oak St', '7 Oak St', 'Seattle'], ['saved', 1, '1 Oak St', '7 Oak St', 'Seattle'],
            ['updated', 1, '7 Oak St', '8 Oak St', 'Seattle'], ['saved', 1, '7 Oak St', '8 Oak St', 'Seattle'],
        ], $this->seen);
        $this->assertSame(['10 Oak St', 'Seattle'], [Place::query()->findOrFail($root->id)->street, Place::query()->findOrFail($root->id)->city]);
    }

    #[Test]
    public function it_lets_a_listing_listener_refuse_an_expression_write_only_of_a_dependent_row_that_serves_the_listing(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->street]);
        $oak = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $root = Place::query()->where('job_id', $oak->id)->firstOrFail();
        $unit = Place::query()->create(['job_id' => $oak->id, 'parent_id' => $root->id, 'street' => 'Unit 2']);
        Event::listen('eloquent.saving: '.Listing::class, fn (Listing $listing) => false);
        $this->seen = [];

        $saved = $unit->update(['street' => DB::raw("'Unit 3'")]);
        $refused = $root->update(['street' => DB::raw("'5 Oak St'")]);

        $this->assertSame([['saving', 1, '1 Oak St']], $this->seen);
        $this->assertSame([true, false, 'Unit 3', '1 Oak St'], [$saved, $refused, Place::query()->findOrFail($unit->id)->street, Place::query()->findOrFail($root->id)->street]);
    }

    #[Test]
    public function it_forwards_a_job_update_once_to_a_listing_that_shares_its_event_object_class_and_still_forwards_a_job_write_a_listener_of_that_object_makes(): void
    {
        $job = new class extends Model
        {
            public $incrementing = false;

            protected $table = 'jobs';

            protected $keyType = 'string';

            protected $guarded = [];

            protected $dispatchesEvents = ['updated' => RecordUpdated::class];
        };
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public static string $successor = '';

            protected $table = 'listings';

            protected $guarded = [];

            protected $dispatchesEvents = ['updated' => RecordUpdated::class];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: self::$successor, key: 'job_id', successor: fn (Successor $successor) => $successor
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        ));
            }
        };
        $listing::$successor = $job::class;
        Forwarder::register($this->app->make('events'), $listing::class);
        $seen = [];
        Event::listen(RecordUpdated::class, function (RecordUpdated $event) use ($job, &$seen): void {
            $successor = $event->model instanceof $job;
            $seen[] = [$successor ? 'job' : 'listing', $event->model->getAttribute($successor ? 'name' : 'title')];

            if (! $successor && $event->model->getAttribute('id') === 1) {
                $job::query()->whereKey(ListingTables::jobOf(3))->firstOrFail()->update(['name' => 'Elm Lodge']);
            }
        });

        $job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);

        $this->assertSame([['listing', 'Oak Lodge'], ['listing', 'Elm Lodge'], ['job', 'Elm Lodge'], ['job', 'Oak Lodge']], $seen);
        $this->assertSame(4, RecordUpdated::$constructed);
    }

    #[Test]
    public function it_gives_a_listener_after_the_commit_of_a_forwarded_increment_the_synchronized_originals_a_native_increment_gives(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        DB::connection()->setTransactionManager($this->app->make('db.transactions'));
        $during = [];
        $after = [];
        Event::listen('eloquent.updated: '.Listing::class, function (Listing $listing) use (&$during, &$after): void {
            $during[] = [$listing->getOriginal('price'), $listing->price, $listing->isDirty('price'), $listing->wasChanged('price')];
            DB::afterCommit(function () use ($listing, &$after): void {
                $after[] = [$listing->getOriginal('price'), $listing->price, $listing->isDirty('price'), $listing->wasChanged('price')];
            });
        });
        $files = [];
        Event::listen(LegacyFileUpdated::class, function (LegacyFileUpdated $event) use (&$files): void {
            $files[] = [$event->legacyFile->getOriginal('file_name'), $event->legacyFile->isDirty('file_name'), $event->legacyFile->wasChanged('file_name')];
        });

        DB::transaction(fn () => Listing::query()->findOrFail(1)->increment('price', 5));
        DB::transaction(fn () => Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->increment(
            column: 'price_cents',
            amount: 500,
        ));
        DB::transaction(fn () => Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->decrement(
            column: 'price_cents',
            amount: 1000,
        ));
        DB::transaction(fn () => File::query()->whereKey(FileTables::fileOf(table: 'legacy_files', id: 1))->firstOrFail()->increment(
            column: 'size_bytes',
            amount: 1,
            extra: ['filename' => 'contract-4.pdf'],
        ));

        $this->assertSame([
            ['1250.50', '1255.50', true, true], ['1255.50', '1260.50', true, true], ['1260.50', '1250.50', true, true],
        ], $during);
        $this->assertSame([
            ['1255.50', '1255.50', false, true], ['1260.50', '1260.50', false, true], ['1250.50', '1250.50', false, true],
        ], $after);
        $this->assertSame([['contract-4.pdf', false, true]], $files);
    }

    #[RequiresMethod(Model::class, 'incrementEach')]
    #[Test]
    public function it_gives_a_listener_of_a_forwarded_increment_of_each_column_the_synchronized_originals_of_its_extra_values(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        DB::connection()->setTransactionManager($this->app->make('db.transactions'));
        $files = [];
        Event::listen(LegacyFileUpdated::class, function (LegacyFileUpdated $event) use (&$files): void {
            $files[] = [$event->legacyFile->getOriginal('file_name'), $event->legacyFile->isDirty('file_name'), $event->legacyFile->wasChanged('file_name')];
        });

        DB::transaction(fn () => File::query()->whereKey(FileTables::fileOf(table: 'legacy_files', id: 1))->firstOrFail()->incrementEach(
            columns: ['size_bytes' => 1],
            extra: ['filename' => 'contract-4.pdf'],
        ));
        DB::transaction(fn () => File::query()->whereKey(FileTables::fileOf(table: 'legacy_files', id: 1))->firstOrFail()->decrementEach(
            columns: ['size_bytes' => 1],
            extra: ['filename' => 'contract-5.pdf'],
        ));

        $this->assertSame([['contract-4.pdf', false, true], ['contract-5.pdf', false, true]], $files);
    }

    #[Test]
    public function it_forwards_a_job_write_to_a_listing_that_reads_a_column_of_the_retained_table_through_an_expression(): void
    {
        $retained = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'mls_number',
                            definition: fn (Attribute $attribute) => $attribute->readAs('"listings_retained"."mls_number"')->readOnly(because: 'The legacy table keeps the MLS number.', ignoreWrites: false),
                        ));
            }
        };
        Forwarder::register($this->app->make('events'), $retained::class);
        $this->listen($retained::class, self::EVENTS, fn (Model $listing) => [$listing->getAttribute('id'), $listing->getAttribute('title'), $listing->getAttribute('mls_number')]);

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);

        $this->assertSame([
            ['saving', 1, 'Oak Lodge', 'MLS-1'], ['updating', 1, 'Oak Lodge', 'MLS-1'], ['updated', 1, 'Oak Lodge', 'MLS-1'], ['saved', 1, 'Oak Lodge', 'MLS-1'],
        ], $this->seen);
        $this->assertSame('Oak Lodge', Job::query()->whereKey(ListingTables::jobOf(1))->value('name'));
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array // @phpstan-ignore ergebnis.privateInFinalClass
    {
        return [WritesWhileBooting::class, ...parent::getPackageProviders($app)];
    }

    /**
     * @param  Application  $app
     */
    protected function writesWhileBooting($app): void // @phpstan-ignore ergebnis.privateInFinalClass
    {
        if ($this->writesWhileBooting === null) {
            return;
        }

        [$connection, $database] = $this->writesWhileBooting;
        $app['config']->set("database.connections.{$connection}.database", $database);
        WritesWhileBooting::$writes = function (): void {
            $this->listen(Listing::class, ['updated'], fn (Listing $listing) => [$listing->id, $listing->title]);
            Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);
        };
    }

    /**
     * @param  Application  $app
     */
    protected function listensForJobUpdatesFirst($app): void // @phpstan-ignore ergebnis.privateInFinalClass
    {
        $app['events']->listen(JobUpdated::class, function (JobUpdated $event): null|bool {
            $this->seen[] = [JobUpdated::class, $event->job->name];

            return $this->jobUpdatedReturns;
        });
    }

    /**
     * @param  Application  $app
     */
    protected function servesOutsideTestingFromACache($app): void // @phpstan-ignore ergebnis.privateInFinalClass
    {
        $this->bootstrap = $this->temporaryBootstrap();
        file_put_contents("{$this->bootstrap}/cache/compatibility.php", '<?php return '.var_export([Listing::class, 'Tests\Fixtures\Support\Listings\RemovedListing'], true).';');
        $app->useBootstrapPath($this->bootstrap);
        $app->detectEnvironment(fn () => 'staging');
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  list<string>  $events
     * @param  Closure(TModel): list<mixed>  $describe
     */
    private function listen(string $model, array $events, Closure $describe): void
    {
        foreach ($events as $event) {
            Event::listen("eloquent.{$event}: {$model}", function (Model $legacy) use ($model, $event, $describe): void {
                $this->seen[] = $legacy instanceof $model ? [$event, ...$describe($legacy)] : [$event, $legacy::class];
            });
        }
    }

    /**
     * @param  list<class-string<Model>>  $models
     */
    private function servedOnly(array $models): ServedModels
    {
        $files = new Filesystem;
        $cache = (string) tempnam(
            directory: sys_get_temp_dir(),
            prefix: 'compatibility-served-',
        );
        $files->put(
            path: $cache,
            contents: '<?php return '.var_export(
                value: $models,
                return: true,
            ).';',
        );
        $served = new ServedModels(
            cache: $cache,
            scans: false,
            withoutCache: false,
            files: $files,
        );
        $served->all();
        $files->delete($cache);

        return $served;
    }

    private function withheldListing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;
            use SoftDeletes;

            protected $table = 'listings';

            protected $guarded = [];

            protected function casts(): array
            {
                return ['price' => 'decimal:2', 'status' => ListingStatus::class, 'latitude' => 'float'];
            }

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
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: false),
                        )
                        ->attribute(
                            name: 'price',
                            definition: fn (Attribute $attribute) => $attribute->from('price_cents')->dividedBy(100),
                        )
                        ->attribute(
                            name: 'status',
                            definition: fn (Attribute $attribute) => $attribute
                                ->from('lifecycle_status')
                                ->mapping([LifecycleStatus::Drafting->value => ListingStatus::Draft, LifecycleStatus::Live->value => ListingStatus::Active, LifecycleStatus::Closed->value => ListingStatus::Sold])
                                ->writtenBy(TransitionListingStatus::class),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->rootPlace(),
                            successor: fn (Successor $place) => $place
                                ->atMostOne(because: 'A listing has one address.')
                                ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                                ->attribute('city', fn (Attribute $attribute) => $attribute->from('city')),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->site(),
                            successor: fn (Successor $site) => $site
                                ->attribute('latitude', fn (Attribute $attribute) => $attribute->from('latitude')),
                        ))
                    ->hide(fn (Builder $jobs) => $jobs->whereNotNull('merged_into_job_id'), because: 'A merged job lives on as the job it merged into.')
                    ->hide(fn (Builder $jobs) => $jobs->whereHas('rootPlace', fn (Builder $places) => $places->where('city', 'Withheld')), because: 'A withheld address keeps its listing off the site.');
            }
        };
    }

    private function outpricedListing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;
            use SoftDeletes;

            protected $table = 'listings';

            protected $guarded = [];

            protected function casts(): array
            {
                return ['price' => 'decimal:2', 'status' => ListingStatus::class, 'latitude' => 'float'];
            }

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
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: false),
                        )
                        ->attribute(
                            name: 'price',
                            definition: fn (Attribute $attribute) => $attribute->from('price_cents')->dividedBy(100),
                        )
                        ->attribute(
                            name: 'status',
                            definition: fn (Attribute $attribute) => $attribute
                                ->from('lifecycle_status')
                                ->mapping([LifecycleStatus::Drafting->value => ListingStatus::Draft, LifecycleStatus::Live->value => ListingStatus::Active, LifecycleStatus::Closed->value => ListingStatus::Sold])
                                ->writtenBy(TransitionListingStatus::class),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->rootPlace(),
                            successor: fn (Successor $place) => $place
                                ->atMostOne(because: 'A listing has one address.')
                                ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                                ->attribute('city', fn (Attribute $attribute) => $attribute->from('city')),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->site(),
                            successor: fn (Successor $site) => $site
                                ->attribute('latitude', fn (Attribute $attribute) => $attribute->from('latitude')),
                        ))
                    ->hide(fn (Builder $jobs) => $jobs->whereNotNull('merged_into_job_id'), because: 'A merged job lives on as the job it merged into.')
                    ->hide(fn (Builder $jobs) => $jobs->whereRaw('exists (select 1 from jobs as siblings where siblings.company_id = jobs.company_id and siblings.price_cents > jobs.price_cents * 100)'), because: 'A job priced under a hundredth of a sibling job is a placeholder.');
            }
        };
    }

    private function temporaryBootstrap(): string
    {
        $bootstrap = sys_get_temp_dir().'/compatibility-'.bin2hex(random_bytes(8));
        (new Filesystem)->ensureDirectoryExists("{$bootstrap}/cache");

        return $bootstrap;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach (['jobs' => ListingTables::legacyId(), 'places' => 'street', 'job_sites' => 'latitude', 'listings_retained' => 'mls_number'] as $table => $order) {
            $snapshot[$table] = array_map(fn (object $row) => (array) $row, DB::table($table)->orderBy($order)->get()->all());
        }

        return $snapshot;
    }
}
