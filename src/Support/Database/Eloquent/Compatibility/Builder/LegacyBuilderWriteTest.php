<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(LegacyBuilder::class)]
final class LegacyBuilderWriteTest extends TestCase
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
    public function it_creates_the_successor_rows_for_a_legacy_insert(): void
    {
        $this->install();
        $events = $this->fakeJobEvents();

        $listing = Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'price' => '450.25', 'street' => '9 Birch St', 'city' => 'Tacoma', 'latitude' => 47.25, 'mls_number' => 'MLS-9', 'featured' => true]);
        $dispatched = $events();
        $job = $this->job($listing->id);

        $this->assertSame(4, $listing->id);
        $this->assertSame(['Birch House', ListingTables::COMPANY, 45025, 'drafting'], [$job->name, $job->company_id, $job->price_cents, $job->getRawOriginal('lifecycle_status')]);
        $this->assertSame([['9 Birch St', 'Tacoma', null]], DB::table('places')->where('job_id', $job->id)->get(['street', 'city', 'parent_id'])->map(fn (object $place) => array_values((array) $place))->all());
        $this->assertSame([47.25], DB::table('job_sites')->where('job_id', $job->id)->pluck('latitude')->all());
        $this->assertSame([['MLS-9', true]], $this->retained($job->id));
        $this->assertSame(['Birch House', '450.25', '9 Birch St', 'Tacoma', 47.25, 'MLS-9', true], $this->served($listing->id, ['title', 'price', 'street', 'city', 'latitude', 'mls_number', 'featured']));
        $this->assertSame(['saving', 'creating', 'created', 'saved'], $dispatched);
    }

    #[Test]
    public function it_updates_the_successor_rows_for_a_legacy_save(): void
    {
        $this->install();
        $listing = Listing::query()->findOrFail(1);
        $listing->fill(['title' => 'Oak Manor', 'price' => '1300.00', 'city' => 'Bellevue', 'latitude' => 47.61, 'mls_number' => 'MLS-1B']);
        $events = $this->fakeJobEvents();

        $listing->save();
        $dispatched = $events();
        $job = $this->job(1);

        $this->assertSame(['Oak Manor', 130000], [$job->name, $job->price_cents]);
        $this->assertSame([['1 Oak St', 'Bellevue']], DB::table('places')->where('job_id', $job->id)->get(['street', 'city'])->map(fn (object $place) => array_values((array) $place))->all());
        $this->assertSame([47.61], DB::table('job_sites')->where('job_id', $job->id)->pluck('latitude')->all());
        $this->assertSame([['MLS-1B', true]], $this->retained($job->id));
        $this->assertSame(['Oak Manor', '1300.00', 'Bellevue', 47.61, 'MLS-1B', true], $this->served(1, ['title', 'price', 'city', 'latitude', 'mls_number', 'featured']));
        $this->assertSame(['retrieved', 'saving', 'updating', 'updated', 'saved'], $dispatched);
    }

    #[Test]
    public function it_soft_deletes_the_primary_for_a_legacy_delete(): void
    {
        $this->install();
        $this->freezeSecond();
        $listing = Listing::query()->findOrFail(1);
        $events = $this->fakeJobEvents();

        $listing->delete();
        $dispatched = $events();
        $job = $this->job(1);

        $this->assertSame([Date::now()->toDateTimeString(), Date::now()->toDateTimeString()], [$job->deleted_at?->toDateTimeString(), $job->updated_at?->toDateTimeString()]);
        $this->assertSame(1, DB::table('places')->where('job_id', $job->id)->count());
        $this->assertSame([['MLS-1', true]], $this->retained($job->id));
        $this->assertNull(Listing::query()->find(1));
        $this->assertSame(['retrieved', 'deleting', 'trashed', 'deleted'], $dispatched);
    }

    #[Test]
    public function it_restores_the_primary_for_a_legacy_restore(): void
    {
        $this->install();
        $listing = Listing::withTrashed()->findOrFail(2);
        $events = $this->fakeJobEvents();

        $listing->restore(); // @phpstan-ignore staticMethod.dynamicCall
        $dispatched = $events();
        $job = $this->job(2);

        $this->assertNull($job->deleted_at);
        $this->assertSame([[null, false]], $this->retained($job->id));
        $this->assertSame('Pine Flat', Listing::query()->findOrFail(2)->title);
        $this->assertSame(['retrieved', 'restoring', 'saving', 'updating', 'updated', 'saved', 'restored'], $dispatched);
    }

    #[Test]
    public function it_force_deletes_the_primary_and_the_rows_that_reference_it_for_a_legacy_force_delete(): void
    {
        $this->install();
        $listing = Listing::query()->findOrFail(1);
        $job = $this->job(1);
        $events = $this->fakeJobEvents();

        $listing->forceDelete();
        $dispatched = $events();

        $this->assertSame(0, Job::withTrashed()->whereKey($job->id)->count());
        $this->assertSame([0, 0, 0], [DB::table('places')->where('job_id', $job->id)->count(), DB::table('job_sites')->where('job_id', $job->id)->count(), DB::table('listings_retained')->where('job_id', $job->id)->count()]);
        $this->assertNull(Listing::withTrashed()->find(1));
        $this->assertSame(['retrieved', 'forceDeleting', 'deleting', 'deleted', 'forceDeleted'], $dispatched);
    }

    #[Test]
    public function it_force_deletes_the_primary_for_a_legacy_model_that_deletes_hard(): void
    {
        $this->install();
        $hard = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job->attribute('title', fn (Attribute $attribute) => $attribute->from('name')));
            }
        };
        $job = $this->job(1);
        $events = $this->fakeJobEvents();

        $hard->newQuery()->findOrFail(1)->delete();
        $dispatched = $events();

        $this->assertSame(0, Job::withTrashed()->whereKey($job->id)->count());
        $this->assertSame(0, DB::table('listings_retained')->where('job_id', $job->id)->count());
        $this->assertSame(['retrieved', 'forceDeleting', 'deleting', 'deleted', 'forceDeleted'], $dispatched);
    }

    #[Test]
    public function it_runs_a_record_write_in_one_transaction_that_locks_the_primary_row_first(): void
    {
        $this->install();
        $listing = Listing::query()->findOrFail(1);
        $listing->fill(['title' => 'Oak Manor', 'street' => '2 Oak St', 'mls_number' => 'MLS-1B']);
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = [$query->connection->transactionLevel(), self::statement($query->sql)];
        });

        $listing->save();

        $this->assertSame([
            [1, 'select jobs for update'],
            [1, 'select listings'],
            [1, 'select places for update'],
            [1, 'update jobs'],
            [1, 'update places'],
            [1, 'insert listings_retained'],
        ], $statements);
    }

    #[Test]
    public function it_writes_a_legacy_insert_in_a_fixed_order(): void
    {
        $this->install();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            if (! str_contains($query->sql, 'pg_class')) {
                $statements[] = self::statement($query->sql);
            }
        });

        Listing::query()->create(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'street' => '9 Birch St', 'latitude' => 47.25, 'mls_number' => 'MLS-9']);

        $this->assertSame([
            'insert jobs',
            'select jobs',
            'select places for update',
            'select job_sites for update',
            'insert places',
            'insert job_sites',
            'insert listings_retained',
        ], $statements);
    }

    private function install(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(Listing::class);
    }

    /**
     * @return \Closure(): list<string>
     */
    private function fakeJobEvents(): \Closure
    {
        $suffix = ': '.Job::class;
        $fake = Event::fake([fn (string $event) => str_starts_with($event, 'eloquent.') && str_ends_with($event, $suffix)]);

        return function () use ($fake, $suffix): array {
            $events = [];

            foreach (array_keys($fake->dispatchedEvents()) as $event) {
                $events[] = substr((string) $event, strlen('eloquent.'), -strlen($suffix));
            }

            foreach (['retrieved', 'saving', 'creating', 'created', 'saved', 'updating', 'updated', 'deleting', 'deleted', 'trashed', 'restoring', 'restored', 'forceDeleting', 'forceDeleted'] as $event) {
                in_array($event, $events, true) ? Event::assertDispatched("eloquent.{$event}{$suffix}") : Event::assertNotDispatched("eloquent.{$event}{$suffix}");
            }

            return $events;
        };
    }

    private static function statement(string $sql): string
    {
        return (string) preg_replace('/^(select|insert|update|delete)(?: .*? from| into)? "(\w+)".*?( for update)?$/s', '$1 $2$3', $sql);
    }

    private function job(int $legacyId): Job
    {
        return Job::withTrashed()->where('legacy_id', $legacyId)->firstOrFail();
    }

    /**
     * @return list<list<mixed>>
     */
    private function retained(string $jobId): array
    {
        return DB::table('listings_retained')->where('job_id', $jobId)->get(['mls_number', 'featured'])->map(fn (object $row) => array_values((array) $row))->all();
    }

    /**
     * @param  list<string>  $attributes
     * @return list<mixed>
     */
    private function served(int $id, array $attributes): array
    {
        $listing = Listing::withTrashed()->findOrFail($id);

        return array_map(fn (string $attribute) => $listing->getAttribute($attribute), $attributes);
    }
}
