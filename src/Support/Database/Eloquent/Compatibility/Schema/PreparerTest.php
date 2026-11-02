<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(Preparer::class)]
final class PreparerTest extends TestCase
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
    public function it_adds_a_unique_successor_column_for_each_legacy_identifier_and_an_index_for_each_at_most_one(): void
    {
        Compatibility::prepare(Listing::class);

        $this->assertEquals(
            ['legacy_id' => new Column('integer', "nextval('listings_id_seq'::regclass)", true), 'legacy_uuid' => new Column('uuid', 'gen_random_uuid()', true)],
            array_intersect_key((new ColumnCatalog(DB::connection()))->of('jobs'), ['legacy_id' => true, 'legacy_uuid' => true]),
        );
        $this->assertSame(
            ['jobs_legacy_id_unique' => 'UNIQUE (legacy_id)', 'jobs_legacy_uuid_unique' => 'UNIQUE (legacy_uuid)'],
            array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'jobs'::regclass and contype = 'u' order by conname"), 'definition', 'conname'),
        );
        $this->assertSame(
            'CREATE UNIQUE INDEX places_listings_at_most_one ON public.places USING btree (job_id) WHERE ((parent_id IS NULL) AND (deleted_at IS NULL))',
            DB::scalar("select indexdef from pg_indexes where indexname = 'places_listings_at_most_one'"),
        );
    }

    #[Test]
    public function it_gives_a_job_created_before_install_a_legacy_id_from_the_legacy_sequence_and_a_fresh_legacy_uuid(): void
    {
        Compatibility::prepare(Listing::class);

        DB::table('jobs')->insert(['name' => 'Birch Barn', 'company_id' => ListingTables::COMPANY]);
        DB::table('listings')->insert(['title' => 'Cedar Cabin', 'company_id' => ListingTables::COMPANY]);
        $job = DB::selectOne("select id, legacy_id, legacy_uuid from jobs where name = 'Birch Barn'");

        $this->assertSame([4, 5], [$job->legacy_id, DB::scalar("select id from listings where title = 'Cedar Cabin'")]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $job->legacy_uuid);
        $this->assertNotSame($job->id, $job->legacy_uuid);
    }

    #[Test]
    public function it_refuses_an_at_most_one_index_over_rows_that_already_break_it_and_names_the_first_keys(): void
    {
        $job = DB::table('jobs')->insertGetId(['name' => 'Oak House', 'company_id' => ListingTables::COMPANY]);
        DB::table('places')->insert([['job_id' => $job, 'street' => '1 Oak St'], ['job_id' => $job, 'street' => '1 Oak St, rear']]);

        try {
            Compatibility::prepare(Listing::class);
            $this->fail('prepare() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'listings' cannot prepare: atMostOne() on the relation from Job to Place allows one places row for each job_id, and 1 values of job_id have more; the first are {$job}", $exception->getMessage());
        }

        $this->assertNull(DB::scalar("select indexname from pg_indexes where indexname = 'places_listings_at_most_one'"));
    }

    #[Test]
    public function it_refuses_to_create_an_index_concurrently_inside_a_transaction(): void
    {
        $this->expectExceptionObject(new LogicException('Compatibility::prepare() runs 2 statements after its own transaction commits, and the connection is already inside a transaction. Run it from a migration that sets public $withinTransaction = false.'));

        DB::transaction(fn () => Compatibility::prepare(Listing::class));
    }

    #[Test]
    public function it_refuses_an_integer_identifier_whose_legacy_column_draws_from_no_sequence(): void
    {
        DB::statement('alter table listings alter column id drop default');

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'listings.id draws from no sequence, and integer() shares the legacy table\'s own sequence'));

        Compatibility::prepare(Listing::class);
    }

    #[Test]
    public function it_refuses_an_integer_identifier_whose_legacy_column_is_an_identity(): void
    {
        DB::statement('alter table listings alter column id drop default');
        DB::statement('alter table listings alter column id add generated by default as identity');

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'listings.id is an identity column, and integer() shares a sequence that must outlive the legacy column'));

        Compatibility::prepare(Listing::class);
    }

    #[Test]
    public function it_refuses_at_most_one_on_a_relation_whose_parent_holds_the_key(): void
    {
        $map = (new Compatibility(Listing::class))
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(Place::class, fn (Successor $place) => $place
                ->related(fn (Place $place) => $place->job(), fn (Successor $job) => $job->atMostOne(because: 'A place has one job.')))
            ->resolve();

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'atMostOne() on the relation from Place to Job needs no index, because the places row names one jobs row by its key'));

        Statements::prepare($map, DB::connection());
    }

    #[Test]
    public function it_refuses_an_index_name_longer_than_postgresql_keeps(): void
    {
        $legacy = new class extends Model
        {
            protected $table = 'listings_of_the_vendor_storefront_before_the_redesign';
        };
        $map = (new Compatibility($legacy::class))
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place->atMostOne(because: 'One address.')))
            ->resolve();

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings_of_the_vendor_storefront_before_the_redesign', 'the compiled name places_listings_of_the_vendor_storefront_before_the_redesign_at_most_one has 72 characters, and PostgreSQL keeps 63'));

        Statements::prepare($map, DB::connection());
    }
}
