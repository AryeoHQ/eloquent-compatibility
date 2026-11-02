<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\NullDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\AliasedPlace;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(RecordWriter::class)]
final class RecordWriterTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    /** @var null|array{resource, array<int, resource>} */
    private null|array $concurrent = null;

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
        if ($this->concurrent !== null) {
            proc_terminate($this->concurrent[0]);
            proc_close($this->concurrent[0]);
            $this->concurrent = null;
        }

        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_rolls_back_every_table_when_a_successor_listener_vetoes_its_save(): void
    {
        $before = $this->snapshot();
        Event::listen('eloquent.saving: '.Place::class, fn () => false);
        $listing = Listing::query()->findOrFail(1);
        $listing->fill(['title' => 'Oak Manor', 'street' => '2 Oak St', 'mls_number' => 'MLS-1B']);

        $update = $this->refusal(fn () => $listing->save());
        $insert = $this->refusal(fn () => Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'street' => '9 Birch St', 'mls_number' => 'MLS-9']));

        $this->assertSame('Tests\Fixtures\Support\Listings\Listing cannot take this write, because a listener of Place refused its save. Why: The write stores its values through Place, and that model\'s own listeners refused them. Instead: Make the write satisfy the rules of Place.', $update->getMessage());
        $this->assertSame($update->getMessage(), $insert->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_keeps_a_legacy_only_fact_through_a_successor_write_and_a_successor_only_fact_through_a_legacy_write(): void
    {
        $job = Job::query()->where('legacy_id', 1)->firstOrFail();
        $root = Place::query()->where('job_id', $job->id)->firstOrFail();
        DB::table('places')->where('id', $root->id)->update(['created_at' => '2026-01-01 00:00:00+00']);
        $wing = Place::query()->create(['job_id' => $job->id, 'parent_id' => $root->id, 'street' => '1 Oak St, wing']);
        $wingBefore = DB::table('places')->where('id', $wing->id)->first();

        $job->update(['name' => 'Oak Lodge']);
        $listing = Listing::query()->findOrFail(1);
        $listing->update(['street' => '2 Oak St']);

        $this->assertSame(['Oak Lodge', 'MLS-1', true], [$listing->title, $listing->mls_number, $listing->featured]);
        $this->assertSame(['MLS-1', true], [Listing::query()->findOrFail(1)->mls_number, Listing::query()->findOrFail(1)->featured]);
        $this->assertSame(['2 Oak St', '2026-01-01 00:00:00'], [$root->refresh()->street, $root->created_at?->toDateTimeString()]);
        $this->assertEquals($wingBefore, DB::table('places')->where('id', $wing->id)->first());
    }

    #[Test]
    public function it_stores_the_value_a_successor_listener_sets(): void
    {
        Event::listen('eloquent.saving: '.Job::class, function (Job $job): void {
            $job->name = strtoupper($job->name);
        });
        $listing = Listing::query()->findOrFail(1);

        $listing->update(['title' => 'Oak Manor']);

        $this->assertSame('OAK MANOR', Job::query()->where('legacy_id', 1)->value('name'));
        $this->assertSame('OAK MANOR', Listing::query()->findOrFail(1)->title);
    }

    #[Test]
    public function it_gives_the_legacy_record_the_values_its_successors_stored_before_its_own_events_run(): void
    {
        Event::listen('eloquent.saving: '.Job::class, function (Job $job): void {
            $job->name = strtoupper($job->name);
        });
        $seen = [];

        foreach (['created', 'updated', 'saved'] as $event) {
            Event::listen("eloquent.{$event}: ".Listing::class, function (Listing $listing) use (&$seen, $event): void {
                $seen[] = [$event, $listing->title, $listing->getOriginal('title'), $listing->getChanges()['title'] ?? null];
            });
        }

        $updated = Listing::query()->findOrFail(1);
        $updated->update(['title' => 'Oak Manor']);
        $created = Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame([
            ['updated', 'OAK MANOR', 'Oak House', 'OAK MANOR'],
            ['saved', 'OAK MANOR', 'Oak House', 'OAK MANOR'],
            ['created', 'BIRCH HOUSE', null, null],
            ['saved', 'BIRCH HOUSE', null, null],
        ], $seen);
        $this->assertSame([['OAK MANOR', 'OAK MANOR', false], ['BIRCH HOUSE', 'BIRCH HOUSE', false]], [
            [$updated->title, $updated->getOriginal('title'), $updated->isDirty()],
            [$created->title, $created->getOriginal('title'), $created->isDirty()],
        ]);
    }

    #[Test]
    public function it_gives_the_legacy_record_the_stored_values_on_every_record_write_path_before_its_own_events_run(): void
    {
        Event::listen('eloquent.saving: '.Job::class, function (Job $job): void {
            $job->name = strtoupper($job->name);
        });
        $seen = [];

        foreach (['created', 'updated', 'saved', 'restored'] as $event) {
            Event::listen("eloquent.{$event}: ".Listing::class, function (Listing $listing) use (&$seen, $event): void {
                $seen[] = [$event, $listing->title];
            });
        }

        $incremented = Listing::query()->findOrFail(1);
        $incremented->increment('price', 1, ['title' => 'Oak Manor']);
        $saved = Listing::query()->findOrFail(3);
        $saved->title = 'Elm Lodge';
        $saved->save();
        $restored = Listing::withTrashed()->findOrFail(2);
        $restored->title = 'Pine Lodge';
        $restored->restore(); // @phpstan-ignore staticMethod.dynamicCall
        $created = Listing::query()->firstOrCreate(['title' => 'Birch House'], ['company_id' => ListingTables::COMPANY]);

        $this->assertSame([
            ['updated', 'OAK MANOR'],
            ['updated', 'ELM LODGE'],
            ['saved', 'ELM LODGE'],
            ['updated', 'PINE LODGE'],
            ['saved', 'PINE LODGE'],
            ['restored', 'PINE LODGE'],
            ['created', 'BIRCH HOUSE'],
            ['saved', 'BIRCH HOUSE'],
        ], $seen);
        $this->assertSame(['OAK MANOR', 'ELM LODGE', 'PINE LODGE', 'BIRCH HOUSE'], array_map(fn (Listing $listing) => $listing->getAttribute('title'), [$incremented, $saved, $restored, $created]));
        $this->assertSame(['OAK MANOR', 'PINE LODGE', 'ELM LODGE', 'BIRCH HOUSE'], Listing::query()->orderBy('id')->pluck('title')->all());
    }

    #[RequiresMethod(Model::class, 'saveOrIgnore')]
    #[Test]
    public function it_gives_the_legacy_record_the_stored_values_of_a_save_or_ignore_before_its_own_events_run(): void
    {
        Event::listen('eloquent.saving: '.Job::class, function (Job $job): void {
            $job->name = strtoupper($job->name);
        });
        $seen = [];

        foreach (['created', 'saved'] as $event) {
            Event::listen("eloquent.{$event}: ".Listing::class, function (Listing $listing) use (&$seen, $event): void {
                $seen[] = [$event, $listing->title];
            });
        }

        $listing = new Listing(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY]);
        $listing->saveOrIgnore(); // @phpstan-ignore method.notFound

        $this->assertSame([['created', 'BIRCH HOUSE'], ['saved', 'BIRCH HOUSE']], $seen);
        $this->assertSame(['BIRCH HOUSE', false], [$listing->title, $listing->isDirty()]);
    }

    #[Test]
    public function it_gives_the_legacy_record_the_values_its_successors_stored_when_the_write_changes_its_identifier(): void
    {
        Event::listen('eloquent.saving: '.Job::class, function (Job $job): void {
            $job->name = strtoupper($job->name);
        });
        $updated = Listing::query()->create(['title' => 'Pine House', 'company_id' => ListingTables::COMPANY]);
        $incremented = Listing::query()->create(['title' => 'Elm House', 'company_id' => ListingTables::COMPANY]);
        $seen = [];

        foreach (['updated', 'saved'] as $event) {
            Event::listen("eloquent.{$event}: ".Listing::class, function (Listing $listing) use (&$seen, $event): void {
                $seen[] = [$event, $listing->id, $listing->title];
            });
        }

        $updated->update(['id' => 99, 'title' => 'Pine Lodge']);
        $incremented->increment('id', 95, ['title' => 'Elm Lodge']);

        $this->assertSame([
            ['updated', 99, 'PINE LODGE'],
            ['saved', 99, 'PINE LODGE'],
            ['updated', 100, 'ELM LODGE'],
        ], $seen);
        $this->assertSame([[99, 'PINE LODGE', false], [100, 'ELM LODGE', false]], [
            [$updated->id, $updated->title, $updated->isDirty()],
            [$incremented->id, $incremented->title, $incremented->isDirty('id')],
        ]);
        $this->assertSame([99 => 'PINE LODGE', 100 => 'ELM LODGE'], Listing::query()->whereIn('id', [99, 100])->orderBy('id')->pluck('title', 'id')->all());
    }

    /**
     * @return iterable<string, array{string, Closure(Listing): mixed}>
     */
    public static function quietWrites(): iterable
    {
        yield 'a quiet save' => ['saving', function (Listing $listing): mixed {
            $listing->title = 'Oak Manor';

            return $listing->saveQuietly();
        }];
        yield 'a quiet soft delete' => ['deleting', fn (Listing $listing): mixed => $listing->deleteQuietly()];
        yield 'a quiet force delete' => ['deleting', fn (Listing $listing): mixed => $listing->forceDeleteQuietly()];
    }

    /**
     * @param  Closure(Listing): mixed  $write
     */
    #[DataProvider('quietWrites')]
    #[Test]
    public function it_runs_the_successor_listeners_of_a_quiet_legacy_write_and_keeps_the_legacy_events_quiet(string $event, Closure $write): void
    {
        $before = $this->snapshot();
        $fired = [];
        Event::listen('eloquent.*: '.Listing::class, function (string $name) use (&$fired): void {
            $fired[] = $name;
        });
        Event::listen("eloquent.{$event}: ".Job::class, fn () => false);
        $listing = Listing::query()->findOrFail(1);
        $fired = [];

        $refusal = $this->refusal(fn () => $write($listing));

        $this->assertSame('Tests\Fixtures\Support\Listings\Listing cannot take this write, because a listener of Job refused its save. Why: The write stores its values through Job, and that model\'s own listeners refused them. Instead: Make the write satisfy the rules of Job.', $refusal->getMessage());
        $this->assertSame([], $fired);
        $this->assertSame($before, $this->snapshot());
        $this->assertNotInstanceOf(NullDispatcher::class, Model::getEventDispatcher());
    }

    /**
     * @return iterable<string, array{bool, Closure(Model): mixed}>
     */
    public static function writesThroughAnotherConnectionName(): iterable
    {
        $update = fn (Model $listing): mixed => $listing->newQuery()->findOrFail(1)->update(['street' => '2 Oak St', 'latitude' => 1.5]);

        yield 'an update' => [false, $update];
        yield 'an update under a guard' => [true, $update];
        yield 'an insert' => [false, fn (Model $listing): mixed => $listing->newQuery()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'street' => '9 Birch St', 'latitude' => 2.5])];
    }

    /**
     * @param  Closure(Model): mixed  $write
     */
    #[DataProvider('writesThroughAnotherConnectionName')]
    #[Test]
    public function it_writes_a_related_model_that_names_another_connection_to_the_same_database_inside_the_write_transaction(bool $guarded, Closure $write): void
    {
        config(['database.connections.'.AliasedPlace::CONNECTION => [...(array) config('database.connections.'.DB::getDefaultConnection()), 'database' => DB::connection()->getDatabaseName()]]);
        DB::connection(AliasedPlace::CONNECTION)->statement("set lock_timeout = '2s'");
        $listing = $guarded ? $this->guardedAliasedListing() : $this->aliasedListing();
        $before = $this->snapshot();
        Event::listen('eloquent.saving: '.JobSite::class, fn () => false);

        $refusal = $this->refusal(fn () => $write($listing));

        $this->assertStringEndsWith(' cannot take this write, because a listener of JobSite refused its save. Why: The write stores its values through JobSite, and that model\'s own listeners refused them. Instead: Make the write satisfy the rules of JobSite.', $refusal->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @return iterable<string, array{Closure(): Listing}>
     */
    public static function deletesOfATrashedSuccessor(): iterable
    {
        yield 'an already-trashed record' => [fn (): Listing => Listing::withTrashed()->findOrFail(2)];
        yield 'a stale record whose successor a concurrent writer trashed' => [function (): Listing {
            $listing = Listing::query()->findOrFail(1);
            Job::query()->where('legacy_id', 1)->firstOrFail()->delete();

            return $listing;
        }];
    }

    /**
     * @param  Closure(): Listing  $record
     */
    #[DataProvider('deletesOfATrashedSuccessor')]
    #[Test]
    public function it_runs_the_successor_delete_and_its_veto_when_the_legacy_record_deletes_a_trashed_successor(Closure $record): void
    {
        $listing = $record();
        $before = $this->snapshot();
        /** @var Collection<int, string> $fired */
        $fired = new Collection;
        Event::listen('eloquent.*: '.Job::class, function (string $name) use ($fired): void {
            $fired->push(Str::before(Str::after($name, 'eloquent.'), ':'));
        });
        Event::listen('eloquent.deleting: '.Job::class, function () use ($fired): bool {
            $fired->push('vetoed');

            return false;
        });

        $refusal = $this->refusal(fn () => $listing->delete());

        $this->assertSame('Tests\Fixtures\Support\Listings\Listing cannot take this write, because a listener of Job refused its save. Why: The write stores its values through Job, and that model\'s own listeners refused them. Instead: Make the write satisfy the rules of Job.', $refusal->getMessage());
        $this->assertSame(['retrieved', 'vetoed'], $fired->all());
        $this->assertSame($before, $this->snapshot());

        Event::forget('eloquent.deleting: '.Job::class);

        $this->assertTrue($listing->delete());
        $this->assertSame(['retrieved', 'deleting', 'trashed', 'deleted'], $fired->slice(2)->values()->all());
        $this->assertSame($listing->deleted_at?->toDateTimeString(), Job::withTrashed()->where('legacy_id', $listing->id)->firstOrFail()->deleted_at?->toDateTimeString());
    }

    #[Test]
    public function it_keeps_the_timestamps_a_legacy_write_sets_when_it_soft_deletes(): void
    {
        $this->freezeSecond();
        $deleting = Date::now()->toDateTimeString();
        Event::listen('eloquent.deleting: '.Job::class, function (): void {
            $this->travel(1)->minutes();
        });
        $deleted = Listing::query()->findOrFail(1);
        $backdated = Listing::query()->findOrFail(3);

        $deleted->delete();
        $backdated->update(['deleted_at' => '2020-01-01 00:00:00']);
        $timestamps = fn (Listing $listing) => [$listing->deleted_at?->toDateTimeString(), $listing->updated_at?->toDateTimeString()];

        $this->assertSame([$deleting, $deleting], $timestamps(Listing::withTrashed()->findOrFail(1)));
        $this->assertSame([$deleting, $deleting], $timestamps($deleted));
        $this->assertFalse($deleted->isDirty());
        $this->assertSame($timestamps($backdated), $timestamps(Listing::withTrashed()->findOrFail(3)));
        $this->assertSame('2020-01-01 00:00:00', $backdated->deleted_at?->toDateTimeString());
    }

    #[Test]
    public function it_refuses_a_raw_expression_for_a_successor_attribute_and_reads_a_legacy_only_one_against_the_row_before_the_write(): void
    {
        $before = $this->snapshot();
        $elm = Listing::query()->findOrFail(3);
        $elm->setAttribute('title', DB::raw("'Listing ' || id"));

        $refused = $this->refusal(fn () => $elm->save());
        $unchanged = $this->snapshot();
        Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor', 'mls_number' => DB::raw("title || ' ' || id")]);

        $this->assertSame(Listing::class.'::$title cannot take a raw expression. Why: It is stored in jobs, and SQL written for the legacy table would read the columns of jobs instead. Instead: Write the computed value, or write the stored column through the successor model.', $refused->getMessage());
        $this->assertSame($before, $unchanged);
        $this->assertSame(['Oak Manor', 'Oak House 1'], [Listing::query()->findOrFail(1)->title, Listing::query()->findOrFail(1)->mls_number]);
    }

    #[Test]
    public function it_runs_a_guard_against_the_stored_record_with_only_the_written_values_applied(): void
    {
        $stale = Listing::query()->findOrFail(1);
        $current = Listing::query()->findOrFail(1);
        $current->update(['price' => '1300.00']);
        $current->update(['status' => ListingStatus::Sold]);

        $stale->update(['title' => 'Oak House Renamed']);

        $this->assertSame(['Oak House Renamed', '1300.00', ListingStatus::Sold], [Listing::query()->findOrFail(1)->title, Listing::query()->findOrFail(1)->price, Listing::query()->findOrFail(1)->status]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function concurrentGuardInputs(): iterable
    {
        foreach (['update' => 'update', 'increment' => 'increment'] as $label => $write) {
            yield "a related row, {$label}" => ["update places set city = 'Portland' where street = '1 Oak St'", $write];
            yield "a retained row, {$label}" => ["update listings_retained set mls_number = 'MLS-HELD' where mls_number = 'MLS-1'", $write];
        }
    }

    #[DataProvider('concurrentGuardInputs')]
    #[Test]
    public function it_runs_a_guard_against_the_values_a_concurrent_write_committed_to_a_table_the_write_does_not_change(string $concurrent, string $write): void
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
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute('price', fn (Attribute $attribute) => $attribute->from('price_cents')->dividedBy(100))
                        ->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place
                            ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                            ->attribute('city', fn (Attribute $attribute) => $attribute->from('city'))))
                    ->guard(
                        'held listing named',
                        fn (Model $after, Model $before) => $before->getAttribute('city') === 'Portland' || $before->getAttribute('mls_number') === 'MLS-HELD',
                        because: 'A held listing keeps its name.',
                        instead: 'Release the hold first.',
                    );
            }
        };
        $record = $listing->newQuery()->findOrFail(1);
        $locked = $this->lockConcurrently($concurrent);

        $refusal = $this->refusal(fn () => $write === 'update' ? $record->update(['title' => 'Oak Manor']) : $record->increment('price', 1, ['title' => 'Oak Manor']));

        $this->assertSame(["locked\n", ''], [$locked, $this->concurrentFailure()]);
        $this->assertStringEndsWith(" record 1 cannot take this write, because guard 'held listing named' refuses it. Why: A held listing keeps its name. Instead: Release the hold first.", $refusal->getMessage());
        $this->assertSame(['Oak House', 125050], [Job::query()->where('legacy_id', 1)->value('name'), Job::query()->where('legacy_id', 1)->value('price_cents')]);
    }

    #[Test]
    public function it_runs_a_guard_against_the_value_a_raw_expression_stores_in_a_legacy_only_attribute(): void
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
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job->attribute('title', fn (Attribute $attribute) => $attribute->from('name')))
                    ->guard('mls number kept', fn (Model $after) => $after->getAttribute('mls_number') === '', because: 'A listing keeps an MLS number.', instead: 'Write the new MLS number.');
            }
        };
        $before = $this->snapshot();

        $refusal = $this->refusal(fn () => $listing->newQuery()->findOrFail(1)->update(['mls_number' => DB::raw("''")]));
        $refused = $this->snapshot();
        $listing->newQuery()->findOrFail(3)->update(['mls_number' => DB::raw("'MLS-' || id || 'B'")]);

        $this->assertStringEndsWith(" record 1 cannot take this write, because guard 'mls number kept' refuses it. Why: A listing keeps an MLS number. Instead: Write the new MLS number.", $refusal->getMessage());
        $this->assertSame($before, $refused);
        $this->assertSame(['MLS-1', 'MLS-3B'], Listing::query()->orderBy('id')->pluck('mls_number')->all());
    }

    #[Test]
    public function it_rounds_a_fractional_record_increment_as_the_mass_increment_does(): void
    {
        Listing::query()->findOrFail(1)->increment('price', 0.005);
        Listing::query()->whereKey(3)->increment('price', 0.005);

        $this->assertSame([125051, 10000], DB::table('jobs')->whereIn('legacy_id', [1, 3])->orderBy('legacy_id')->pluck('price_cents')->all());
    }

    #[Test]
    public function it_refuses_a_change_to_a_read_only_attribute(): void
    {
        $before = $this->snapshot();
        $listing = Listing::query()->findOrFail(1);
        $listing->fill(['title' => 'Oak Manor', 'company_id' => 'a7c3e5f1-2b4d-4e6f-8a0b-1c2d3e4f5a6b']);

        $refusal = $this->refusal(fn () => $listing->save());

        $this->assertSame('Tests\Fixtures\Support\Listings\Listing record 1 cannot change $company_id. Why: A job never changes company. Instead: Leave company_id unchanged in writes through Listing.', $refusal->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_runs_the_transition_of_a_state_write_before_the_other_successor_saves(): void
    {
        $saves = [];
        Event::listen('eloquent.updated: *', function (string $event, array $models) use (&$saves): void {
            foreach ($models as $model) {
                $saves[] = [class_basename($model), array_keys(array_diff_key($model->getChanges(), ['updated_at' => true]))];
            }
        });
        $listing = Listing::query()->findOrFail(1);

        $listing->update(['title' => 'Oak Manor', 'status' => ListingStatus::Sold, 'street' => '2 Oak St']);

        $this->assertSame([['Job', ['lifecycle_status']], ['Job', ['name']], ['Place', ['street']], ['Listing', ['title', 'status', 'street']]], $saves);
        $this->assertSame([ListingStatus::Sold, 'Oak Manor', '2 Oak St'], [Listing::query()->findOrFail(1)->status, Listing::query()->findOrFail(1)->title, Listing::query()->findOrFail(1)->street]);
    }

    #[Test]
    public function it_refuses_a_write_its_guard_forbids(): void
    {
        $before = $this->snapshot();
        $sold = Listing::query()->findOrFail(3);
        $active = Listing::query()->findOrFail(1);

        $refusal = $this->refusal(fn () => $sold->update(['price' => '120.00']));
        $active->update(['price' => '1300.00']);

        $this->assertSame('Tests\Fixtures\Support\Listings\Listing record 3 cannot take this write, because guard \'sold price frozen\' refuses it. Why: A sold listing keeps the price it sold for. Instead: Change the price before the sale.', $refusal->getMessage());
        $this->assertSame($before['jobs'][2], $this->snapshot()['jobs'][2]);
        $this->assertSame(130000, Job::query()->where('legacy_id', 1)->value('price_cents'));
    }

    #[Test]
    public function it_refuses_a_declined_write_of_an_external_attribute_and_a_value_its_conversions_cannot_store(): void
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
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute('mls_number', fn (Attribute $attribute) => $attribute->external(read: fn (Collection $jobs) => $jobs, write: Attribute::declined(because: 'The MLS assigns its own numbers.')))
                        ->attribute('featured', fn (Attribute $attribute) => $attribute->fixed(false)));
            }
        };
        $external = $listing->newQuery()->findOrFail(3);
        $fixed = $listing->newQuery()->findOrFail(3);

        $externalRefusal = $this->refusal(fn () => $external->update(['title' => 'Elm House', 'mls_number' => 'MLS-3B']));
        $fixedRefusal = $this->refusal(fn () => $fixed->update(['title' => 'Elm House', 'featured' => true]));

        $this->assertSame(['The MLS assigns its own numbers.', true], [$externalRefusal->because, str_ends_with($externalRefusal->refused, '::$mls_number cannot take a write')]);
        $this->assertStringStartsWith('Leave mls_number unchanged in writes through ', $externalRefusal->instead);
        $this->assertStringEndsWith('::$featured cannot take a write. Why: The attribute always reads false, so it cannot store true. Instead: Write a value its conversions can store, or write the stored column through the successor model.', $fixedRefusal->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_creates_a_dependent_only_when_one_of_its_values_is_set(): void
    {
        $created = Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'street' => null, 'city' => null, 'latitude' => null]);
        $createdJob = Job::query()->where('legacy_id', $created->id)->firstOrFail();
        $listing = Listing::withTrashed()->findOrFail(2);
        $job = Job::withTrashed()->where('legacy_id', 2)->firstOrFail();

        $listing->update(['street' => '2 Pine St']);

        $this->assertSame([0, 0], [DB::table('places')->where('job_id', $createdJob->id)->count(), DB::table('job_sites')->where('job_id', $createdJob->id)->count()]);
        $this->assertSame([['2 Pine St', null, null]], DB::table('places')->where('job_id', $job->id)->get(['street', 'city', 'parent_id'])->map(fn (object $place) => array_values((array) $place))->all());
        $this->assertSame(0, DB::table('job_sites')->where('job_id', $job->id)->count());
        $this->assertSame('2 Pine St', Listing::withTrashed()->findOrFail(2)->street);
    }

    #[Test]
    public function it_writes_to_a_dependent_a_successor_listener_created_rather_than_create_a_second(): void
    {
        Event::listen('eloquent.created: '.Job::class, function (Job $job): void {
            $job->rootPlace()->create(['street' => 'To be confirmed']);
        });

        $listing = Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'street' => '9 Birch St', 'city' => 'Tacoma']);
        $job = Job::query()->where('legacy_id', $listing->id)->firstOrFail();

        $this->assertSame([['9 Birch St', 'Tacoma']], DB::table('places')->where('job_id', $job->id)->get(['street', 'city'])->map(fn (object $place) => array_values((array) $place))->all());
    }

    #[Test]
    public function it_writes_to_a_dependent_a_listener_of_the_primary_save_created_during_an_update_rather_than_create_a_second(): void
    {
        Event::listen('eloquent.saved: '.Job::class, function (Job $job): void {
            if ($job->rootPlace()->doesntExist()) {
                $job->rootPlace()->create(['street' => 'To be confirmed']);
            }
        });
        $listing = Listing::withTrashed()->findOrFail(2);

        $listing->update(['title' => 'Pine Lodge', 'street' => '2 Pine St']);
        $job = Job::withTrashed()->where('legacy_id', 2)->firstOrFail();

        $this->assertSame([['2 Pine St', null]], DB::table('places')->where('job_id', $job->id)->get(['street', 'city'])->map(fn (object $place) => array_values((array) $place))->all());
        $this->assertSame(['Pine Lodge', '2 Pine St'], [Listing::withTrashed()->findOrFail(2)->title, Listing::withTrashed()->findOrFail(2)->street]);
    }

    #[Test]
    public function it_saves_the_other_changes_of_a_write_that_soft_deletes_before_the_primary_deletes(): void
    {
        $saved = [];
        Event::listen('eloquent.saved: '.Job::class, function (Job $job) use (&$saved): void {
            $saved[] = [$job->name, $job->deleted_at === null];
        });
        $listing = Listing::query()->findOrFail(1);

        $listing->update(['title' => 'Oak Manor', 'deleted_at' => Date::now()]);
        $job = Job::withTrashed()->where('legacy_id', 1)->firstOrFail();

        $this->assertSame([['Oak Manor', true]], $saved);
        $this->assertSame(['Oak Manor', true], [$job->name, $job->trashed()]);
        $this->assertNull(Listing::query()->find(1));
    }

    #[Test]
    public function it_writes_nothing_for_a_record_its_primary_no_longer_serves(): void
    {
        $listing = Listing::query()->findOrFail(1);
        $merged = Job::query()->where('legacy_id', 3)->firstOrFail();
        Job::query()->where('legacy_id', 1)->update(['merged_into_job_id' => $merged->id]);
        $before = $this->snapshot();

        $saved = $listing->update(['title' => 'Oak Manor', 'mls_number' => 'MLS-1B']);

        $this->assertTrue($saved);
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_holds_each_successor_save_of_a_legacy_write_while_it_runs(): void
    {
        $held = [];
        Event::listen('eloquent.saving: *', function (string $event, array $models) use (&$held): void {
            foreach ($models as $model) {
                $held[] = [class_basename($model), $model instanceof Model && Applying::holds($model)];
            }
        });
        $listing = Listing::query()->findOrFail(1);

        $listing->update(['title' => 'Oak Manor', 'street' => '2 Oak St']);
        Job::query()->where('legacy_id', 1)->firstOrFail()->update(['name' => 'Oak Lodge']);

        $this->assertSame([['Listing', false], ['Job', true], ['Place', true], ['Job', false]], $held);
    }

    private function aliasedListing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return RecordWriterTest::aliasedMap($listing);
            }
        };
    }

    private function guardedAliasedListing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return RecordWriterTest::aliasedMap($listing)->guard('never', fn () => false, because: 'It reads every related row.', instead: 'Nothing.');
            }
        };
    }

    public static function aliasedMap(Compatibility $listing): Compatibility
    {
        return $listing
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->retainedIn('listings_retained', key: 'job_id')
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id'))
                ->related(fn (Job $job) => $job->hasOne(AliasedPlace::class), fn (Successor $place) => $place
                    ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                    ->attribute('city', fn (Attribute $attribute) => $attribute->from('city')))
                ->related(fn (Job $job) => $job->site(), fn (Successor $site) => $site
                    ->attribute('latitude', fn (Attribute $attribute) => $attribute->from('latitude'))));
    }

    private function lockConcurrently(string $sql): string
    {
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $concurrent = '$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).');'
            .' $pdo->beginTransaction(); $pdo->exec('.var_export($sql, true).'); echo "locked\n"; fflush(STDOUT); sleep(2); $pdo->commit();';
        $process = proc_open([PHP_BINARY, '-r', $concurrent], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            $this->fail('The concurrent writer did not start.');
        }

        $this->concurrent = [$process, $pipes];

        return (string) fgets($pipes[1]);
    }

    private function concurrentFailure(): string
    {
        if ($this->concurrent === null) {
            $this->fail('No concurrent writer is running.');
        }

        [$process, $pipes] = $this->concurrent;
        $failure = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $this->concurrent = null;

        return $failure;
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
