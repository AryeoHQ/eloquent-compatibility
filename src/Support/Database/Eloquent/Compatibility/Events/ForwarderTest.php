<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provider;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\TestCase;

#[CoversClass(Forwarder::class)]
#[CoversClass(Provider::class)]
final class ForwarderTest extends TestCase
{
    private const array EVENTS = ['saving', 'creating', 'created', 'updating', 'updated', 'saved', 'deleting', 'deleted', 'restoring', 'restored', 'forceDeleting', 'forceDeleted'];

    /** @var list<string> */
    protected $connectionsToTransact = [];

    /** @var list<array<int, mixed>> */
    private array $seen = [];

    private null|string $bootstrap = null;

    private bool $discovered = true;

    protected function setUp(): void
    {
        parent::setUp();

        SyncListingToMls::$synced = [];
        ListingTables::create();

        if ($this->discovered) {
            ListingTables::seed();
            Compatibility::prepare(Listing::class);
            ListingTables::backfill();
            Compatibility::install(Listing::class);
        }
    }

    protected function tearDown(): void
    {
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

        $job = Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]);
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
    }

    #[Test]
    public function it_runs_no_query_of_its_own_when_no_listing_listener_takes_the_event(): void
    {
        Event::listen('eloquent.updated: '.Listing::class, SyncListingToMls::class);
        $job = Job::query()->where('legacy_id', 1)->firstOrFail();
        $site = JobSite::query()->where('job_id', $job->id)->firstOrFail();
        DB::enableQueryLog();

        $job->update(['name' => 'Oak Lodge']);
        $site->update(['latitude' => 1.5]);
        Place::query()->create(['job_id' => Job::withTrashed()->where('legacy_id', 2)->value('id'), 'street' => '2 Pine St']);

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
            File::query()->where('legacy_type', 'photo')->where('legacy_id', 1)->firstOrFail()->update(['filename' => 'oak-porch.jpg']);
        });
        $this->listen(Photo::class, ['updated'], fn (Photo $photo) => [$photo->id, $photo->file_name]);

        Listing::query()->findOrFail(1)->update(['title' => 'Oak Lodge']);

        $this->assertSame([['updated', 1, 'oak-porch.jpg']], $this->seen);
    }

    #[Test]
    public function it_gives_every_event_of_one_successor_write_the_same_listing_instance(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [spl_object_id($listing)]);
        $job = Job::query()->where('legacy_id', 1)->firstOrFail();
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

        Job::query()->where('legacy_id', 1)->firstOrFail()->update(['name' => 'Oak Lodge']);

        Event::assertDispatchedTimes('eloquent.updated: '.Listing::class, 1);
        Event::assertDispatched('eloquent.updated: '.Listing::class, fn (string $event, Listing $listing) => [$listing->id, $listing->title] === [1, 'Oak Lodge']);
    }

    #[Test]
    public function it_forwards_nothing_for_a_mass_write_or_a_quiet_write_of_a_successor(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);
        $job = Job::query()->where('legacy_id', 1)->firstOrFail();
        $place = Place::query()->where('job_id', $job->id)->firstOrFail();

        Job::query()->where('legacy_id', 1)->update(['name' => 'Oak Lodge']);
        Place::query()->whereKey($place->id)->update(['street' => '5 Oak St']);
        $job->fill(['price_cents' => 1])->saveQuietly();
        $place->fill(['city' => 'Tacoma'])->saveQuietly();
        $place->deleteQuietly();
        Job::query()->where('legacy_id', 3)->firstOrFail()->deleteQuietly();

        $this->assertSame([], $this->seen);
        $this->assertSame(['Oak Lodge', '0.01', null, null], [Listing::query()->findOrFail(1)->title, Listing::query()->findOrFail(1)->price, Listing::query()->findOrFail(1)->street, Listing::query()->find(3)]);
    }

    #[Test]
    public function it_forwards_the_delete_restore_and_force_delete_of_a_job_as_the_same_listing_events(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->deleted_at !== null, $listing->exists]);
        $job = Job::query()->where('legacy_id', 1)->firstOrFail();

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

        Job::query()->where('legacy_id', 1)->firstOrFail()->delete();

        $this->assertSame([['deleting', false], ['trashed', true], ['deleted', true]], $this->seen);
    }

    #[Test]
    public function it_cancels_the_successor_write_a_legacy_listener_refuses(): void
    {
        $before = $this->snapshot();
        $job = Job::query()->where('legacy_id', 1)->firstOrFail();
        $place = Place::query()->where('job_id', $job->id)->firstOrFail();

        Event::listen('eloquent.saving: '.Listing::class, fn (Listing $listing) => $listing->title !== 'Refused');
        Event::listen('eloquent.creating: '.Listing::class, fn () => false);
        Event::listen('eloquent.updating: '.Listing::class, fn (Listing $listing) => $listing->street !== 'Refused');
        Event::listen('eloquent.deleting: '.Listing::class, fn () => false);
        Event::listen('eloquent.restoring: '.Listing::class, fn () => false);

        $this->assertSame([false, false, false, false, false], [
            $job->fill(['name' => 'Refused'])->save(),
            (new Job(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]))->save(),
            $place->fill(['street' => 'Refused'])->save(),
            $job->refresh()->delete(),
            Job::withTrashed()->where('legacy_id', 2)->firstOrFail()->restore(), // @phpstan-ignore staticMethod.dynamicCall
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
        $job = Job::query()->where('legacy_id', 1)->firstOrFail();

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
            ['updating', fn (Listing $listing) => $listing->status = ListingStatus::Sold, 'status', 'It is declared transitions(), so only a write through Listing can run the trigger that changes it.'],
            ['deleting', fn (Listing $listing) => $listing->title = 'Gone', 'title', 'The forwarded event belongs to a delete, which writes no value.'],
        ];
        $refusals = [];
        $expected = [];

        foreach ($cases as [$event, $change, $attribute, $because]) {
            Event::forget("eloquent.{$event}: ".Listing::class);
            Event::listen("eloquent.{$event}: ".Listing::class, $change);
            $job = Job::query()->where('legacy_id', 1)->firstOrFail();

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

        Job::query()->where('legacy_id', 1)->firstOrFail()->update(['name' => 'Oak Lodge']);
        $forwarded = SyncListingToMls::$synced;
        Listing::query()->findOrFail(3)->update(['title' => 'Elm Lodge']);

        $this->assertSame([[], [3, 3, 3, 3, 3]], [$forwarded, SyncListingToMls::$synced]);
        $this->assertSame([['updated', 1], ['updated', 3]], $this->seen);
    }

    #[Test]
    public function it_forwards_a_dependent_write_only_when_it_changes_a_value_the_listing_serves(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id, $listing->street, $listing->latitude]);
        $job = Job::query()->where('legacy_id', 1)->firstOrFail();
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
        $elm = Place::query()->where('job_id', Job::query()->where('legacy_id', 3)->value('id'))->firstOrFail();

        $elm->update(['job_id' => Job::withTrashed()->where('legacy_id', 2)->value('id')]);

        $this->assertSame([
            ['updating', 2, null, '3 Elm St'], ['updating', 3, '3 Elm St', null],
            ['updated', 2, null, '3 Elm St'], ['updated', 3, '3 Elm St', null],
        ], $this->seen);
    }

    #[Test]
    public function it_forwards_a_write_of_a_shared_successor_only_to_the_legacy_model_its_legacy_type_names(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $describe = fn (Model $legacy) => [class_basename($legacy), $legacy->getAttribute('id'), $legacy->getAttribute('file_name')];

        foreach ([Photo::class, FloorPlan::class, LegacyFile::class] as $model) {
            $this->listen($model, self::EVENTS, $describe);
        }

        $photo = File::query()->where('legacy_type', 'photo')->where('legacy_id', 1)->firstOrFail();
        DB::enableQueryLog();
        $photo->update(['filename' => 'oak-porch.jpg']);
        $read = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        File::query()->create(['legacy_type' => 'floorplan', 'filename' => 'birch-plan.pdf', 'mime_type' => 'application/pdf']);
        File::query()->create(['filename' => 'unclassified.bin', 'mime_type' => 'application/octet-stream']);

        $this->assertStringContainsString('"photos"', $read);
        $this->assertStringNotContainsString('"floorplans"', $read);
        $this->assertStringNotContainsString('"legacy_files"', $read);
        $this->assertSame([
            ['saving', 'Photo', 1, 'oak-porch.jpg'], ['updating', 'Photo', 1, 'oak-porch.jpg'], ['updated', 'Photo', 1, 'oak-porch.jpg'], ['saved', 'Photo', 1, 'oak-porch.jpg'],
            ['saving', 'FloorPlan', null, 'birch-plan.pdf'], ['creating', 'FloorPlan', null, 'birch-plan.pdf'], ['created', 'FloorPlan', 4, 'birch-plan.pdf'], ['saved', 'FloorPlan', 4, 'birch-plan.pdf'],
        ], $this->seen);
    }

    #[Test]
    public function it_forwards_a_write_of_either_successor_of_a_video_to_the_video_it_serves(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::seedMedia();
        FileTables::install();
        FileTables::installMedia();
        $this->listen(Video::class, ['updating', 'updated'], fn (Video $video) => [$video->id, $video->original_file_name, $video->youtube_embed_url, $video->getOriginal('youtube_embed_url')]);
        $this->listen(InteractiveContent::class, self::EVENTS, fn (InteractiveContent $content) => [$content->id]);

        Link::query()->where('legacy_type', 'video')->where('legacy_id', 2)->firstOrFail()->update(['url' => 'https://youtu.be/oak-2']);
        File::query()->where('legacy_type', 'video')->where('legacy_id', 3)->firstOrFail()->update(['filename' => 'drone-2.mov']);

        $this->assertSame([
            ['updating', 2, null, 'https://youtu.be/oak-2', 'https://youtu.be/oak'], ['updated', 2, null, 'https://youtu.be/oak-2', 'https://youtu.be/oak'],
            ['updating', 3, 'drone-2.mov', null, null], ['updated', 3, 'drone-2.mov', null, null],
        ], $this->seen);
    }

    #[Test]
    public function it_forwards_nothing_while_the_legacy_table_is_still_a_table(): void
    {
        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);

        Job::query()->where('legacy_id', 1)->firstOrFail()->update(['name' => 'Oak Lodge']);
        Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame([], $this->seen);
        $this->assertSame('Oak House', DB::table('listings')->where('id', 1)->value('title'));
    }

    #[DefineEnvironment('servesOutsideTestingWithoutACache')]
    #[Test]
    public function it_boots_and_optimizes_outside_local_and_testing_without_a_cache_and_forwards_nothing_until_one_exists(): void
    {
        $this->listen(Listing::class, self::EVENTS, fn (Listing $listing) => [$listing->id]);
        Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY])->update(['name' => 'Birch Lodge']);
        $this->bootstrap = $this->temporaryBootstrap();
        $this->app->useBootstrapPath($this->bootstrap);

        $optimized = Artisan::call('optimize', ['--except' => 'config,events,routes,views']);

        $this->assertSame(['staging', [], 0], [$this->app->environment(), $this->seen, $optimized]);
        $this->assertContains(Listing::class, require "{$this->bootstrap}/cache/compatibility.php");
    }

    #[DefineEnvironment('servesOutsideTestingFromACache')]
    #[Test]
    public function it_forwards_from_the_first_successor_write_outside_local_and_testing_once_the_cache_exists(): void
    {
        $this->listen(Listing::class, ['updated'], fn (Listing $listing) => [$listing->id, $listing->title]);

        Job::query()->where('legacy_id', 1)->firstOrFail()->update(['name' => 'Oak Lodge']);

        $this->assertSame(['staging', [['updated', 1, 'Oak Lodge']]], [$this->app->environment(), $this->seen]);
    }

    /**
     * @param  Application  $app
     */
    protected function servesOutsideTestingWithoutACache($app): void // @phpstan-ignore ergebnis.privateInFinalClass
    {
        $this->discovered = false;
        $app->detectEnvironment(fn () => 'staging');
    }

    /**
     * @param  Application  $app
     */
    protected function servesOutsideTestingFromACache($app): void // @phpstan-ignore ergebnis.privateInFinalClass
    {
        $this->bootstrap = $this->temporaryBootstrap();
        file_put_contents("{$this->bootstrap}/cache/compatibility.php", '<?php return '.var_export([Listing::class], true).';');
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

        foreach (['jobs' => 'legacy_id', 'places' => 'street', 'job_sites' => 'latitude', 'listings_retained' => 'mls_number'] as $table => $order) {
            $snapshot[$table] = array_map(fn (object $row) => (array) $row, DB::table($table)->orderBy($order)->get()->all());
        }

        return $snapshot;
    }
}
