<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Lifecycle\EnteredClosed;
use Tests\Fixtures\Support\Listings\Lifecycle\EnteredLive;
use Tests\Fixtures\Support\Listings\Lifecycle\EnteringClosed;
use Tests\Fixtures\Support\Listings\Lifecycle\EnteringLive;
use Tests\Fixtures\Support\Listings\Lifecycle\LaunchedJob;
use Tests\Fixtures\Support\Listings\Lifecycle\StagedJob;
use Tests\Fixtures\Support\Listings\LifecycleStage;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(Transitions::class)]
final class TransitionsTest extends TestCase
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
        Compatibility::install(Listing::class);
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_runs_the_successor_trigger_for_a_legacy_state_write_and_fires_its_events_once(): void
    {
        Event::fake([EnteringLive::class, EnteredLive::class, EnteringClosed::class, EnteredClosed::class]);
        $held = [];
        Event::listen('eloquent.updating: '.Job::class, function (Job $job) use (&$held): void {
            $held[] = [$job->getDirty()['lifecycle_status'] ?? null, Applying::holds($job)];
        });

        Listing::query()->findOrFail(1)->update(['status' => ListingStatus::Sold]);
        Listing::withTrashed()->findOrFail(2)->update(['status' => ListingStatus::Active]);

        $this->assertSame(['closed', 'live', 'closed'], DB::table('jobs')->orderBy('legacy_id')->pluck('lifecycle_status')->all());
        $this->assertSame([ListingStatus::Sold, ListingStatus::Active], [Listing::query()->findOrFail(1)->status, Listing::withTrashed()->findOrFail(2)->status]);
        $this->assertSame([['closed', true], ['live', true]], $held);
        Event::assertDispatchedTimes(EnteringClosed::class, 1);
        Event::assertDispatchedTimes(EnteredClosed::class, 1);
        Event::assertDispatchedTimes(EnteringLive::class, 1);
        Event::assertDispatchedTimes(EnteredLive::class, 1);
    }

    #[Test]
    public function it_saves_the_successor_once_with_the_legacy_timestamp_when_the_trigger_saves_in_a_later_second(): void
    {
        Event::fake([EnteringLive::class, EnteredLive::class, EnteringClosed::class, EnteredClosed::class]);
        Date::setTestNow('2026-06-07 10:00:00');
        $held = [];
        Event::listen('eloquent.updating: '.Job::class, function (Job $job) use (&$held): void {
            $held[] = [$job->getDirty()['lifecycle_status'] ?? null, Applying::holds($job)];
            Date::setTestNow('2026-06-07 10:00:01');
        });

        $listing = Listing::query()->findOrFail(1);
        $listing->update(['status' => ListingStatus::Sold]);

        $this->assertSame([['closed', true]], $held);
        $this->assertSame(['2026-06-07 10:00:00', '2026-06-07 10:00:00'], [$listing->updated_at?->format('Y-m-d H:i:s'), Listing::query()->findOrFail(1)->updated_at?->format('Y-m-d H:i:s')]);
    }

    #[Test]
    public function it_runs_no_trigger_for_a_write_that_leaves_the_state_where_it_is(): void
    {
        Event::fake([EnteringLive::class, EnteredLive::class, EnteringClosed::class, EnteredClosed::class]);
        $stale = Listing::query()->findOrFail(1);
        DB::table('jobs')->where('legacy_id', 1)->update(['lifecycle_status' => 'closed']);

        $stale->update(['title' => 'Oak Manor', 'status' => ListingStatus::Sold]);
        Listing::query()->findOrFail(3)->update(['title' => 'Elm House']);

        $this->assertSame([['Oak Manor', 'closed'], ['Pine Flat', 'drafting'], ['Elm House', 'closed']], DB::table('jobs')->orderBy('legacy_id')->get(['name', 'lifecycle_status'])->map(fn (object $job) => array_values((array) $job))->all());
        Event::assertNotDispatched(EnteringClosed::class);
        Event::assertNotDispatched(EnteredClosed::class);
        Event::assertNotDispatched(EnteringLive::class);
        Event::assertNotDispatched(EnteredLive::class);
    }

    #[Test]
    public function it_refuses_the_whole_write_for_a_move_no_transition_declares(): void
    {
        $before = $this->snapshot();

        $refusal = $this->refusal(fn () => Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor', 'street' => '2 Oak St', 'status' => ListingStatus::Draft]));

        $this->assertSame(Listing::class.' record 1 cannot change $status from \'live\' to \'drafting\'. Why: LifecycleStatus declares no transition from \'live\' to \'drafting\', so no trigger can make the change. Instead: Change status only along a transition LifecycleStatus declares.', $refusal->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_refuses_the_whole_write_for_a_move_several_transitions_declare(): void
    {
        $before = $this->snapshot();

        $refusal = $this->refusal(fn () => Listing::query()->findOrFail(3)->update(['title' => 'Elm House', 'status' => ListingStatus::Active]));

        $this->assertSame(Listing::class.' record 3 cannot change $status from \'closed\' to \'live\'. Why: LifecycleStatus declares 2 transitions from \'closed\' to \'live\', through Reopen and Relist, so the write cannot choose one. Instead: Run the trigger you mean on Job directly.', $refusal->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_refuses_the_whole_write_when_the_trigger_refuses_the_move(): void
    {
        DB::table('jobs')->where('legacy_id', 1)->update(['price_cents' => null]);
        $before = $this->snapshot();

        $refusal = $this->refusal(fn () => Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor', 'street' => '2 Oak St', 'status' => ListingStatus::Sold]));

        $this->assertSame(Listing::class.' record 1 cannot change $status, because the trigger Close refused it. Why: Invalid transition from [Live] to [Closed] on [Job]. Instead: Make the change once Close allows it, or change status along another transition.', $refusal->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_refuses_the_whole_write_when_a_successor_listener_vetoes_the_trigger_save(): void
    {
        $before = $this->snapshot();
        Event::listen('eloquent.saving: '.Job::class, fn (Job $job) => ! $job->isDirty('lifecycle_status'));

        $refusal = $this->refusal(fn () => Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor', 'status' => ListingStatus::Sold]));

        $this->assertSame(Listing::class.' record 1 cannot change $status, because the trigger Close refused it', $refusal->refused);
        $this->assertSame('Job did not store lifecycle_status, because a listener of its save refused the save.', $refusal->because);
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_refuses_a_transitions_attribute_whose_column_has_no_state_machine(): void
    {
        $before = $this->snapshot();
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name')->transitions()));
            }
        };

        $update = $this->refusal(fn () => $listing->newQuery()->findOrFail(1)->update(['title' => 'Oak Manor']));
        $insert = $this->refusal(fn () => $listing->newQuery()->create(['title' => 'Birch House']));

        $this->assertSame($listing::class.'::$title cannot take a write. Why: It is declared transitions(), and Job::$name has no state-machine cast. Instead: Cast name on Job to a StateMachineable enum, or drop transitions() from the attribute.', $update->getMessage());
        $this->assertSame($update->getMessage(), $insert->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_refuses_a_transitions_attribute_whose_column_casts_to_an_enum_without_a_state_machine(): void
    {
        $before = $this->snapshot();
        $job = new class extends Model
        {
            protected $table = 'jobs';

            protected $guarded = [];

            protected function casts(): array
            {
                return ['lifecycle_status' => ListingStatus::class];
            }
        };
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            /** @var class-string<Model> */
            public static string $successor;

            public $timestamps = false;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->servedFrom(self::$successor, fn (Successor $job) => $job
                        ->attribute('status', fn (Attribute $attribute) => $attribute
                            ->from('lifecycle_status')
                            ->mapping(['drafting' => ListingStatus::Draft, 'live' => ListingStatus::Active, 'closed' => ListingStatus::Sold])
                            ->transitions()));
            }
        };
        $listing::$successor = $job::class;

        $refusal = $this->refusal(fn () => $listing->newQuery()->findOrFail(1)->update(['status' => ListingStatus::Sold]));

        $this->assertSame([$listing::class.'::$status cannot take a write', 'has no state-machine cast.'], [$refusal->refused, substr($refusal->because, -strlen('has no state-machine cast.'))]);
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_runs_the_trigger_of_an_integer_backed_state_machine_and_refuses_a_move_it_does_not_declare(): void
    {
        DB::statement('create table stages (id serial primary key, stage integer not null default 1)');
        DB::statement('create table staged_jobs (id uuid primary key default gen_random_uuid(), stage integer not null default 1)');
        DB::table('stages')->insert([['stage' => 1], ['stage' => 1]]);
        $staged = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'stages';

            protected $guarded = [];

            public static function compatibility(Compatibility $stage): Compatibility
            {
                return $stage
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->retainedIn('stages_retained', key: 'staged_job_id')
                    ->servedFrom(StagedJob::class, fn (Successor $job) => $job
                        ->attribute('stage', fn (Attribute $attribute) => $attribute->from('stage')->transitions()));
            }
        };

        try {
            Compatibility::prepare($staged::class);
            DB::statement('insert into staged_jobs (legacy_id, stage) select id, stage from stages');
            Compatibility::install($staged::class);

            $staged->newQuery()->findOrFail(1)->update(['stage' => LifecycleStage::Live]);
            $refusal = $this->refusal(fn () => $staged->newQuery()->findOrFail(1)->update(['stage' => LifecycleStage::Drafting]));
            $stages = DB::table('staged_jobs')->orderBy('legacy_id')->pluck('stage')->all();
        } finally {
            DB::statement('drop view if exists stages');
            DB::statement('drop table if exists stages, stages_retained, staged_jobs cascade');
            LegacyBuilder::forgetViews();
        }

        $this->assertSame([2, 1], $stages);
        $this->assertSame($staged::class.' record 1 cannot change $stage from \'2\' to \'1\'. Why: LifecycleStage declares no transition from \'2\' to \'1\', so no trigger can make the change. Instead: Change stage only along a transition LifecycleStage declares.', $refusal->getMessage());
    }

    #[Test]
    public function it_inserts_a_record_in_the_initial_state_of_its_successor_and_refuses_any_other(): void
    {
        $listing = Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'status' => ListingStatus::Draft]);
        $before = $this->snapshot();

        $refusal = $this->refusal(fn () => Listing::query()->create(['title' => 'Cedar Cabin', 'company_id' => ListingTables::COMPANY, 'status' => ListingStatus::Active]));

        $this->assertSame('drafting', DB::table('jobs')->where('legacy_id', $listing->id)->value('lifecycle_status'));
        $this->assertSame(Listing::class.' cannot insert a record whose $status stores \'live\'. Why: A new Job starts in its initial state \'drafting\' on lifecycle_status, and an insert runs no transition. Instead: Insert the record with the status that stores \'drafting\', then change it in a second write.', $refusal->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_takes_the_initial_state_from_the_successor_model_before_the_column_default(): void
    {
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->servedFrom(LaunchedJob::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id'))
                        ->attribute('status', fn (Attribute $attribute) => $attribute
                            ->from('lifecycle_status')
                            ->mapping(['drafting' => ListingStatus::Draft, 'live' => ListingStatus::Active, 'closed' => ListingStatus::Sold])
                            ->transitions()));
            }
        };

        $launched = $listing->newQuery()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'status' => ListingStatus::Active]);
        $refusal = $this->refusal(fn () => $listing->newQuery()->create(['title' => 'Cedar Cabin', 'company_id' => ListingTables::COMPANY, 'status' => ListingStatus::Draft]));

        $this->assertSame('live', DB::table('jobs')->where('legacy_id', $launched->getKey())->value('lifecycle_status'));
        $this->assertSame($listing::class.' cannot insert a record whose $status stores \'drafting\'. Why: A new LaunchedJob starts in its initial state \'live\' on lifecycle_status, and an insert runs no transition. Instead: Insert the record with the status that stores \'live\', then change it in a second write.', $refusal->getMessage());
    }

    #[Test]
    public function it_refuses_an_insert_of_a_state_when_its_successor_declares_no_initial_state(): void
    {
        DB::statement('alter table jobs alter column lifecycle_status drop default');
        $before = $this->snapshot();

        $refusal = $this->refusal(fn () => Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'status' => ListingStatus::Draft]));

        $this->assertSame(Listing::class.' cannot insert a record whose $status stores \'drafting\'. Why: Job declares no initial state for lifecycle_status, so an insert cannot store a state without a transition. Instead: Insert the record without status, then set it through the state machine of Job.', $refusal->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function refusal(Closure $write): CompatibilityWriteRefused
    {
        try {
            $write();
        } catch (CompatibilityWriteRefused $refusal) {
            return $refusal;
        }

        $this->fail('The write was not refused.');
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $tables = [];

        foreach (['jobs' => 'legacy_id', 'places' => 'street', 'job_sites' => 'latitude', 'listings_retained' => 'job_id'] as $table => $order) {
            $tables[$table] = DB::table($table)->orderBy($order)->get()->map(fn (object $row) => (array) $row)->all();
        }

        return $tables;
    }
}
