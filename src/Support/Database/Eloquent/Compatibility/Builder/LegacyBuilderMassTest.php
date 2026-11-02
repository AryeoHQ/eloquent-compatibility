<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(LegacyBuilder::class)]
final class LegacyBuilderMassTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_soft_deletes_restores_and_force_deletes_a_set_without_model_events(): void
    {
        $this->install();
        Date::setTestNow('2026-05-06 07:08:09');
        $events = $this->fakeModelEvents();

        $deleted = Listing::query()->where('price', '<', 2000)->delete();
        $trashed = Job::onlyTrashed()->orderBy('legacy_id')->pluck('legacy_id')->all();
        $restored = Listing::onlyTrashed()->where('title', '!=', 'Pine Flat')->restore(); // @phpstan-ignore staticMethod.dynamicCall
        $forced = Listing::withTrashed()->whereKey(2)->forceDelete();
        $dispatched = $events();

        $this->assertSame([2, [1, 2, 3], 2, 1], [$deleted, $trashed, $restored, $forced]);
        $this->assertSame([[1, null, '2026-05-06 07:08:09'], [3, null, '2026-05-06 07:08:09']], Job::withTrashed()->orderBy('legacy_id')->get()->map(fn (Job $job) => [$job->legacy_id, $job->deleted_at, $job->updated_at?->toDateTimeString()])->all());
        $this->assertSame([], $dispatched);
    }

    #[Test]
    public function it_touches_a_set_through_the_successor(): void
    {
        $this->install();
        Date::setTestNow('2026-05-06 07:08:09');
        $events = $this->fakeModelEvents();

        $touched = Listing::query()->where('price', '>', 1000)->touch();
        $dispatched = $events();

        $this->assertSame(1, $touched);
        $this->assertSame(['2026-05-06 07:08:09', '2026-01-05 08:00:00'], Job::query()->orderBy('legacy_id')->get()->map(fn (Job $job) => $job->updated_at?->toDateTimeString())->all());
        $this->assertSame([], $dispatched);
    }

    #[Test]
    public function it_increments_a_set_without_model_events(): void
    {
        $this->install();
        $events = $this->fakeModelEvents();

        Listing::query()->increment('price', 2, ['title' => 'Raised']);
        Listing::query()->decrementEach(['price' => 1]);
        $dispatched = $events();

        $this->assertSame([['Raised', 125150], ['Pine Flat', null], ['Raised', 10099]], Job::withTrashed()->orderBy('legacy_id')->get()->map(fn (Job $job) => [$job->name, $job->price_cents])->all());
        $this->assertSame([], $dispatched);
    }

    #[Test]
    public function it_increments_one_record_through_a_successor_save(): void
    {
        $this->install();
        Date::setTestNow('2026-05-06 07:08:09');
        $listing = Listing::query()->findOrFail(1);
        $saved = [];
        Event::listen('eloquent.saved: *', function (string $event) use (&$saved): void {
            $saved[] = substr($event, strlen('eloquent.saved: '));
        });

        $listing->increment('price', 2.5, ['mls_number' => 'MLS-1B']);
        $listing->decrement('latitude', 0.25);

        $job = Job::query()->where('legacy_id', 1)->firstOrFail();
        $this->assertSame(125300, $job->price_cents);
        $this->assertSame([47.3562], JobSite::query()->where('job_id', $job->id)->pluck('latitude')->all());
        $this->assertSame([['MLS-1B', true]], DB::table('listings_retained')->where('job_id', $job->id)->get(['mls_number', 'featured'])->map(fn (object $row) => array_values((array) $row))->all());
        $this->assertSame(['1253.00', 47.3562], [$listing->price, $listing->latitude]);
        $this->assertSame([Job::class, JobSite::class], array_values(array_filter($saved, fn (string $model) => $model !== Listing::class)));
        $this->assertSame(9999, Job::query()->where('legacy_id', 3)->value('price_cents'));
    }

    #[RequiresMethod(Model::class, 'incrementEach')]
    #[Test]
    public function it_increments_each_column_of_one_record_through_a_successor_save(): void
    {
        $this->install();
        $listing = Listing::query()->findOrFail(1);
        $saved = [];
        Event::listen('eloquent.saved: *', function (string $event) use (&$saved): void {
            $saved[] = substr($event, strlen('eloquent.saved: '));
        });

        $listing->incrementEach(['price' => 2, 'latitude' => 2]); // @phpstan-ignore staticMethod.dynamicCall
        $listing->decrementEach(['price' => 1, 'latitude' => 1]); // @phpstan-ignore staticMethod.dynamicCall

        $job = Job::query()->where('legacy_id', 1)->firstOrFail();
        $this->assertSame(125150, $job->price_cents);
        $this->assertSame([48.6062], JobSite::query()->where('job_id', $job->id)->pluck('latitude')->all());
        $this->assertSame(['1251.50', 48.6062], [$listing->price, $listing->latitude]);
        $this->assertSame([Job::class, JobSite::class, Job::class, JobSite::class], array_values(array_filter($saved, fn (string $model) => $model !== Listing::class)));
        $this->assertSame(9999, Job::query()->where('legacy_id', 3)->value('price_cents'));
    }

    #[Test]
    public function it_increments_one_record_inside_one_transaction_that_locks_the_primary_first(): void
    {
        $this->install();
        $listing = Listing::query()->findOrFail(1);
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            if (str_contains($query->sql, 'pg_class')) {
                return;
            }

            $statements[] = [$query->connection->transactionLevel(), (string) preg_replace('/^(select|insert|update)(?: .*? from| into)? "(\w+)".*?( for update)?$/s', '$1 $2$3', $query->sql)];
        });

        $listing->increment('price');

        $this->assertSame([[1, "select set_config('TimeZone', ?, true)"], [1, 'select jobs for update'], [1, 'select listings'], [1, 'update jobs']], $statements);
    }

    #[Test]
    public function it_increments_natively_before_install(): void
    {
        $listing = Listing::query()->findOrFail(1);

        $listing->increment('price', 2);
        Listing::query()->whereKey(3)->increment('price', 1);

        $this->assertSame(['1252.50', '100.99'], DB::table('listings')->whereIn('id', [1, 3])->orderBy('id')->pluck('price')->all());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function install(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(Listing::class);
    }

    /**
     * @return Closure(): list<string>
     */
    private function fakeModelEvents(): Closure
    {
        $models = [Listing::class, Job::class, Place::class, JobSite::class];
        $fake = Event::fake([fn (string $event) => str_starts_with($event, 'eloquent.') && in_array(substr($event, (int) strpos($event, ': ') + 2), $models, true)]);

        return fn (): array => array_values(array_map(fn (int|string $event) => (string) $event, array_keys($fake->dispatchedEvents())));
    }
}
