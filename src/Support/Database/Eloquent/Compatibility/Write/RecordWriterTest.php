<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
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
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Events\WriteIgnored;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\AliasedPlace;
use Tests\Fixtures\Support\Listings\ConfiguredJob;
use Tests\Fixtures\Support\Listings\ConfiguredSite;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\WriteConfiguredCity;
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
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
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

        $place = Place::query()->where('job_id', ListingTables::jobOf(1))->value('id');
        $this->assertSame("Tests\\Fixtures\\Support\\Listings\\Listing record 1 cannot take this write, because a listener of Place {$place} refused its save. Why: The write stores its values through Place, and that model's own listeners refused them. Instead: Make the write satisfy the rules of Place.", $update->getMessage());
        $this->assertSame('Tests\Fixtures\Support\Listings\Listing cannot take this write, because a listener of Place refused its save. Why: The write stores its values through Place, and that model\'s own listeners refused them. Instead: Make the write satisfy the rules of Place.', $insert->getMessage());
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_keeps_a_legacy_only_fact_through_a_successor_write_and_a_successor_only_fact_through_a_legacy_write(): void
    {
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
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

    /**
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function recordWritePaths(): iterable
    {
        yield 'an update' => [fn () => Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor'])];
        yield 'an increment' => [fn () => Listing::query()->findOrFail(1)->increment('price', 1, ['title' => 'Oak Manor'])];
        yield 'a save' => [function (): bool {
            $listing = Listing::query()->findOrFail(3);
            $listing->title = 'Elm Lodge';

            return $listing->save();
        }];
        yield 'a restore' => [function (): bool {
            $listing = Listing::withTrashed()->findOrFail(2);
            $listing->title = 'Pine Lodge';

            return $listing->restore(); // @phpstan-ignore staticMethod.dynamicCall
        }];
        yield 'a create' => [fn () => Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY])];
        yield 'a first or create' => [fn () => Listing::query()->firstOrCreate(['title' => 'Birch House'], ['company_id' => ListingTables::COMPANY])];
        yield 'an identifier change' => [fn () => Listing::withTrashed()->findOrFail(2)->update(['id' => 40, 'title' => 'Pine Manor'])];
    }

    /**
     * @param  Closure(): mixed  $write
     */
    #[DataProvider('recordWritePaths')]
    #[Test]
    public function it_refuses_and_rolls_back_every_record_write_path_whose_successor_listener_changes_a_written_value(Closure $write): void
    {
        Event::listen('eloquent.saving: '.Job::class, function (Job $job): void {
            $job->name = strtoupper($job->name);
        });
        $after = [];

        foreach (['created', 'updated', 'saved', 'restored'] as $event) {
            Event::listen("eloquent.{$event}: ".Listing::class, function () use (&$after, $event): void {
                $after[] = $event;
            });
        }

        $before = $this->snapshot();
        $refusal = $this->refusal($write);

        $this->assertStringContainsString(' cannot take this write of $title. Why: The write stores ', $refusal->getMessage());
        $this->assertStringContainsString(', so the record would not read back what was written.', $refusal->getMessage());
        $this->assertSame([], $after);
        $this->assertSame($before, $this->snapshot());
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

        $this->assertSame('Tests\Fixtures\Support\Listings\Listing record 1 cannot take this write, because a listener of Job '.ListingTables::jobOf(1).' refused its save. Why: The write stores its values through Job, and that model\'s own listeners refused them. Instead: Make the write satisfy the rules of Job.', $refusal->getMessage());
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

        $this->assertMatchesRegularExpression('/ cannot take this write, because a listener of JobSite( [0-9a-f-]{36})? refused its save\. Why: The write stores its values through JobSite, and that model\'s own listeners refused them\. Instead: Make the write satisfy the rules of JobSite\.$/', $refusal->getMessage());
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
            Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->delete();

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

        $this->assertSame("Tests\\Fixtures\\Support\\Listings\\Listing record {$listing->id} cannot take this write, because a listener of Job ".ListingTables::jobOf($listing->id)." refused its save. Why: The write stores its values through Job, and that model's own listeners refused them. Instead: Make the write satisfy the rules of Job.", $refusal->getMessage());
        $this->assertSame(['retrieved', 'vetoed'], $fired->all());
        $this->assertSame($before, $this->snapshot());

        Event::forget('eloquent.deleting: '.Job::class);

        $this->assertTrue($listing->delete());
        $this->assertSame(['retrieved', 'deleting', 'trashed', 'deleted'], $fired->slice(2)->values()->all());
        $this->assertSame($listing->deleted_at?->toDateTimeString(), Job::withTrashed()->whereKey(ListingTables::jobOf($listing->id))->firstOrFail()->deleted_at?->toDateTimeString());
    }

    #[Test]
    public function it_runs_the_successor_restore_and_its_veto_when_a_stale_legacy_record_restores_a_successor_another_writer_restored(): void
    {
        $listing = Listing::withTrashed()->findOrFail(2);
        DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: ListingTables::jobOf(2),
        )->update(['deleted_at' => null]);
        $before = $this->snapshot();
        /** @var Collection<int, string> $fired */
        $fired = new Collection;
        Event::listen('eloquent.*: '.Job::class, function (string $name) use ($fired): void {
            $fired->push(Str::before(Str::after($name, 'eloquent.'), ':'));
        });
        Event::listen('eloquent.restoring: '.Job::class, function () use ($fired): bool {
            $fired->push('vetoed');

            return false;
        });
        $listing->title = 'Pine Lodge';

        $refusal = $this->refusal(fn () => $listing->restore()); // @phpstan-ignore staticMethod.dynamicCall

        $this->assertSame('Tests\Fixtures\Support\Listings\Listing record 2 cannot take this write, because a listener of Job '.ListingTables::jobOf(2).' refused its save. Why: The write stores its values through Job, and that model\'s own listeners refused them. Instead: Make the write satisfy the rules of Job.', $refusal->getMessage());
        $this->assertSame(['retrieved', 'vetoed'], $fired->all());
        $this->assertSame($before, $this->snapshot());

        Event::forget('eloquent.restoring: '.Job::class);

        $this->assertTrue($listing->restore()); // @phpstan-ignore staticMethod.dynamicCall
        $this->assertSame(['retrieved', 'restoring', 'saving', 'updating', 'updated', 'saved', 'restored'], $fired->slice(2)->values()->all());
        $this->assertSame(['Pine Lodge', null], [Job::query()->whereKey(ListingTables::jobOf(2))->value('name'), Job::query()->whereKey(ListingTables::jobOf(2))->value('deleted_at')]);
    }

    #[Test]
    public function it_saves_the_successor_without_its_restore_events_when_a_legacy_listener_refused_the_restore_before_the_save(): void
    {
        $listing = Listing::withTrashed()->findOrFail(2);
        DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: ListingTables::jobOf(2),
        )->update(['deleted_at' => null]);
        /** @var Collection<int, string> $fired */
        $fired = new Collection;
        Event::listen('eloquent.*: '.Job::class, function (string $name) use ($fired): void {
            $fired->push(Str::before(Str::after($name, 'eloquent.'), ':'));
        });
        Event::listen('eloquent.saving: '.Listing::class, fn (): bool => false);

        $restored = $listing->restore(); // @phpstan-ignore staticMethod.dynamicCall
        Event::forget('eloquent.saving: '.Listing::class);
        $listing->title = 'Pine Lodge';
        $saved = $listing->save();

        $this->assertSame([false, true], [$restored, $saved]);
        $this->assertSame(['retrieved', 'saving', 'updating', 'updated', 'saved'], $fired->all());
        $this->assertSame('Pine Lodge', Job::query()->whereKey(ListingTables::jobOf(2))->value('name'));
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
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'price',
                            definition: fn (Attribute $attribute) => $attribute->from('price_cents')->dividedBy(100),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->rootPlace(),
                            successor: fn (Successor $place) => $place
                                ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                                ->attribute('city', fn (Attribute $attribute) => $attribute->from('city')),
                        ))
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
        $this->assertSame(['Oak House', 125050], [Job::query()->whereKey(ListingTables::jobOf(1))->value('name'), Job::query()->whereKey(ListingTables::jobOf(1))->value('price_cents')]);
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
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
                        name: 'title',
                        definition: fn (Attribute $attribute) => $attribute->from('name'),
                    ))
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

        $this->assertSame([125051, 10000], [DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: ListingTables::jobOf(1),
        )->value('price_cents'), DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: ListingTables::jobOf(3),
        )->value('price_cents')]);
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
    public function it_runs_the_transition_of_a_state_write_after_the_other_successor_saves(): void
    {
        $saves = [];
        Event::listen('eloquent.updated: *', function (string $event, array $models) use (&$saves): void {
            foreach ($models as $model) {
                $saves[] = [class_basename($model), array_keys(array_diff_key($model->getChanges(), ['updated_at' => true]))];
            }
        });
        $listing = Listing::query()->findOrFail(1);

        $listing->update(['title' => 'Oak Manor', 'status' => ListingStatus::Sold, 'street' => '2 Oak St']);

        $this->assertSame([['Job', ['name']], ['Place', ['street']], ['Job', ['lifecycle_status']], ['Listing', ['title', 'status', 'street']]], $saves);
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
        $this->assertSame(130000, Job::query()->whereKey(ListingTables::jobOf(1))->value('price_cents'));
    }

    #[Test]
    public function it_refuses_a_write_of_a_read_only_computed_attribute_and_a_change_of_a_read_only_fixed_attribute(): void
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
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'mls_number',
                            definition: fn (Attribute $attribute) => $attribute->computedAs(read: fn (Collection $jobs) => $jobs)->readOnly(because: 'The MLS assigns its own numbers.', ignoreWrites: false),
                        )
                        ->attribute(
                            name: 'featured',
                            definition: fn (Attribute $attribute) => $attribute->fixed(false)->readOnly(because: 'No listing is featured any more.', ignoreWrites: false),
                        ));
            }
        };
        $external = $listing->newQuery()->findOrFail(3);
        $fixed = $listing->newQuery()->findOrFail(3);

        $externalRefusal = $this->refusal(fn () => $external->update(['title' => 'Elm House', 'mls_number' => 'MLS-3B']));
        $fixedRefusal = $this->refusal(fn () => $fixed->update(['title' => 'Elm House', 'featured' => true]));

        $this->assertSame(['The MLS assigns its own numbers.', true], [$externalRefusal->because, str_ends_with($externalRefusal->refused, ' record 3 cannot change $mls_number')]);
        $this->assertStringStartsWith('Leave mls_number unchanged in writes through ', $externalRefusal->instead);
        $this->assertSame(['No listing is featured any more.', true], [$fixedRefusal->because, str_ends_with($fixedRefusal->refused, ' record 3 cannot change $featured')]);
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function it_creates_a_dependent_only_when_one_of_its_values_is_set(): void
    {
        $created = Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'street' => null, 'city' => null, 'latitude' => null]);
        $createdJob = Job::query()->whereKey(ListingTables::jobOf($created->id))->firstOrFail();
        $listing = Listing::withTrashed()->findOrFail(2);
        $job = Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail();

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
        $job = Job::query()->whereKey(ListingTables::jobOf($listing->id))->firstOrFail();

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
        $job = Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail();

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
        $job = Job::withTrashed()->whereKey(ListingTables::jobOf(1))->firstOrFail();

        $this->assertSame([['Oak Manor', true]], $saved);
        $this->assertSame(['Oak Manor', true], [$job->name, $job->trashed()]);
        $this->assertNull(Listing::query()->find(1));
    }

    #[Test]
    public function it_writes_nothing_for_a_record_its_primary_no_longer_serves(): void
    {
        $listing = Listing::query()->findOrFail(1);
        $merged = Job::query()->whereKey(ListingTables::jobOf(3))->firstOrFail();
        Job::query()->whereKey(ListingTables::jobOf(1))->update(['merged_into_job_id' => $merged->id]);
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
        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['name' => 'Oak Lodge']);

        $this->assertSame([['Listing', false], ['Job', true], ['Place', true], ['Listing', false], ['Job', false]], $held);
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
            ->retainedIn('listings_retained')
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->attribute(
                    name: 'title',
                    definition: fn (Attribute $attribute) => $attribute->from('name'),
                )
                ->attribute(
                    name: 'company_id',
                    definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                )
                ->related(
                    relation: fn (Job $job) => $job->hasOne(AliasedPlace::class),
                    successor: fn (Successor $place) => $place
                        ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                        ->attribute('city', fn (Attribute $attribute) => $attribute->from('city')),
                )
                ->related(
                    relation: fn (Job $job) => $job->site(),
                    successor: fn (Successor $site) => $site
                        ->attribute('latitude', fn (Attribute $attribute) => $attribute->from('latitude')),
                ));
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

    #[Test]
    public function it_discards_a_live_update_of_an_attribute_that_ignores_writes_and_dispatches_write_ignored_unless_the_value_is_null(): void
    {
        $listing = $this->companyIgnoringListing()::query()->findOrFail(1);
        /** @var list<WriteIgnored> $ignored */
        $ignored = [];
        Event::listen(WriteIgnored::class, function (WriteIgnored $event) use (&$ignored): void {
            $ignored[] = $event;
        });
        $other = '9f1e2d3c-4b5a-4678-9e0f-1a2b3c4d5e6f';

        $listing->update(['company_id' => $other, 'title' => 'Oak Manor']);
        $listing->update(['company_id' => null]);

        $this->assertCount(1, $ignored);
        $this->assertSame([Origin::Live, 'company_id', $other, ListingTables::COMPANY], [$ignored[0]->change->origin, $ignored[0]->change->attribute, $ignored[0]->value, $ignored[0]->change->original->getAttribute('company_id')]);
        $this->assertSame([ListingTables::COMPANY, 'Oak Manor'], array_values((array) DB::table('jobs')->where('id', ListingTables::jobOf(1))->first(['company_id', 'name'])));

        $refusal = $this->refusal(fn () => Listing::query()->findOrFail(1)->update(['company_id' => $other]));

        $this->assertSame('Tests\Fixtures\Support\Listings\Listing record 1 cannot change $company_id. Why: A job never changes company. Instead: Leave company_id unchanged in writes through Listing.', $refusal->getMessage());
        $this->assertSame(ListingTables::COMPANY, DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('company_id'));
    }

    #[Test]
    public function it_writes_a_legacy_only_update_to_the_retained_row_with_one_legacy_event_sequence_and_no_successor_save(): void
    {
        $listing = Listing::query()->findOrFail(1);
        $updatedAt = $listing->updated_at?->toDateTimeString();
        $jobUpdatedAt = DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('updated_at');
        $fired = [];

        foreach (['saving', 'updating', 'updated', 'saved'] as $event) {
            Event::listen("eloquent.{$event}: ".Listing::class, function () use (&$fired, $event): void {
                $fired[] = 'Listing '.$event;
            });
            Event::listen("eloquent.{$event}: ".Job::class, function () use (&$fired, $event): void {
                $fired[] = 'Job '.$event;
            });
        }

        $listing->update(['mls_number' => 'MLS-1B']);
        $loud = $fired;
        $fired = [];
        $listing->mls_number = 'MLS-1C';
        $listing->saveQuietly();

        $this->assertSame([['Listing saving', 'Listing updating', 'Listing updated', 'Listing saved'], []], [$loud, $fired]);
        $this->assertSame(['MLS-1C', $updatedAt, $updatedAt], [Listing::query()->findOrFail(1)->mls_number, Listing::query()->findOrFail(1)->updated_at?->toDateTimeString(), $listing->updated_at?->toDateTimeString()]);
        $this->assertSame($jobUpdatedAt, DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('updated_at'));
    }

    #[Test]
    public function it_keeps_the_updated_at_touch_of_a_legacy_only_write_when_updated_at_is_itself_legacy_only(): void
    {
        DB::statement('create table holders (id serial primary key, name text not null, note text, created_at timestamp(0), updated_at timestamp(0))');
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null, settings jsonb)');
        DB::table('holders')->insert([['name' => 'Oak', 'note' => 'a', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']]);
        $holder = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'holders';

            protected $guarded = [];

            public static function compatibility(Compatibility $holder): Compatibility
            {
                return $holder
                    ->retainedIn('holders_retained')
                    ->servedFrom(root: ConfiguredJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'name', definition: fn (Attribute $attribute) => $attribute->from('name')));
            }
        };

        try {
            Compatibility::prepare($holder::class);
            DB::statement('update holders set job_id = gen_random_uuid()');
            DB::statement('insert into configured_jobs (id, name) select job_id, name from holders');
            Compatibility::install(
                model: $holder::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            Date::setTestNow('2026-03-03 03:03:03');
            $record = $holder->newQuery()->findOrFail(1);
            $record->update(['note' => 'b']);
            $stamps = [DB::table('holders_retained')->value('updated_at'), (string) $record->getAttribute('updated_at')];
        } finally {
            Date::setTestNow();
            DB::statement('drop view if exists holders');
            DB::statement('drop table if exists holders, holders_retained, holders_backfill, configured_jobs cascade');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type IN ('holders'); END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }

        $this->assertSame(['2026-03-03 03:03:03', '2026-03-03 03:03:03'], $stamps);
    }

    #[Test]
    public function it_creates_the_root_with_a_supplied_successor_key_that_names_no_root_and_refuses_one_that_names_an_existing_root(): void
    {
        $existing = (string) ListingTables::jobOf(1);
        $reserved = '6a5b4c3d-2e1f-4a0b-9c8d-7e6f5a4b3c2d';

        $refusal = $this->refusal(fn () => Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'job_id' => $existing]));
        $created = Listing::query()->create(['title' => 'Cedar House', 'company_id' => ListingTables::COMPANY, 'job_id' => $reserved]);

        $this->assertSame("Tests\Fixtures\Support\Listings\Listing cannot run a create that names the existing jobs row {$existing} as its job_id through its compatibility map. Why: A legacy create creates its own Job, and a successor key that names an existing one would bind a second record to it. Instead: Write each record through Listing, or write the successor models directly.", $refusal->getMessage());
        $this->assertSame([$reserved, 'Cedar House'], [ListingTables::jobOf($created->id), DB::table('jobs')->where('id', $reserved)->value('name')]);
        $this->assertFalse(DB::table('jobs')->where('name', 'Birch House')->exists());
    }

    #[Test]
    public function it_increments_the_identifier_of_a_record_and_writes_its_extra_values_under_the_new_identifier(): void
    {
        $incremented = Listing::query()->create(['title' => 'Elm House', 'company_id' => ListingTables::COMPANY]);
        $id = (int) $incremented->id;

        $incremented->increment('id', 95, ['title' => 'Elm Lodge']);

        $this->assertSame([$id + 95, 'Elm Lodge'], [$incremented->id, $incremented->title]);
        $this->assertSame([$id + 95 => 'Elm Lodge'], Listing::query()->whereKey([$id, $id + 95])->pluck('title', 'id')->all());
    }

    #[Test]
    public function it_refuses_an_identifier_increment_whose_successor_listener_changes_its_extra_value_and_keeps_the_old_identifier(): void
    {
        $incremented = Listing::query()->create(['title' => 'Elm House', 'company_id' => ListingTables::COMPANY]);
        $id = (int) $incremented->id;
        Event::listen('eloquent.saving: '.Job::class, function (Job $job): void {
            $job->name = strtoupper($job->name);
        });

        $refusal = $this->refusal(fn () => $incremented->increment('id', 95, ['title' => 'Elm Lodge']));

        $this->assertStringContainsString(' record '.($id + 95)." cannot take this write of \$title. Why: The write stores 'Elm Lodge', and the view now serves title as 'ELM LODGE'", $refusal->getMessage());
        $this->assertSame([$id => 'Elm House'], Listing::query()->whereKey([$id, $id + 95])->pluck('title', 'id')->all());
    }

    #[Test]
    public function it_refuses_a_live_create_that_supplies_a_stored_less_read_only_attribute_and_creates_one_that_supplies_null(): void
    {
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

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
                        ->attribute(name: 'mls_number', definition: fn (Attribute $attribute) => $attribute->computedAs(read: fn (Collection $jobs) => $jobs->mapWithKeys(fn (Job $job) => [$job->getKey() => null]))->readOnly(because: 'The MLS assigns its own numbers.', ignoreWrites: false)));
            }
        };
        $retained = DB::table('listings_retained')->count();

        $refusal = $this->refusal(fn () => $listing->newQuery()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'mls_number' => 'MLS-9']));
        $created = $listing->newQuery()->create(['title' => 'Cedar House', 'company_id' => ListingTables::COMPANY, 'mls_number' => null]);

        $this->assertSame(['The MLS assigns its own numbers.', true], [$refusal->because, str_ends_with($refusal->refused, ' records cannot change $mls_number')]);
        $this->assertSame([false, $retained + 1], [DB::table('jobs')->where('name', 'Birch House')->exists(), DB::table('listings_retained')->count()]);
        $this->assertSame('Cedar House', DB::table('jobs')->where('id', ListingTables::jobOf($created->getKey()))->value('name'));
    }

    #[Test]
    public function it_assigns_a_read_only_attribute_that_ignores_writes_into_the_root_a_create_creates_and_dispatches_no_write_ignored(): void
    {
        $ignored = [];
        Event::listen(WriteIgnored::class, function (WriteIgnored $event) use (&$ignored): void {
            $ignored[] = $event->change->attribute;
        });

        $created = $this->companyIgnoringListing()::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame([[], ListingTables::COMPANY], [$ignored, DB::table('jobs')->where('id', ListingTables::jobOf($created->getKey()))->value('company_id')]);
    }

    #[Test]
    public function it_keeps_the_updated_at_a_caller_writes_beside_a_legacy_only_column(): void
    {
        $listing = Listing::query()->findOrFail(1);

        $listing->update(['mls_number' => 'MLS-1D', 'updated_at' => '2026-04-01 00:00:00']);

        $read = Listing::query()->findOrFail(1);
        $this->assertSame(['MLS-1D', '2026-04-01 00:00:00'], [$read->mls_number, $read->updated_at?->format('Y-m-d H:i:s')]);
    }

    #[Test]
    public function it_reads_filters_and_writes_an_attribute_that_falls_back_to_a_companion_column_once_installed(): void
    {
        DB::statement('create table holders (id serial primary key, name text not null, city text, created_at timestamp(0), updated_at timestamp(0))');
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null, settings jsonb, city text)');
        DB::statement('create table configured_sites (id uuid primary key default gen_random_uuid(), configured_job_id uuid not null unique references configured_jobs, options jsonb, city text)');
        DB::table('holders')->insert([['name' => 'Oak', 'city' => 'Seattle'], ['name' => 'Pine', 'city' => 'Tacoma']]);
        $holder = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'holders';

            protected $guarded = [];

            public static function compatibility(Compatibility $holder): Compatibility
            {
                return $holder
                    ->retainedIn('holders_retained')
                    ->servedFrom(root: ConfiguredJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'name', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'city', definition: fn (Attribute $attribute) => $attribute->from('city')->orFrom(successor: ConfiguredSite::class, column: 'city')->writtenBy(WriteConfiguredCity::class))
                        ->related(fn (ConfiguredJob $job) => $job->site()));
            }
        };

        try {
            Compatibility::prepare($holder::class);
            DB::statement('update holders set job_id = gen_random_uuid()');
            DB::statement('insert into configured_jobs (id, name, city) select job_id, name, city from holders');
            DB::statement("insert into configured_sites (configured_job_id, city) select job_id, 'Seattle' from holders where name = 'Oak'");
            Compatibility::install(
                model: $holder::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            DB::table('configured_jobs')->where('name', 'Oak')->update(['city' => null]);
            $read = $holder->newQuery()->orderBy('id')->pluck('city', 'id')->all();
            $found = $holder->newQuery()->where('city', 'Seattle')->pluck('id')->all();
            $holder->newQuery()->findOrFail(1)->update(['city' => 'Everett']);
            $written = [$holder->newQuery()->findOrFail(1)->getAttribute('city'), DB::table('configured_jobs')->where('name', 'Oak')->value('city'), DB::table('configured_sites')->value('city')];
        } finally {
            DB::statement('drop view if exists holders');
            DB::statement('drop table if exists holders, holders_retained, holders_backfill, configured_sites, configured_jobs cascade');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type IN ('holders'); END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }

        $this->assertSame([1 => 'Seattle', 2 => 'Tacoma'], $read);
        $this->assertSame([1], $found);
        $this->assertSame(['Everett', 'Everett', 'Seattle'], $written);
    }

    private function companyIgnoringListing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'title', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'created_at', definition: fn (Attribute $attribute) => $attribute->from('created_at'))
                        ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at'))
                        ->attribute(name: 'company_id', definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: true)));
            }
        };
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

        foreach (['jobs' => 'name', 'places' => 'street', 'job_sites' => 'latitude', 'listings_retained' => 'id'] as $table => $order) {
            $tables[$table] = DB::table($table)->orderBy($order)->get()->map(fn (object $row) => (array) $row)->all();
        }

        return $tables;
    }
}
