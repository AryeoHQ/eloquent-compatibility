<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\ClearListingAddress;
use Tests\Fixtures\Support\Listings\InterleavingWriteScope;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\MovingWriteScope;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\RecordingWriteScope;
use Tests\Fixtures\Support\Listings\SkippingWriteScope;
use Tests\Fixtures\Support\Listings\SwallowingWriteScope;
use Tests\TestCase;

#[CoversClass(UnitOfWork::class)]
final class UnitOfWorkTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        RecordingWriteScope::$received = [];
        InterleavingWriteScope::$received = [];
        InterleavingWriteScope::$interleave = null;
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_saves_a_job_once_for_five_plain_attributes_and_once_more_for_the_publish_of_a_status_write(): void
    {
        $listing = Listing::withTrashed()->findOrFail(2);
        $fired = new Collection;

        foreach (['saving', 'updated'] as $event) {
            Event::listen("eloquent.{$event}: ".Job::class, function () use ($fired, $event): void {
                $fired->push($event);
            });
        }

        $listing->update(['title' => 'Pine Lodge', 'price' => '12.50', 'created_at' => '2026-01-04 09:15:00', 'updated_at' => '2026-03-01 08:00:00', 'deleted_at' => '2026-02-02 00:00:00']);
        $plain = $fired->all();
        $native = DB::transaction(fn () => Job::query()->create(['name' => 'Native House', 'company_id' => ListingTables::COMPANY]))->refresh();
        $fired->splice(0);
        $native->lifecycle_status->publish()->now();
        $publish = $fired->splice(0)->all();
        $listing->update(['title' => 'Pine Manor', 'price' => '13.50', 'created_at' => '2026-01-05 09:15:00', 'updated_at' => '2026-03-02 08:00:00', 'deleted_at' => '2026-02-03 00:00:00', 'status' => ListingStatus::Active]);

        $this->assertSame(['saving', 'updated'], $plain);
        $this->assertContains('updated', $publish);
        $this->assertSame(['saving', 'updated', ...$publish], $fired->all());
        $this->assertSame(['Pine Manor', 1350, 'live', '2026-03-02 08:00:00+00'], array_values((array) DB::table('jobs')->where('id', ListingTables::jobOf(2))->first(['name', 'price_cents', 'lifecycle_status', 'updated_at'])));
    }

    #[Test]
    public function it_runs_the_write_scope_before_the_first_lock_the_package_takes_and_hands_it_the_touched_keys(): void
    {
        $listing = $this->scoped(RecordingWriteScope::class)::query()->findOrFail(1);
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $listing->update(['title' => 'Oak Manor', 'street' => '2 Oak St']);

        $scope = array_search("select 'the write scope' as marker", $statements, true);
        $locks = array_keys(array_filter($statements, fn (string $sql) => str_contains($sql, 'for update')));
        $job = (string) ListingTables::jobOf(1);
        $this->assertIsInt($scope);
        $this->assertNotSame([], $locks);
        $this->assertLessThan(min($locks), $scope);
        $this->assertSame([[
            Job::class => [$job],
            JobSite::class => [(string) JobSite::query()->where('job_id', $job)->value('id')],
            Place::class => [(string) Place::query()->where('job_id', $job)->value('id')],
        ]], RecordingWriteScope::$received);
        $this->assertSame('2 Oak St', Place::query()->where('job_id', $job)->value('street'));
    }

    #[Test]
    public function it_refuses_with_a_retryable_error_when_a_key_the_scope_received_moves_before_the_lock(): void
    {
        $listing = $this->scoped(MovingWriteScope::class)::query()->findOrFail(1);
        $before = DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('name');

        try {
            $listing->update(['title' => 'Oak Manor']);
            $this->fail('The write was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringContainsString('cannot take this write now, because its JobSite rows moved after the write scope ran', $refusal->getMessage());
            $this->assertStringEndsWith('Instead: Retry the write in a new transaction; the outermost transaction of the application is the retry point.', $refusal->getMessage());
        }

        $this->assertSame($before, DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('name'));
        $this->assertTrue(JobSite::query()->where('job_id', ListingTables::jobOf(1))->exists());
    }

    #[Test]
    public function it_runs_the_write_scope_once_for_a_mass_write_with_the_keys_of_every_record(): void
    {
        $model = $this->scoped(RecordingWriteScope::class);

        $updated = $model::query()->whereIn('id', [1, 3])->update(['street' => '9 Shared St']);

        $jobs = [(string) ListingTables::jobOf(1), (string) ListingTables::jobOf(3)];
        sort($jobs);
        $this->assertSame(2, $updated);
        $this->assertCount(1, RecordingWriteScope::$received);
        $this->assertSame($jobs, RecordingWriteScope::$received[0][Job::class]);
        $this->assertSame(['9 Shared St', '9 Shared St'], Place::query()->whereIn('job_id', $jobs)->pluck('street')->all());
    }

    #[Test]
    public function it_reads_the_original_of_a_write_after_the_lock_so_a_commit_made_after_the_scope_read_is_kept(): void
    {
        $job = ListingTables::jobOf(1);
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        InterleavingWriteScope::$interleave = function () use ($job): void {
            DB::connection('concurrent')->table('jobs')->where('id', $job)->update(['price_cents' => 99900]);
        };
        $listing = $this->interleaved()->newQuery()->findOrFail(1);

        $listing->increment('price', 1);

        $this->assertCount(1, InterleavingWriteScope::$received);
        $this->assertSame(100000, DB::table('jobs')->where('id', $job)->value('price_cents'));
        $this->assertSame('1000.00', $this->interleaved()->newQuery()->findOrFail(1)->getAttribute('price'));
    }

    #[Test]
    public function it_keeps_the_legacy_updated_at_of_a_create_whose_job_created_listener_saves_the_job_a_second_later(): void
    {
        Event::listen('eloquent.created: '.Job::class, function (Job $job): void {
            usleep(1_100_000);
            Job::query()->findOrFail($job->id)->update(['price_cents' => 1]);
        });

        $created = Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'updated_at' => '2026-03-01 08:00:00']);

        $this->assertSame(['2026-03-01 08:00:00', '0.01'], [$created->refresh()->updated_at?->format('Y-m-d H:i:s'), $created->price]);
        $this->assertNotNull(DB::table('jobs')->where('id', ListingTables::jobOf($created->id))->value('created_at'));
    }

    #[Test]
    public function it_hands_the_write_scope_the_key_of_an_orphaned_place_a_create_links(): void
    {
        $place = $this->orphanedPlace();

        $created = $this->linking()->newQuery()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'place_id' => $place]);

        $job = (string) ListingTables::jobOf($created->getKey());
        $this->assertSame([[Job::class => [], Place::class => [$place]]], InterleavingWriteScope::$received);
        $this->assertSame($job, Place::query()->whereKey($place)->value('job_id'));
    }

    #[Test]
    public function it_refuses_a_create_that_names_the_place_of_another_live_record_and_leaves_that_record_its_place(): void
    {
        $job = (string) ListingTables::jobOf(1);
        $place = (string) Place::query()->where('job_id', $job)->value('id');
        $linking = $this->linking();

        try {
            $linking->newQuery()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'place_id' => $place]);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = str_replace($linking::class, 'Listing', $exception->getMessage());
        }

        $this->assertStringStartsWith("Listing cannot run a create that names the places row {$place} as its \$place_id through its compatibility map. Why: That Place belongs to Job {$job}, which still exists. A create links only a row whose Job is gone, because the package never re-keys a related row. Instead: ", $refusal);
        $this->assertSame([$job, '1 Oak St', 'Seattle'], [Place::query()->whereKey($place)->value('job_id'), Listing::query()->findOrFail(1)->street, Listing::query()->findOrFail(1)->city]);
    }

    #[Test]
    public function it_links_an_orphaned_place_before_any_operation_runs_whatever_order_the_map_declares_its_attributes_in(): void
    {
        $cities = [];

        foreach ([$this->placeAfterAddress(), $this->placeBeforeAddress()] as $listing) {
            $place = $this->orphanedPlace();
            $places = DB::table('places')->count();
            $listing->newQuery()->create(['title' => 'Ash House', 'company_id' => ListingTables::COMPANY, 'place_id' => $place, 'street' => '9 Ash St', 'city' => 'Tacoma']);
            $cities[] = [DB::table('places')->where('id', $place)->value('city'), DB::table('places')->count() - $places];
        }

        $this->assertSame([['Tacoma', 0], ['Tacoma', 0]], $cities);
    }

    #[Test]
    public function it_refuses_with_a_retryable_error_when_the_place_a_create_links_moves_after_the_write_scope_ran(): void
    {
        $place = $this->orphanedPlace();
        $listings = DB::table('listings_retained')->count();
        InterleavingWriteScope::$interleave = function () use ($place): void {
            DB::table('places')->where('id', $place)->delete();
        };

        try {
            $this->linking()->newQuery()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'place_id' => $place]);
            $this->fail('The create was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringContainsString('cannot take this write now, because its Place rows moved after the write scope ran', $refusal->getMessage());
        }

        $this->assertSame($listings, DB::table('listings_retained')->count());
        $this->assertSame([true, null], [Place::query()->whereKey($place)->exists(), Place::query()->whereKey($place)->value('job_id')]);
    }

    #[Test]
    public function it_rethrows_the_refusal_a_write_scope_catches_and_commits_nothing(): void
    {
        Event::listen('eloquent.saving: '.Job::class, function (Job $job): void {
            $job->name = strtoupper($job->name);
        });

        try {
            $this->swallowing()::query()->findOrFail(1)->update(['title' => 'Oak Manor']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString("record 1 cannot take this write of \$title. Why: The write stores 'Oak Manor', and the view now serves title as 'OAK MANOR'", $refusal);
        $this->assertSame('Oak House', DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('name'));
    }

    #[Test]
    public function it_refuses_a_write_whose_scope_returns_without_running_it(): void
    {
        $skipping = $this->skipping();

        try {
            $skipping::query()->findOrFail(1)->update(['title' => 'Oak Manor']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame($skipping::class.' cannot take this write, because its write scope SkippingWriteScope returned without running it. Why: The package runs the write inside SkippingWriteScope::run(), and run() returned without calling $write, so the write would report success and store nothing. Instead: Make SkippingWriteScope::run() call $write and return its result.', $refusal);
        $this->assertSame('Oak House', DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('name'));
    }

    private function swallowing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;
            use SoftDeletes;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return UnitOfWorkTest::scopedMap($listing)->writesWithin(SwallowingWriteScope::class);
            }
        };
    }

    private function skipping(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;
            use SoftDeletes;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return UnitOfWorkTest::scopedMap($listing)->writesWithin(SkippingWriteScope::class);
            }
        };
    }

    #[Test]
    public function it_refuses_a_write_that_reaches_a_related_successor_an_earlier_operation_of_it_deleted(): void
    {
        $clearing = new class extends Model implements Compatible
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
                        ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at'))
                        ->attribute(name: 'title', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->related(relation: fn (Job $job) => $job->rootPlace(), successor: fn (Successor $place) => $place
                            ->attribute(name: 'street', definition: fn (Attribute $attribute) => $attribute->from('street')->writtenBy(ClearListingAddress::class))
                            ->attribute(name: 'city', definition: fn (Attribute $attribute) => $attribute->from('city'))));
            }
        };

        try {
            $clearing->newQuery()->findOrFail(1)->update(['street' => null, 'city' => 'Tacoma']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString(' cannot run an operation that calls related() for Place after deleteRelated() through its compatibility map. Why: The unit of work deletes that Place after the saves, so a change to it would be saved and then deleted.', $refusal);
        $this->assertSame([1, 'Seattle'], [Place::query()->where('job_id', ListingTables::jobOf(1))->count(), Place::query()->where('job_id', ListingTables::jobOf(1))->value('city')]);
    }

    private function orphanedPlace(): string
    {
        DB::statement('alter table places alter column job_id drop not null');
        DB::statement('alter table places drop constraint places_job_id_foreign, add constraint places_job_id_foreign foreign key (job_id) references jobs (id) on delete set null');
        $job = (string) DB::table('jobs')->insertGetId(['name' => 'Old', 'company_id' => ListingTables::COMPANY], 'id');
        $place = (string) DB::table('places')->insertGetId(['job_id' => $job, 'street' => '9 Ash St', 'city' => 'Seattle'], 'id');
        DB::table('jobs')->where('id', $job)->delete();

        return $place;
    }

    private function placeAfterAddress(): Model
    {
        return new class extends Model implements Compatible
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
                        ->attribute(name: 'created_at', definition: fn (Attribute $attribute) => $attribute->from('created_at'))
                        ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at'))
                        ->attribute(name: 'title', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'company_id', definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: false))
                        ->related(relation: fn (Job $job) => $job->rootPlace(), successor: fn (Successor $place) => $place
                            ->attribute(name: 'street', definition: fn (Attribute $attribute) => $attribute->from('street'))
                            ->attribute(name: 'city', definition: fn (Attribute $attribute) => $attribute->from('city'))
                            ->attribute(name: 'place_id', definition: fn (Attribute $attribute) => $attribute->from('id')->readOnly(because: 'A listing keeps its place.', ignoreWrites: false))));
            }
        };
    }

    private function placeBeforeAddress(): Model
    {
        return new class extends Model implements Compatible
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
                        ->attribute(name: 'created_at', definition: fn (Attribute $attribute) => $attribute->from('created_at'))
                        ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at'))
                        ->attribute(name: 'title', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'company_id', definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: false))
                        ->related(relation: fn (Job $job) => $job->rootPlace(), successor: fn (Successor $place) => $place
                            ->attribute(name: 'place_id', definition: fn (Attribute $attribute) => $attribute->from('id')->readOnly(because: 'A listing keeps its place.', ignoreWrites: false))
                            ->attribute(name: 'street', definition: fn (Attribute $attribute) => $attribute->from('street'))
                            ->attribute(name: 'city', definition: fn (Attribute $attribute) => $attribute->from('city'))));
            }
        };
    }

    private function linking(): Model
    {
        return new class extends Model implements Compatible
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
                        ->attribute(name: 'created_at', definition: fn (Attribute $attribute) => $attribute->from('created_at'))
                        ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at'))
                        ->attribute(name: 'title', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'company_id', definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: false))
                        ->related(relation: fn (Job $job) => $job->rootPlace(), successor: fn (Successor $place) => $place
                            ->attribute(name: 'place_id', definition: fn (Attribute $attribute) => $attribute->from('id')->readOnly(because: 'A listing keeps its place.', ignoreWrites: false))))
                    ->writesWithin(InterleavingWriteScope::class);
            }
        };
    }

    private function interleaved(): Model
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
                        ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at'))
                        ->attribute(name: 'title', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'price', definition: fn (Attribute $attribute) => $attribute->from('price_cents')->dividedBy(100))
                        ->related(relation: fn (Job $job) => $job->rootPlace(), successor: fn (Successor $place) => $place
                            ->attribute(name: 'street', definition: fn (Attribute $attribute) => $attribute->from('street'))))
                    ->writesWithin(InterleavingWriteScope::class);
            }
        };
    }

    /**
     * @return class-string<Model>
     */
    private function scoped(string $scope): string
    {
        return $scope === RecordingWriteScope::class ? $this->recorded()::class : $this->moved()::class;
    }

    private function recorded(): Model
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
                return UnitOfWorkTest::scopedMap($listing)->writesWithin(RecordingWriteScope::class);
            }
        };
    }

    private function moved(): Model
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
                return UnitOfWorkTest::scopedMap($listing)->writesWithin(MovingWriteScope::class);
            }
        };
    }

    public static function scopedMap(Compatibility $listing): Compatibility
    {
        return $listing
            ->retainedIn('listings_retained')
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at'))
                ->attribute(name: 'title', definition: fn (Attribute $attribute) => $attribute->from('name'))
                ->related(relation: fn (Job $job) => $job->rootPlace(), successor: fn (Successor $place) => $place
                    ->attribute(name: 'street', definition: fn (Attribute $attribute) => $attribute->from('street')))
                ->related(relation: fn (Job $job) => $job->site(), successor: fn (Successor $site) => $site
                    ->attribute(name: 'latitude', definition: fn (Attribute $attribute) => $attribute->from('latitude'))));
    }
}
