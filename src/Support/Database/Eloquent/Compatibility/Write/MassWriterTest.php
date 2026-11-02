<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(MassWriter::class)]
#[CoversClass(RetainedRows::class)]
final class MassWriterTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
        Schema::table('listings', function (Blueprint $table): void {
            $table->integer('views')->default(5);
        });
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
    public function it_writes_each_owning_table_once_and_fires_no_model_events(): void
    {
        Event::fake();

        $updated = Listing::query()->where('price', '>', 50)->update(['title' => 'Renamed', 'price' => '10.25', 'city' => 'Tacoma', 'latitude' => 1.5, 'mls_number' => 'MLS-X']);

        $this->assertSame(2, $updated);
        $this->assertSame([['Renamed', 1025], ['Renamed', 1025]], DB::table('jobs')->whereIn('legacy_id', [1, 3])->orderBy('legacy_id')->get(['name', 'price_cents'])->map(fn (object $job) => array_values((array) $job))->all());
        $this->assertSame(['Pine Flat', null], array_values((array) DB::table('jobs')->where('legacy_id', 2)->first(['name', 'price_cents'])));
        $this->assertSame(['Tacoma', 'Tacoma'], DB::table('places')->join('jobs', 'jobs.id', '=', 'places.job_id')->orderBy('jobs.legacy_id')->pluck('places.city')->all());
        $this->assertSame([1.5, 1.5], DB::table('job_sites')->join('jobs', 'jobs.id', '=', 'job_sites.job_id')->orderBy('jobs.legacy_id')->pluck('job_sites.latitude')->all());
        $this->assertSame(['MLS-X', 'MLS-X'], DB::table('listings_retained')->join('jobs', 'jobs.id', '=', 'listings_retained.job_id')->whereIn('jobs.legacy_id', [1, 3])->orderBy('jobs.legacy_id')->pluck('mls_number')->all());
        $this->assertSame([], array_values(array_filter(array_keys(Event::dispatchedEvents()), fn (string $event) => str_starts_with($event, 'eloquent.'))));
    }

    #[Test]
    public function it_runs_the_same_number_of_statements_for_one_matching_row_and_for_a_hundred(): void
    {
        DB::table('jobs')->insert(array_fill(0, 100, ['name' => 'Bulk', 'company_id' => ListingTables::COMPANY, 'price_cents' => 100]));
        DB::table('jobs')->insert(['name' => 'Single', 'company_id' => ListingTables::COMPANY, 'price_cents' => 100]);
        $write = ['price' => '2.00', 'street' => '5 Elm St', 'latitude' => 2.5, 'mls_number' => 'MLS-B'];

        $one = $this->statements(fn () => Listing::query()->where('title', 'Single')->update($write));
        $hundred = $this->statements(fn () => Listing::query()->where('title', 'Bulk')->update($write));

        $this->assertSame([1, 100], [$one[0], $hundred[0]]);
        $this->assertSame($one[1], $hundred[1]);
        $this->assertSame(101, DB::table('jobs')->where('price_cents', 200)->count());
        $this->assertSame(101, DB::table('places')->where('street', '5 Elm St')->whereNull('parent_id')->count());
        $this->assertSame(101, DB::table('job_sites')->where('latitude', 2.5)->count());
        $this->assertSame(101, DB::table('listings_retained')->where('mls_number', 'MLS-B')->count());
    }

    #[Test]
    public function it_keeps_reaching_every_table_when_the_write_changes_the_attribute_it_filters_on(): void
    {
        $updated = Listing::query()->where('title', 'Oak House')->where('price', '>', 1000)->update(['title' => 'Oak Manor', 'price' => '1.00', 'street' => '2 Oak St', 'mls_number' => 'MLS-1B']);
        $job = Job::query()->where('legacy_id', 1)->firstOrFail();

        $this->assertSame(1, $updated);
        $this->assertSame(['Oak Manor', 100], [$job->name, $job->price_cents]);
        $this->assertSame(['2 Oak St'], DB::table('places')->where('job_id', $job->id)->pluck('street')->all());
        $this->assertSame(['MLS-1B'], DB::table('listings_retained')->where('job_id', $job->id)->pluck('mls_number')->all());
    }

    #[Test]
    public function it_creates_the_related_rows_a_matching_record_lacks(): void
    {
        Listing::withTrashed()->whereKey([1, 2])->update(['street' => '7 Ash St', 'latitude' => 3.5]);
        $pine = Job::withTrashed()->where('legacy_id', 2)->firstOrFail();

        $this->assertSame([['7 Ash St', null, null]], DB::table('places')->where('job_id', $pine->id)->get(['street', 'city', 'parent_id'])->map(fn (object $place) => array_values((array) $place))->all());
        $this->assertNotNull(DB::table('places')->where('job_id', $pine->id)->value('created_at'));
        $this->assertSame([3.5], DB::table('job_sites')->where('job_id', $pine->id)->pluck('latitude')->all());
        $this->assertSame(2, DB::table('places')->where('street', '7 Ash St')->count());
        $this->assertSame(['7 Ash St', 3.5], [Listing::withTrashed()->findOrFail(2)->street, Listing::withTrashed()->findOrFail(2)->latitude]);
    }

    #[Test]
    public function it_creates_no_related_row_for_a_null_value(): void
    {
        Listing::withTrashed()->whereKey(2)->update(['street' => null, 'latitude' => null]);
        $pine = Job::withTrashed()->where('legacy_id', 2)->firstOrFail();

        $this->assertSame([0, 0], [DB::table('places')->where('job_id', $pine->id)->count(), DB::table('job_sites')->where('job_id', $pine->id)->count()]);
    }

    #[Test]
    public function it_adds_an_amount_through_a_linear_conversion_and_to_a_legacy_only_column(): void
    {
        $oak = Job::query()->where('legacy_id', 1)->firstOrFail();
        DB::table('listings_retained')->where('job_id', $oak->id)->update(['views' => 40]);
        $elm = Job::query()->where('legacy_id', 3)->firstOrFail();
        DB::table('listings_retained')->where('job_id', $elm->id)->delete();

        Listing::withTrashed()->increment('price', 1.5);
        Listing::withTrashed()->incrementEach(['views' => 2], ['mls_number' => 'MLS-Z']);
        Listing::withTrashed()->decrement('latitude', 0.5);
        Listing::query()->findOrFail(3)->increment('views', 3);

        $this->assertSame([1, 125200, 2, null, 3, 10149], DB::table('jobs')->orderBy('legacy_id')->get(['legacy_id', 'price_cents'])->flatMap(fn (object $job) => array_values((array) $job))->all());
        $this->assertSame([42, 7, 10], Listing::withTrashed()->orderBy('id')->pluck('views')->all());
        $this->assertSame(['MLS-Z', 'MLS-Z', 'MLS-Z'], Listing::withTrashed()->orderBy('id')->pluck('mls_number')->all());
        $this->assertSame([47.1062, null, -122.8321], Listing::withTrashed()->orderBy('id')->pluck('latitude')->all());
    }

    #[Test]
    public function it_refuses_an_increment_through_a_conversion_that_is_not_linear(): void
    {
        $mapped = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job->attribute('status', fn (Attribute $attribute) => $attribute->from('lifecycle_status')->mapping(['drafting' => 'Draft', 'live' => 'Active', 'closed' => 'Sold'])));
            }
        };

        $refused = $this->refusal(fn () => $mapped->newQuery()->increment('status'));

        $this->assertSame($mapped::class.' cannot run an increment of $status through its compatibility map. Why: Its conversions are not linear, so an amount added to it has no one stored amount. Instead: Write each record through '.class_basename($mapped::class).', or write the successor models directly.', $refused->getMessage());
        $this->assertSame(['live', 'drafting', 'closed'], DB::table('jobs')->orderBy('legacy_id')->pluck('lifecycle_status')->all());
    }

    #[Test]
    public function it_refuses_a_raw_expression_for_a_converted_attribute_and_a_write_of_a_read_only_attribute(): void
    {
        $raw = $this->refusal(fn () => Listing::query()->update(['price' => DB::raw('price + 1')]));
        $readOnly = $this->refusal(fn () => Listing::query()->update(['company_id' => ListingTables::COMPANY]));

        $this->assertSame(Listing::class.'::$price cannot take a raw expression. Why: Its conversions read stored values, and SQL written for the legacy value does not pass through them. Instead: Write the computed value, or write the stored column through the successor model.', $raw->getMessage());
        $this->assertSame(Listing::class.' records cannot change $company_id. Why: A job never changes company. Instead: Leave company_id unchanged in writes through Listing.', $readOnly->getMessage());
        $this->assertSame([125050, 9999], DB::table('jobs')->whereNotNull('price_cents')->orderBy('legacy_id')->pluck('price_cents')->all());
    }

    #[Test]
    public function it_refuses_a_mass_write_of_an_attribute_that_runs_transitions(): void
    {
        $refused = $this->refusal(fn () => Listing::query()->where('status', ListingStatus::Active)->update(['title' => 'Oak Manor', 'status' => ListingStatus::Sold]));

        $this->assertSame(Listing::class.'::$status cannot take a mass write. Why: It is declared transitions(), so each record\'s change runs its successor\'s state-machine trigger, and a set-based statement cannot run a trigger per row. Instead: Write the records one at a time through Listing.', $refused->getMessage());
        $this->assertSame([['Oak House', 'live'], ['Pine Flat', 'drafting'], ['Elm Cottage', 'closed']], DB::table('jobs')->orderBy('legacy_id')->get(['name', 'lifecycle_status'])->map(fn (object $job) => array_values((array) $job))->all());
    }

    #[Test]
    public function it_refuses_a_raw_expression_for_a_successor_attribute_and_reads_a_legacy_only_one_against_the_row_before_the_write(): void
    {
        $refused = $this->refusal(fn () => Listing::query()->whereKey(3)->update(['title' => DB::raw("'Listing ' || id")]));
        $updated = Listing::query()->whereKey(1)->update(['title' => 'Oak Manor', 'mls_number' => DB::raw("title || ' ' || id")]);

        $this->assertSame(Listing::class.'::$title cannot take a raw expression. Why: It is stored in jobs, and SQL written for the legacy table would read the columns of jobs instead. Instead: Write the computed value, or write the stored column through the successor model.', $refused->getMessage());
        $this->assertSame('Elm Cottage', Job::query()->where('legacy_id', 3)->value('name'));
        $this->assertSame([1, 'Oak Manor', 'Oak House 1'], [$updated, Listing::query()->findOrFail(1)->title, Listing::query()->findOrFail(1)->mls_number]);
    }

    #[Test]
    public function it_skips_a_record_a_concurrent_write_moved_out_of_the_filter_while_the_write_waited_for_its_lock(): void
    {
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $concurrent = '$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).');'
            .' $pdo->beginTransaction(); $pdo->exec("update jobs set name = \'Changed\' where legacy_id = 1"); echo "locked\n"; fflush(STDOUT); sleep(2); $pdo->commit();';
        $process = proc_open([PHP_BINARY, '-r', $concurrent], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $locked = fgets($pipes[1]);

        $updated = Listing::query()->where('title', 'Oak House')->update(['title' => 'Mass']);
        $failure = stream_get_contents($pipes[2]);
        proc_close($process);

        $this->assertSame(["locked\n", ''], [$locked, $failure]);
        $this->assertSame([0, 'Changed'], [$updated, Listing::query()->findOrFail(1)->title]);
    }

    #[Test]
    public function it_writes_only_the_records_a_limited_query_chooses(): void
    {
        $updated = Listing::query()->orderBy('price')->limit(1)->update(['title' => 'Cheapest', 'mls_number' => 'MLS-C']); // @phpstan-ignore staticMethod.dynamicCall

        $this->assertSame(1, $updated);
        $this->assertSame([['Oak House', 'MLS-1'], ['Cheapest', 'MLS-C']], Listing::query()->orderBy('id')->get(['title', 'mls_number'])->map(fn (Listing $listing) => [$listing->title, $listing->mls_number])->all());
    }

    #[Test]
    public function it_refuses_to_create_a_missing_dependent_whose_key_has_no_database_default(): void
    {
        DB::statement('alter table places alter column id drop default');

        $refused = $this->refusal(fn () => Listing::withTrashed()->whereKey([1, 2])->update(['title' => 'Renamed', 'street' => '2 Pine St']));
        Listing::query()->whereKey(1)->update(['street' => '2 Oak St']);

        $this->assertSame(Listing::class.' cannot write $street in one statement to records that have no places row. Why: A set-based write creates the missing places rows in SQL, and places.id has no database default to key them. Instead: Write the records one at a time through Listing, or give places.id a database default.', $refused->getMessage());
        $this->assertSame(['Oak House', 'Pine Flat'], Job::withTrashed()->whereIn('legacy_id', [1, 2])->orderBy('legacy_id')->pluck('name')->all());
        $this->assertSame(['2 Oak St', '3 Elm St'], DB::table('places')->orderBy('street')->pluck('street')->all());
    }

    #[Test]
    public function it_binds_no_matched_key_into_a_statement(): void
    {
        DB::table('jobs')->insert(array_fill(0, 100, ['name' => 'Bulk', 'company_id' => ListingTables::COMPANY, 'price_cents' => 100]));
        $keys = DB::table('jobs')->where('name', 'Bulk')->pluck('id')->all();

        [$updated] = $this->statements(fn () => Listing::query()->where('title', 'Bulk')->update(['price' => '2.00', 'street' => '5 Elm St', 'latitude' => 2.5, 'mls_number' => 'MLS-B']));
        $bound = (string) json_encode(array_column(DB::getQueryLog(), 'bindings'));

        $this->assertSame(100, $updated);
        $this->assertSame([], array_values(array_filter($keys, fn (mixed $key) => str_contains($bound, (string) $key))));
    }

    #[Test]
    public function it_deletes_the_primary_rows_hard_in_one_statement(): void
    {
        $oak = Job::query()->where('legacy_id', 1)->firstOrFail();
        Listing::query()->where('price', '>', 1000)->get();
        [$count, $statements] = $this->statements(fn () => Listing::query()->where('price', '>', 1000)->forceDelete());

        $this->assertSame(1, $count);
        $this->assertSame(1, $statements);
        $this->assertSame([0, 0, 0], [Job::withTrashed()->whereKey($oak->id)->count(), DB::table('places')->where('job_id', $oak->id)->count(), DB::table('listings_retained')->where('job_id', $oak->id)->count()]);
        $this->assertSame(2, Job::withTrashed()->count());
    }

    /**
     * @return array{mixed, int}
     */
    private function statements(Closure $write): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $write();
        } finally {
            DB::disableQueryLog();
        }

        return [$result, count(array_filter(DB::getQueryLog(), fn (array $query) => ! str_contains((string) $query['query'], 'pg_class')))];
    }

    private function refusal(Closure $write): CompatibilityWriteRefused
    {
        try {
            $write();
        } catch (CompatibilityWriteRefused $refused) {
            return $refused;
        }

        $this->fail('The write was not refused.');
    }
}
