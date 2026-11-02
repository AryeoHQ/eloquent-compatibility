<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Pdo\Pgsql;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(Installer::class)]
#[CoversClass(Statements::class)]
#[CoversClass(Compatibility::class)]
final class InstallerTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_serves_every_legacy_row_with_the_same_columns_and_values_once_installed(): void
    {
        $before = $this->listings();

        Compatibility::install(Listing::class);

        $this->assertSame('v', $this->kind('listings'));
        $this->assertSame($before, $this->listings());
        $this->assertSame(['mls_number', 'featured', 'job_id'], array_keys((array) DB::selectOne('select * from listings_retained limit 1')));
    }

    #[Test]
    public function it_serves_a_job_created_through_the_successor_with_a_legacy_id_from_the_sequence_and_a_fresh_legacy_uuid(): void
    {
        Compatibility::install(Listing::class);

        DB::table('jobs')->insert(['name' => 'Birch Barn', 'company_id' => ListingTables::COMPANY, 'price_cents' => 500000, 'lifecycle_status' => 'live']);
        $job = DB::selectOne("select id, legacy_id, legacy_uuid from jobs where name = 'Birch Barn'");
        $listing = DB::selectOne('select id, uuid, title, status, price, featured from listings where id = ?', [$job->legacy_id]);

        $this->assertSame(4, $job->legacy_id);
        $this->assertEquals((object) ['id' => 4, 'uuid' => $job->legacy_uuid, 'title' => 'Birch Barn', 'status' => 'Active', 'price' => '5000.00', 'featured' => false], $listing);
        $this->assertNotSame($job->id, $listing->uuid);
    }

    #[Test]
    public function it_never_serves_a_successor_key_as_a_legacy_identifier(): void
    {
        Compatibility::install(Listing::class);
        DB::table('jobs')->insert(['name' => 'Birch Barn', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame(0, DB::scalar('select count(*) from listings where cast(uuid as text) in (select cast(id as text) from jobs)'));
        $this->assertSame(4, DB::scalar('select count(*) from listings where uuid is not null and uuid not in (select id from jobs)'));
    }

    #[Test]
    public function it_refuses_every_write_through_the_view(): void
    {
        Compatibility::install(Listing::class);
        $refusals = [];

        foreach ([
            fn () => DB::table('listings')->insert(['title' => 'Cedar Cabin', 'company_id' => ListingTables::COMPANY]),
            fn () => DB::table('listings')->where('id', 1)->update(['title' => 'Renamed']),
            fn () => DB::table('listings')->where('id', 1)->delete(),
        ] as $write) {
            try {
                $write();
            } catch (QueryException $exception) {
                $refusals[] = $exception->getPrevious()?->getMessage();
            }
        }

        $this->assertCount(3, $refusals);
        $this->assertStringContainsString('cannot insert into view "listings"', (string) $refusals[0]);
        $this->assertStringContainsString('cannot update view "listings"', (string) $refusals[1]);
        $this->assertStringContainsString('cannot delete from view "listings"', (string) $refusals[2]);
        $this->assertSame('Oak House', DB::scalar('select title from listings where id = 1'));
    }

    #[Test]
    public function it_repoints_each_inbound_foreign_key_to_its_identifier_column_and_back(): void
    {
        $before = $this->foreignKeys();

        Compatibility::install(Listing::class);
        $installed = $this->foreignKeys();
        Compatibility::uninstall(Listing::class);

        $this->assertSame([
            'listing_photos_listing_id_foreign' => 'FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE',
            'listing_shares_listing_uuid_foreign' => 'FOREIGN KEY (listing_uuid) REFERENCES listings(uuid) ON UPDATE CASCADE ON DELETE SET NULL',
        ], $before);
        $this->assertSame([
            'listing_photos_listing_id_foreign' => 'FOREIGN KEY (listing_id) REFERENCES jobs(legacy_id) ON DELETE CASCADE',
            'listing_shares_listing_uuid_foreign' => 'FOREIGN KEY (listing_uuid) REFERENCES jobs(legacy_uuid) ON UPDATE CASCADE ON DELETE SET NULL',
            'listings_retained_job_id_foreign' => 'FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE',
        ], $installed);
        $this->assertSame($before, $this->foreignKeys());
    }

    #[Test]
    public function it_round_trips_every_legacy_column_value_through_install_and_uninstall(): void
    {
        $before = $this->listings();

        Compatibility::install(Listing::class);
        Compatibility::uninstall(Listing::class);

        $this->assertSame('r', $this->kind('listings'));
        $this->assertNull($this->kind('listings_retained'));
        $this->assertSame($this->sorted($before), $this->sorted($this->listings()));
        $this->assertSame(['listing_id' => 1, 'path' => 'oak/front.jpg'], (array) DB::selectOne('select listing_id, path from listing_photos order by id limit 1'));
    }

    #[Test]
    public function it_restores_the_legacy_table_with_its_keys_defaults_and_sequence(): void
    {
        Compatibility::install(Listing::class);
        Compatibility::uninstall(Listing::class);

        DB::table('listings')->insert(['title' => 'Cedar Cabin', 'company_id' => ListingTables::COMPANY]);

        $this->assertEquals((object) ['id' => 4, 'status' => 'Draft', 'featured' => false, 'has_uuid' => true], DB::selectOne("select id, status, featured, uuid is not null as has_uuid from listings where title = 'Cedar Cabin'"));
        $this->assertSame(
            ['listings_pkey' => 'PRIMARY KEY (id)', 'listings_uuid_unique' => 'UNIQUE (uuid)'],
            array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'listings'::regclass and contype in ('p', 'u') order by conname"), 'definition', 'conname'),
        );
        $this->assertSame('public.listings_id_seq', DB::scalar("select pg_get_serial_sequence('listings', 'id')"));
    }

    #[Test]
    public function it_restores_a_listing_for_every_job_created_while_installed_whether_the_view_hides_it_or_not(): void
    {
        Compatibility::install(Listing::class);
        DB::table('jobs')->insert(['name' => 'Birch Barn', 'company_id' => ListingTables::COMPANY, 'lifecycle_status' => 'closed']);
        DB::table('jobs')->insert(['name' => 'Merged Mill', 'company_id' => ListingTables::COMPANY, 'merged_into_job_id' => DB::scalar('select id from jobs where legacy_id = 1')]);
        DB::table('listing_photos')->insert(['listing_id' => DB::scalar("select legacy_id from jobs where name = 'Merged Mill'"), 'path' => 'mill/front.jpg']);
        $served = DB::select('select * from listings order by id');

        Compatibility::uninstall(Listing::class);

        $this->assertSame(['Oak House', 'Pine Flat', 'Elm Cottage', 'Birch Barn'], array_column($served, 'title'));
        $this->assertSame(
            [[4, 'Birch Barn', 'Sold', false], [5, 'Merged Mill', 'Draft', false]],
            array_map(fn (object $row) => array_values((array) $row), DB::select('select id, title, status, featured from listings where id > 3 order by id')),
        );
        $this->assertTrue(DB::scalar("select convalidated from pg_constraint where conname = 'listing_photos_listing_id_foreign'"));
    }

    #[Test]
    public function it_grants_the_view_every_privilege_the_legacy_table_granted(): void
    {
        $role = 'compatibility_reader_'.getmypid();
        DB::statement("create role {$role}");

        try {
            DB::statement("grant select on listings to {$role}");
            DB::statement("grant update on listings to {$role} with grant option");

            Compatibility::install(Listing::class);

            $this->assertSame(
                [['SELECT', 'NO'], ['UPDATE', 'YES']],
                array_map(fn (object $row) => [$row->privilege_type, $row->is_grantable], DB::select("select privilege_type, is_grantable from information_schema.role_table_grants where table_name = 'listings' and grantee = ? order by privilege_type", [$role])),
            );
            DB::statement("set role {$role}");
            $this->assertSame(3, DB::scalar('select count(*) from listings'));
        } finally {
            DB::statement('reset role');
            DB::statement("drop owned by {$role}");
            DB::statement("drop role {$role}");
        }
    }

    #[Test]
    public function it_keeps_the_legacy_table_owner_reading_the_view_when_another_role_installs_it(): void
    {
        $role = 'compatibility_owner_'.getmypid();
        DB::statement("create role {$role}");

        try {
            DB::statement("alter table listings owner to {$role}");
            DB::statement("alter table jobs owner to {$role}");

            Compatibility::install(Listing::class);
            DB::statement("set role {$role}");

            $this->assertSame([3, true], [DB::scalar('select count(*) from listings'), DB::scalar("select has_table_privilege('listings', 'SELECT WITH GRANT OPTION')")]);
        } finally {
            DB::statement('reset role');
            DB::statement("reassign owned by {$role} to current_user");
            DB::statement("drop owned by {$role}");
            DB::statement("drop role {$role}");
        }
    }

    #[Test]
    public function it_grants_each_view_column_every_privilege_the_legacy_column_granted(): void
    {
        $role = 'compatibility_column_reader_'.getmypid();
        DB::statement("create role {$role}");

        try {
            DB::statement("grant select (id, title, mls_number) on listings to {$role}");

            Compatibility::install(Listing::class);

            $this->assertSame(
                ['id', 'mls_number', 'title'],
                array_column(DB::select("select column_name from information_schema.column_privileges where table_name = 'listings' and grantee = ? and privilege_type = 'SELECT' order by column_name", [$role]), 'column_name'),
            );
            DB::statement("set role {$role}");
            $this->assertSame(3, DB::scalar('select count(*) from (select id, title, mls_number from listings) as granted'));
        } finally {
            DB::statement('reset role');
            DB::statement("drop owned by {$role}");
            DB::statement("drop role {$role}");
        }
    }

    #[Test]
    public function it_serves_and_restores_a_legacy_timestamp_whatever_the_session_time_zone(): void
    {
        $createdAt = fn (string $zone) => DB::transaction(function () use ($zone): mixed {
            DB::statement("set local time zone '{$zone}'");

            return DB::scalar('select cast(created_at as text) from listings where id = 1');
        });

        Compatibility::install(Listing::class);
        $served = [$createdAt('UTC'), $createdAt('America/Denver')];
        DB::statement("set time zone 'America/Denver'");

        try {
            Compatibility::uninstall(Listing::class);
        } finally {
            DB::statement("set time zone 'UTC'");
        }

        $this->assertSame(['2026-01-01 10:00:00', '2026-01-01 10:00:00', '2026-01-01 10:00:00'], [...$served, $createdAt('UTC')]);
    }

    #[Test]
    public function it_installs_and_uninstalls_over_a_foreign_key_from_the_legacy_table_to_itself(): void
    {
        DB::statement('alter table listings add column parent_listing_id integer references listings (id)');
        DB::update('update listings set parent_listing_id = 1 where id = 3');
        $foreignKey = fn () => (array) DB::selectOne("select conrelid::regclass::text as on_table, pg_get_constraintdef(oid) as definition, convalidated from pg_constraint where conname = 'listings_parent_listing_id_fkey'");

        Compatibility::install(Listing::class);
        $installed = $foreignKey();
        Compatibility::uninstall(Listing::class);

        $this->assertSame(['on_table' => 'listings_retained', 'definition' => 'FOREIGN KEY (parent_listing_id) REFERENCES jobs(legacy_id)', 'convalidated' => true], $installed);
        $this->assertSame(['on_table' => 'listings', 'definition' => 'FOREIGN KEY (parent_listing_id) REFERENCES listings(id)', 'convalidated' => true], $foreignKey());
    }

    #[Test]
    public function it_keeps_an_inbound_foreign_key_that_was_not_valid_before_install_not_valid_through_recompile_and_uninstall(): void
    {
        DB::statement('create table listing_notes (listing_id integer)');
        $foreignKey = fn () => (array) DB::selectOne("select pg_get_constraintdef(oid) as definition, convalidated from pg_constraint where conname = 'listing_notes_listing_id_foreign'");

        try {
            DB::insert('insert into listing_notes (listing_id) values (1), (99)');
            DB::statement('alter table listing_notes add constraint listing_notes_listing_id_foreign foreign key (listing_id) references listings (id) not valid');
            Compatibility::install(Listing::class);
            $installed = $foreignKey();
            Compatibility::recompile(Listing::class);
            $recompiled = $foreignKey();
            Compatibility::uninstall(Listing::class);
            $uninstalled = $foreignKey();
        } finally {
            DB::statement('drop table listing_notes');
        }

        $this->assertSame([
            ['definition' => 'FOREIGN KEY (listing_id) REFERENCES jobs(legacy_id) NOT VALID', 'convalidated' => false],
            ['definition' => 'FOREIGN KEY (listing_id) REFERENCES jobs(legacy_id) NOT VALID', 'convalidated' => false],
            ['definition' => 'FOREIGN KEY (listing_id) REFERENCES listings(id) NOT VALID', 'convalidated' => false],
        ], [$installed, $recompiled, $uninstalled]);
    }

    #[Test]
    public function it_validates_on_recompile_each_foreign_key_install_left_not_valid(): void
    {
        Compatibility::install(Listing::class);
        DB::update("update pg_constraint set convalidated = false where conname in ('listing_photos_listing_id_foreign', 'listings_retained_job_id_foreign')");

        Compatibility::recompile(Listing::class);

        $this->assertSame(
            ['listing_photos_listing_id_foreign' => true, 'listings_retained_job_id_foreign' => true],
            array_column(DB::select("select conname, convalidated from pg_constraint where conname in ('listing_photos_listing_id_foreign', 'listings_retained_job_id_foreign') order by conname"), 'convalidated', 'conname'),
        );
    }

    #[Test]
    public function it_restores_the_not_null_flags_indexes_checks_outbound_foreign_keys_and_comment_install_dropped(): void
    {
        DB::statement('create table companies (id uuid primary key)');

        try {
            DB::insert('insert into companies (id) values (?)', [ListingTables::COMPANY]);
            DB::statement('alter table listings add constraint listings_company_id_foreign foreign key (company_id) references companies (id)');
            DB::statement('alter table listings add constraint listings_price_check check (price >= 0)');
            DB::statement('create index listings_status_index on listings (status)');
            DB::statement('create index listings_lower_title_index on listings (lower(title)) where deleted_at is null');
            DB::statement("comment on table listings is 'Every listing an agent published.'");
            $before = $this->shape();

            Compatibility::install(Listing::class);
            Compatibility::uninstall(Listing::class);

            $this->assertSame($before, $this->shape());
            $this->assertSame(['company_id', 'featured', 'id', 'status', 'title', 'uuid'], $before['not null']);
            $this->assertSame(['listings_company_id_foreign', 'listings_pkey', 'listings_price_check', 'listings_uuid_unique'], array_keys($before['constraints']));
            $this->assertSame(['listings_lower_title_index', 'listings_pkey', 'listings_status_index', 'listings_uuid_unique'], array_keys($before['indexes']));
        } finally {
            DB::statement('drop table if exists companies cascade');
        }
    }

    #[Test]
    public function it_restores_a_generated_column_install_dropped(): void
    {
        DB::statement('alter table listings add column title_upper text generated always as (upper(title)) stored');
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.'))
                        ->attribute('title_upper', fn (Attribute $attribute) => $attribute->readAs('upper("jobs"."name")')->readOnly(because: 'The legacy table generates it.')));
            }
        };

        Compatibility::install($model::class);
        $served = DB::scalar('select title_upper from listings where id = 1');
        Compatibility::uninstall($model::class);

        $this->assertSame('OAK HOUSE', $served);
        $this->assertEquals(
            (object) ['title_upper' => 'OAK HOUSE', 'generated' => 's', 'expression' => 'upper((title)::text)'],
            DB::selectOne("select title_upper, (select attgenerated::text from pg_attribute where attrelid = 'listings'::regclass and attname = 'title_upper') as generated, (select pg_get_expr(adbin, adrelid) from pg_attrdef join pg_attribute on attrelid = adrelid and attnum = adnum where adrelid = 'listings'::regclass and attname = 'title_upper') as expression from listings where id = 1"),
        );
    }

    #[Test]
    public function it_serves_a_null_a_retained_row_stores_and_the_default_only_for_a_job_without_a_retained_row(): void
    {
        DB::statement("alter table listings add column source text default 'import'");
        DB::update('update listings set source = null where id = 3');

        Compatibility::install(Listing::class);
        DB::table('jobs')->insert(['name' => 'Birch Barn', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame([1 => 'import', 2 => 'import', 3 => null, 4 => 'import'], array_column(DB::select('select id, source from listings order by id'), 'source', 'id'));
    }

    #[Test]
    public function it_reports_how_many_legacy_rows_a_hide_rule_hides_at_install(): void
    {
        DB::update('update jobs set merged_into_job_id = (select id from jobs where legacy_id = 3) where legacy_id = 1');
        $notices = [];
        $pdo = DB::connection()->getPdo();
        $this->assertInstanceOf(Pgsql::class, $pdo);
        $pdo->setNoticeCallback(function (string $notice) use (&$notices): void {
            $notices[] = $notice;
        });

        try {
            Compatibility::install(Listing::class);
        } finally {
            $pdo->setNoticeCallback(null);
        }

        $this->assertStringContainsString("Compatibility map 'listings' install hides 1 listings rows, because a hide() rule matches their jobs row; the first are id 1.", implode("\n", $notices));
        $this->assertSame([2, 3], array_map(intval(...), array_column(DB::select('select id from listings order by id'), 'id')));
    }

    #[Test]
    public function it_hides_a_merged_job_from_the_view(): void
    {
        Compatibility::install(Listing::class);

        DB::update('update jobs set merged_into_job_id = (select id from jobs where legacy_id = 3) where legacy_id = 1');

        $this->assertSame([2, 3], array_map(intval(...), array_column(DB::select('select id from listings order by id'), 'id')));
    }

    #[Test]
    public function it_deletes_the_retained_row_with_its_job(): void
    {
        Compatibility::install(Listing::class);

        DB::delete('delete from jobs where legacy_id = 3');

        $this->assertSame([1, 2], array_map(intval(...), array_column(DB::select('select id from listings order by id'), 'id')));
        $this->assertSame(2, DB::scalar('select count(*) from listings_retained'));
        $this->assertSame(['oak/front.jpg', 'oak/back.jpg'], array_column(DB::select('select path from listing_photos order by id'), 'path'));
    }

    #[Test]
    public function it_refuses_to_install_while_a_legacy_row_has_no_primary(): void
    {
        DB::delete('delete from jobs where legacy_id in (1, 3)');

        try {
            Compatibility::install(Listing::class);
            $this->fail('install() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'listings' cannot install: 2 listings rows have no jobs row with the same id and uuid; the first are id 1, 3. Backfill them before install().", $exception->getMessage());
        }

        $this->assertSame('r', $this->kind('listings'));
        $this->assertSame([], DB::select("select conname from pg_constraint where confrelid = 'jobs'::regclass and conname like 'listing%'"));
    }

    #[Test]
    public function it_refuses_an_operation_that_validates_after_its_transaction_inside_another_transaction(): void
    {
        $this->expectExceptionObject(new LogicException('Compatibility::install() runs 3 statements after its own transaction commits, and the connection is already inside a transaction. Run it from a migration that sets public $withinTransaction = false.'));

        DB::transaction(fn () => Compatibility::install(Listing::class));
    }

    #[Test]
    public function it_refuses_to_install_a_map_whose_external_attribute_names_a_legacy_column(): void
    {
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.'))
                        ->attribute('mls_number', fn (Attribute $attribute) => $attribute->external(read: fn (array $jobs) => $jobs, write: fn (Job $job, mixed $number) => $number)));
            }
        };

        try {
            Compatibility::install($model::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $refusal) {
            $this->assertSame(InvalidCompatibilityMap::because('listings', "attribute 'mls_number' is external(), and this release neither reads nor restores an external attribute, so install() would drop listings.mls_number and its values")->getMessage(), $refusal->getMessage());
        }

        $this->assertSame('r', $this->kind('listings'));
        $this->assertSame(['MLS-1', null, 'MLS-3'], array_column(DB::select('select mls_number from listings order by id'), 'mls_number'));
    }

    #[Test]
    public function it_leaves_a_foreign_key_that_install_did_not_move_where_uninstall_finds_it(): void
    {
        DB::statement('create table job_notes (id serial primary key, job_legacy_id integer references jobs (legacy_id))');

        try {
            Compatibility::install(Listing::class);
            Compatibility::uninstall(Listing::class);

            $this->assertSame('FOREIGN KEY (job_legacy_id) REFERENCES jobs(legacy_id)', DB::scalar("select pg_get_constraintdef(oid) from pg_constraint where conname = 'job_notes_job_legacy_id_fkey'"));
        } finally {
            DB::statement('drop table if exists job_notes');
        }
    }

    #[Test]
    public function it_reads_the_catalog_through_the_write_connection_when_the_connection_has_a_replica(): void
    {
        DB::statement('create schema compatibility_replica');
        config(['database.connections.compatibility_replicated' => [...(array) config('database.connections.pgsql'), 'read' => ['search_path' => 'compatibility_replica'], 'write' => []]]);
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $connection = 'compatibility_replicated';

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return Listing::compatibility($listing);
            }
        };

        try {
            Compatibility::install($model::class);
        } finally {
            DB::purge('compatibility_replicated');
            DB::statement('drop schema compatibility_replica cascade');
        }

        $this->assertSame('v', $this->kind('listings'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unfinishedPreparations(): array
    {
        return [
            'a nullable identifier' => ['alter table jobs alter column legacy_id drop not null', 'jobs.legacy_id is not yet NOT NULL with a unique index of its own'],
            'an identifier without its unique index' => ['alter table jobs drop constraint jobs_legacy_uuid_unique', 'jobs.legacy_uuid is not yet NOT NULL with a unique index of its own'],
            'no at-most-one index' => ['drop index places_listings_at_most_one', 'places_listings_at_most_one is missing, not valid, or not the unique index prepare() builds on places'],
            'an at-most-one index not yet valid' => ["update pg_index set indisvalid = false where indexrelid = 'places_listings_at_most_one'::regclass", 'places_listings_at_most_one is missing, not valid, or not the unique index prepare() builds on places'],
        ];
    }

    #[DataProvider('unfinishedPreparations')]
    #[Test]
    public function it_refuses_to_install_over_a_preparation_that_has_not_finished_even_with_no_inbound_foreign_key(string $unfinished, string $what): void
    {
        DB::statement('drop table listing_photos, listing_shares');
        DB::statement($unfinished);

        try {
            Compatibility::install(Listing::class);
            $this->fail('install() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'listings' cannot install: {$what}, so prepare() has not finished. Run Compatibility::prepare() until it completes, then install again.", $exception->getMessage());
        }

        $this->assertSame('r', $this->kind('listings'));
    }

    #[Test]
    public function it_reads_the_catalog_under_locks_that_keep_the_legacy_shape_until_install_commits(): void
    {
        config(['database.connections.compatibility_concurrent' => config('database.connections.pgsql')]);
        $concurrent = null;

        DB::listen(function (QueryExecuted $query) use (&$concurrent): void {
            if ($concurrent !== null || $query->connectionName === 'compatibility_concurrent' || ! str_contains($query->sql, 'format_type(table_column.atttypid')) {
                return;
            }

            $concurrent = 'added';

            try {
                DB::connection('compatibility_concurrent')->transaction(function (): void {
                    DB::connection('compatibility_concurrent')->statement("set local lock_timeout = '200ms'");
                    DB::connection('compatibility_concurrent')->statement('alter table listings add column notes text');
                });
            } catch (QueryException $exception) {
                $concurrent = (string) $exception->getCode();
            }
        });

        try {
            Compatibility::install(Listing::class);
        } finally {
            DB::purge('compatibility_concurrent');
        }

        $this->assertSame(['55P03', false, false], [$concurrent, ...array_map(fn (string $relation) => DB::scalar('select count(*) > 0 from pg_attribute where attrelid = cast(? as regclass) and attname = ?', [$relation, 'notes']), ['listings', 'listings_retained'])]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function concurrentChanges(): array
    {
        return [
            'a column' => ['alter table listings add column notes text', "select count(*) from pg_attribute where attname = 'notes' and attrelid in (to_regclass('listings'), to_regclass('listings_retained'))"],
            'an index' => ['create index listings_city_index on listings (city)', "select count(*) from pg_class where relname = 'listings_city_index'"],
            'a foreign key to the legacy table' => ['create table listing_notes (listing_id bigint references listings (id))', "select count(*) from pg_class where relname = 'listing_notes'"],
        ];
    }

    #[DataProvider('concurrentChanges')]
    #[Test]
    public function it_holds_back_a_concurrent_change_to_a_table_it_read_until_install_commits(string $change, string $exists): void
    {
        config(['database.connections.compatibility_concurrent' => config('database.connections.pgsql')]);
        $concurrent = null;

        DB::listen(function (QueryExecuted $query) use (&$concurrent, $change): void {
            if ($concurrent !== null || $query->connectionName === 'compatibility_concurrent' || ! str_contains($query->sql, 'obj_description(')) {
                return;
            }

            $concurrent = 'committed';

            try {
                DB::connection('compatibility_concurrent')->transaction(function () use ($change): void {
                    DB::connection('compatibility_concurrent')->statement("set local lock_timeout = '200ms'");
                    DB::connection('compatibility_concurrent')->statement($change);
                });
            } catch (QueryException $exception) {
                $concurrent = (string) $exception->getCode();
            }
        });

        try {
            Compatibility::install(Listing::class);
            $outcome = [$concurrent, (int) DB::scalar($exists), DB::scalar("select relkind::text from pg_class where oid = to_regclass('listings')")];
        } finally {
            DB::purge('compatibility_concurrent');
            DB::statement('drop table if exists listing_notes');
        }

        $this->assertSame(['55P03', 0, 'v'], $outcome);
    }

    #[Test]
    public function it_holds_back_a_write_to_a_related_successor_until_uninstall_commits_the_values_it_copied(): void
    {
        Compatibility::install(Listing::class);
        config(['database.connections.compatibility_concurrent' => config('database.connections.pgsql')]);
        $concurrent = null;

        DB::listen(function (QueryExecuted $query) use (&$concurrent): void {
            if ($concurrent !== null || $query->connectionName === 'compatibility_concurrent' || ! str_starts_with($query->sql, 'UPDATE "listings" SET')) {
                return;
            }

            $concurrent = 'committed';

            try {
                DB::connection('compatibility_concurrent')->transaction(function (): void {
                    DB::connection('compatibility_concurrent')->statement("set local lock_timeout = '200ms'");
                    DB::connection('compatibility_concurrent')->update("update places set street = '9 Moved St' where job_id = (select id from jobs where legacy_id = 1)");
                });
            } catch (QueryException $exception) {
                $concurrent = (string) $exception->getCode();
            }
        });

        try {
            Compatibility::uninstall(Listing::class);
        } finally {
            DB::purge('compatibility_concurrent');
        }

        $this->assertSame(
            ['55P03', '1 Oak St', '1 Oak St'],
            [$concurrent, DB::scalar('select street from listings where id = 1'), DB::scalar('select street from places where job_id = (select id from jobs where legacy_id = 1)')],
        );
    }

    /**
     * @return array<string, array{null|string, string, bool, bool}>
     */
    public static function concurrentPrivilegeChanges(): array
    {
        return [
            'a grant' => [null, 'grant select on listings to %s', true, true],
            'a revoke' => ['grant select on listings to %s', 'revoke select on listings from %s', false, false],
            'a column grant' => [null, 'grant select (title) on listings to %s', false, true],
        ];
    }

    #[DataProvider('concurrentPrivilegeChanges')]
    #[Test]
    public function it_serves_through_the_view_a_privilege_change_that_commits_after_install_compiles(null|string $before, string $change, bool $table, bool $column): void
    {
        config(['database.connections.compatibility_concurrent' => config('database.connections.pgsql')]);
        $role = 'compatibility_grantee_'.getmypid();
        $concurrent = null;
        DB::statement("create role {$role}");

        DB::listen(function (QueryExecuted $query) use (&$concurrent, $change, $role): void {
            if ($concurrent !== null || $query->connectionName === 'compatibility_concurrent' || ! str_contains($query->sql, 'IN SHARE ROW EXCLUSIVE MODE')) {
                return;
            }

            $concurrent = 'committed';

            try {
                DB::connection('compatibility_concurrent')->transaction(function () use ($change, $role): void {
                    DB::connection('compatibility_concurrent')->statement("set local lock_timeout = '200ms'");
                    DB::connection('compatibility_concurrent')->statement(sprintf($change, $role));
                });
            } catch (QueryException $exception) {
                $concurrent = (string) $exception->getCode();
            }
        });

        try {
            if ($before !== null) {
                DB::statement(sprintf($before, $role));
            }

            Compatibility::install(Listing::class);
            $outcome = [$concurrent, DB::scalar("select has_table_privilege(?, 'listings', 'SELECT')", [$role]), DB::scalar("select has_column_privilege(?, 'listings', 'title', 'SELECT')", [$role])];
        } finally {
            DB::purge('compatibility_concurrent');
            DB::statement("drop owned by {$role}");
            DB::statement("drop role {$role}");
        }

        $this->assertSame(['committed', $table, $column], $outcome);
    }

    #[Test]
    public function it_hides_the_rows_eloquent_matches_with_a_float_binding_as_pdo_sends_it(): void
    {
        DB::statement('alter table jobs add column score double precision');
        DB::update('update jobs set score = 0.01 where legacy_id = 1');
        DB::update('update jobs set score = 0.009999999999999998 where legacy_id = 3');
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.')))
                    ->hide(fn (Builder $jobs) => $jobs->where('score', 0.009999999999999998), because: 'A scored job is internal.');
            }
        };
        $hidden = array_map(intval(...), Job::query()->withTrashed()->where('score', 0.009999999999999998)->pluck('legacy_id')->all());

        Compatibility::install($model::class);

        $this->assertSame([[1], [2, 3]], [$hidden, array_map(intval(...), DB::table('listings')->orderBy('id')->pluck('id')->all())]);
    }

    /**
     * @return array<string, array{string, string, Closure(Builder<Job>): mixed, bool}>
     */
    public static function hideBindings(): array
    {
        return [
            'a backslash in a string, with standard_conforming_strings off' => ['alter table jobs add column note text', "update jobs set note = 'C:\\new' where legacy_id = 3", static fn (Builder $jobs) => $jobs->where('note', 'C:\\new'), true],
            'a backslash before a quote, with standard_conforming_strings off' => ['alter table jobs add column note text', "update jobs set note = 'it\\''s' where legacy_id = 3", static fn (Builder $jobs) => $jobs->where('note', "it\\'s"), true],
            'an integer against a boolean column' => ['alter table jobs add column archived boolean not null default false', 'update jobs set archived = true where legacy_id = 3', static fn (Builder $jobs) => $jobs->where('archived', 1), false],
            'a boolean against an integer column' => ['alter table jobs add column rank integer not null default 0', 'update jobs set rank = 1 where legacy_id = 3', static fn (Builder $jobs) => $jobs->where('rank', true), false],
        ];
    }

    /**
     * @param  Closure(Builder<Job>): mixed  $hide
     */
    #[DataProvider('hideBindings')]
    #[Test]
    public function it_hides_the_rows_eloquent_matches_with_each_binding_as_postgresql_types_it(string $column, string $row, Closure $hide, bool $escapesOff): void
    {
        DB::statement($column);
        DB::update($row);
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public static null|Closure $hide = null;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.')))
                    ->hide(self::$hide ?? fn (Builder $jobs) => $jobs, because: 'A marked job is internal.');
            }
        };
        $model::$hide = $hide;
        $hidden = array_map(intval(...), Job::query()->withTrashed()->where($hide)->pluck('legacy_id')->all());

        try {
            DB::statement('set standard_conforming_strings = '.($escapesOff ? 'off' : 'on'));
            Compatibility::install($model::class);
        } finally {
            DB::statement('set standard_conforming_strings = on');
        }

        $this->assertSame([[3], [1, 2]], [$hidden, array_map(intval(...), DB::table('listings')->orderBy('id')->pluck('id')->all())]);
    }

    #[Test]
    public function it_recompiles_an_installed_view_in_place(): void
    {
        Compatibility::install(Listing::class);
        DB::statement('grant select on listings to public');
        $before = $this->listings();

        Compatibility::recompile(Listing::class);

        $this->assertSame($before, $this->listings());
        $this->assertSame(1, DB::scalar("select count(*) from information_schema.role_table_grants where table_name = 'listings' and grantee = 'PUBLIC'"));
    }

    /**
     * @return array{'not null': list<string>, constraints: array<string, array{string, bool}>, indexes: array<string, string>, comment: mixed}
     */
    private function shape(): array
    {
        return [
            'not null' => array_column(DB::select("select attname from pg_attribute where attrelid = 'listings'::regclass and attnum > 0 and not attisdropped and attnotnull order by attname"), 'attname'),
            'constraints' => array_map(fn (object $row) => [$row->definition, $row->convalidated], array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition, convalidated from pg_constraint where conrelid = 'listings'::regclass and contype <> 'n' order by conname"), null, 'conname')),
            'indexes' => array_column(DB::select("select indexname, indexdef from pg_indexes where tablename = 'listings' order by indexname"), 'indexdef', 'indexname'),
            'comment' => DB::scalar("select obj_description('listings'::regclass, 'pg_class')"),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listings(): array
    {
        return array_map(fn (object $row) => (array) $row, DB::select('select * from listings order by id'));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows): array
    {
        return array_map(function (array $row): array {
            ksort($row);

            return $row;
        }, $rows);
    }

    /**
     * @return array<string, string>
     */
    private function foreignKeys(): array
    {
        return array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where contype = 'f' and confrelid in (to_regclass('listings'), to_regclass('jobs')) and conrelid not in ('jobs'::regclass, 'places'::regclass, 'job_sites'::regclass) order by conname"), 'definition', 'conname');
    }

    private function kind(string $relation): null|string
    {
        $kind = DB::scalar('select relkind::text from pg_class where oid = to_regclass(?)', [$relation]);

        return is_string($kind) ? $kind : null;
    }
}
