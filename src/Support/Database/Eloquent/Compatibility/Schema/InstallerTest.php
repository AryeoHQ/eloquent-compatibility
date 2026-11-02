<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

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
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Photo;
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
        FileTables::drop();

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
            if ($concurrent !== null || $query->connectionName === 'compatibility_concurrent' || ! str_contains($query->sql, "privilege_type || ' ('")) {
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

    #[Test]
    public function it_repoints_an_inbound_foreign_key_on_a_discriminated_integer_identifier_as_a_composite_key_and_back(): void
    {
        $this->prepareFiles();
        $before = [$this->photoForeignKeys(), DB::select('select * from photos order by id')];

        Compatibility::install(Photo::class);
        $installed = [$this->photoForeignKeys(), DB::selectOne("select attgenerated::text as generated, pg_get_expr(adbin, adrelid) as expression from pg_attribute join pg_attrdef on adrelid = attrelid and adnum = attnum where attrelid = 'photo_comments'::regclass and attname = 'photo_legacy_type'")];

        try {
            DB::table('photo_comments')->insert(['photo_id' => 999, 'body' => 'Dangling']);
            $this->fail('The dangling reference was stored.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }

        Compatibility::uninstall(Photo::class);

        $this->assertSame([
            'photo_comments_photo_id_foreign' => 'FOREIGN KEY (photo_legacy_type, photo_id) REFERENCES files(legacy_type, legacy_id) ON DELETE CASCADE',
            'photo_shares_photo_uuid_foreign' => 'FOREIGN KEY (photo_legacy_type, photo_uuid) REFERENCES files(legacy_type, legacy_uuid) ON DELETE CASCADE',
        ], $installed[0]);
        $this->assertEquals((object) ['generated' => 's', 'expression' => "'photo'::text"], $installed[1]);
        $this->assertSame([
            'photo_comments_photo_id_foreign' => 'FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE',
            'photo_shares_photo_uuid_foreign' => 'FOREIGN KEY (photo_uuid) REFERENCES photos(uuid) ON DELETE CASCADE',
        ], $before[0]);
        $this->assertSame($before[0], $this->photoForeignKeys());
        $this->assertSame([], DB::select("select attrelid::regclass::text from pg_attribute where attrelid in ('photo_comments'::regclass, 'photo_shares'::regclass) and attname = 'photo_legacy_type' and not attisdropped"));
        $this->assertEquals($before[1], DB::select('select id, uuid, file_name, mime_type, caption, views, size_kb, created_at, updated_at from photos order by id'));
    }

    #[Test]
    public function it_refuses_an_inbound_uuid_reference_to_the_row_of_another_legacy_model_on_the_same_successor(): void
    {
        $this->prepareFiles();
        Compatibility::install(Photo::class);
        $floorPlan = DB::table('files')->where('legacy_type', 'floorplan')->where('legacy_id', 1)->value('legacy_uuid');

        try {
            DB::table('photo_shares')->insert(['photo_uuid' => $floorPlan]);
            $this->fail('The reference to a floor plan was stored.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }

        DB::table('photo_shares')->insert(['photo_uuid' => Photo::query()->findOrFail(2)->uuid]);

        $this->assertSame(3, DB::table('photo_shares')->count());
    }

    #[Test]
    public function it_refuses_a_composite_repoint_of_a_uuid_foreign_key_that_sets_its_column_to_null(): void
    {
        $this->prepareFiles();
        DB::statement('alter table photo_shares drop constraint photo_shares_photo_uuid_foreign, add constraint photo_shares_photo_uuid_foreign foreign key (photo_uuid) references photos (uuid) on delete set null');

        $this->expectExceptionObject(InvalidCompatibilityMap::because('photos', 'the foreign key photo_shares_photo_uuid_foreign on photo_shares takes ON DELETE SET NULL, and install() repoints it onto (legacy_type, legacy_uuid) through the generated column photo_legacy_type, which no referential action may change'));

        Compatibility::install(Photo::class);
    }

    #[Test]
    public function it_starts_the_shared_sequence_above_every_id_each_installed_legacy_table_holds(): void
    {
        $this->prepareFiles();
        DB::table('floorplans')->insert(['id' => 50, 'file_name' => 'barn-plan.pdf', 'mime_type' => 'application/pdf']);
        DB::table('files')->insert(['legacy_type' => 'floorplan', 'legacy_id' => 50, 'legacy_uuid' => DB::table('floorplans')->where('id', 50)->value('uuid'), 'filename' => 'barn-plan.pdf', 'mime_type' => 'application/pdf']);

        Compatibility::install(Photo::class);
        Compatibility::install(FloorPlan::class);
        $photo = Photo::query()->create(['file_name' => 'barn.jpg', 'mime_type' => 'image/jpeg']);

        $this->assertGreaterThan(50, $photo->id);
        $this->assertSame([3, 50], [DB::scalar('select max(id) from photos where id < ?', [$photo->id]), DB::scalar('select max(id) from floorplans')]);
    }

    #[Test]
    public function it_refuses_to_install_a_second_map_that_claims_an_installed_morph_alias(): void
    {
        $this->prepareFiles();
        Compatibility::install(Photo::class);
        $impostor = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'floorplans';

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $floorPlan): Compatibility
            {
                return FloorPlan::compatibility($floorPlan);
            }
        };

        try {
            Compatibility::install($impostor::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $refusal) {
            $this->assertSame(InvalidCompatibilityMap::because('floorplans', "the installed map retained in photos_retained already serves the files rows whose legacy_type is 'photo', and two installed maps cannot share a morph alias")->getMessage(), $refusal->getMessage());
        }

        $this->assertSame('r', $this->kind('floorplans'));
    }

    #[Test]
    public function it_refuses_to_recompile_an_installed_map_onto_the_morph_alias_another_installed_map_claims(): void
    {
        $this->prepareFiles();
        Compatibility::install(Photo::class);
        Compatibility::install(LegacyFile::class);
        $view = DB::scalar("select pg_get_viewdef('legacy_files'::regclass)");
        $impostor = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'legacy_files';

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $legacyFile): Compatibility
            {
                return LegacyFile::compatibility($legacyFile);
            }
        };

        try {
            Compatibility::recompile($impostor::class);
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame(InvalidCompatibilityMap::because('legacy_files', "the installed map retained in photos_retained already serves the files rows whose legacy_type is 'photo', and two installed maps cannot share a morph alias")->getMessage(), $refusal);
        $this->assertSame($view, DB::scalar("select pg_get_viewdef('legacy_files'::regclass)"));
        $this->assertSame([], DB::table('legacy_files')->whereIn('file_name', DB::table('photos')->select('file_name'))->pluck('file_name')->all());
    }

    #[Test]
    public function it_refuses_a_composite_repoint_of_a_foreign_key_that_sets_its_column_to_null(): void
    {
        $this->prepareFiles();
        DB::statement('alter table photo_comments alter column photo_id drop not null, drop constraint photo_comments_photo_id_foreign, add constraint photo_comments_photo_id_foreign foreign key (photo_id) references photos (id) on delete set null');

        $this->expectExceptionObject(InvalidCompatibilityMap::because('photos', 'the foreign key photo_comments_photo_id_foreign on photo_comments takes ON DELETE SET NULL, and install() repoints it onto (legacy_type, legacy_id) through the generated column photo_legacy_type, which no referential action may change'));

        Compatibility::install(Photo::class);
    }

    #[Test]
    public function it_refuses_to_install_a_discriminated_map_whose_legacy_identifier_still_draws_from_its_own_sequence(): void
    {
        $this->prepareFiles();
        DB::statement("alter table photos alter column id set default nextval('photos_id_seq')");

        $this->expectExceptionObject(InvalidCompatibilityMap::because('photos', "photos.id still defaults to nextval('photos_id_seq'::regclass), and Compatibility::prepare() moves it onto the sequence compatibility_legacy_id_seq before install()"));

        Compatibility::install(Photo::class);
    }

    #[Test]
    public function it_refuses_to_install_while_a_legacy_row_has_no_successor_row_of_its_own_legacy_model(): void
    {
        $this->prepareFiles();
        $uuid = DB::table('photos')->where('id', 2)->value('uuid');
        DB::delete("delete from files where legacy_type = 'photo' and legacy_id = 2");
        DB::update("update files set legacy_uuid = ? where legacy_type = 'floorplan' and legacy_id = 2", [$uuid]);

        try {
            Compatibility::install(Photo::class);
            $this->fail('install() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'photos' cannot install: 1 photos rows have no files row with the same id and uuid; the first are id 2. Backfill them before install().", $exception->getMessage());
        }

        $this->assertSame('r', $this->kind('photos'));
    }

    #[Test]
    public function it_refuses_to_uninstall_while_a_retained_row_belongs_to_a_successor_row_another_morph_alias_now_claims(): void
    {
        $this->prepareFiles();
        Compatibility::install(Photo::class);
        $pine = Photo::query()->create(['file_name' => 'pine.jpg', 'mime_type' => 'image/jpeg', 'caption' => 'Pine']);
        DB::table('files')->where('legacy_type', 'photo')->where('legacy_id', $pine->id)->update(['legacy_type' => 'legacy_file']);

        try {
            Compatibility::uninstall(Photo::class);
            $refusal = 'no refusal';
        } catch (QueryException $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString("Compatibility map 'photos' cannot uninstall: 1 photos_retained rows belong to files rows whose legacy_type is no longer 'photo'; the first are legacy_id {$pine->id}. Restore their legacy_type, or delete their photos_retained rows, before uninstall().", $refusal);
        $this->assertSame('v', $this->kind('photos'));

        DB::table('files')->where('legacy_type', 'legacy_file')->where('legacy_id', $pine->id)->update(['legacy_type' => 'photo']);
        Compatibility::uninstall(Photo::class);

        $this->assertSame([[1, 'Front'], [2, null], [3, 'Elm'], [$pine->id, 'Pine']], array_map(fn (object $row) => [(int) $row->id, $row->caption], DB::select('select id, caption from photos order by id')));
    }

    #[Test]
    public function it_installs_and_uninstalls_two_maps_whose_legacy_tables_reference_each_other_in_either_order(): void
    {
        $this->prepareFiles();
        DB::statement('alter table floorplans add column photo_id integer references photos (id)');
        DB::statement('alter table photos add column floorplan_id integer references floorplans (id)');
        DB::update('update floorplans set photo_id = 1 where id = 1');
        DB::update('update photos set floorplan_id = 1 where id = 2');
        $foreignKeys = fn () => array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where contype = 'f' and conrelid in (to_regclass('photos'), to_regclass('photos_retained'), to_regclass('floorplans'), to_regclass('floorplans_retained')) order by conname"), 'definition', 'conname');
        $before = [DB::select('select * from photos order by id'), DB::select('select * from floorplans order by id'), $foreignKeys()];

        foreach ([[Photo::class, FloorPlan::class], [FloorPlan::class, Photo::class]] as $order) {
            foreach ($order as $model) {
                Compatibility::install($model);
            }

            $served = [DB::select('select * from photos order by id'), DB::select('select * from floorplans order by id')];
            $codes = [];

            foreach ([
                fn () => FloorPlan::query()->findOrFail(2)->update(['photo_id' => 999]),
                fn () => Photo::query()->findOrFail(3)->update(['floorplan_id' => 999]),
            ] as $write) {
                try {
                    $write();
                    $codes[] = 'stored';
                } catch (QueryException $exception) {
                    $codes[] = $exception->getCode();
                }
            }

            foreach (array_reverse($order) as $model) {
                Compatibility::uninstall($model);
            }

            $this->assertEquals([$before[0], $before[1]], $served);
            $this->assertSame(['23503', '23503'], $codes);
            $this->assertEquals($before, [DB::select('select * from photos order by id'), DB::select('select * from floorplans order by id'), $foreignKeys()]);
        }
    }

    #[Test]
    public function it_refuses_to_recompile_an_installed_map_onto_a_morph_alias_install_did_not_record(): void
    {
        $this->prepareFiles();
        Compatibility::install(Photo::class);
        $view = DB::scalar("select pg_get_viewdef('photos'::regclass)");
        $renamed = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'photos';

            public function getMorphClass()
            {
                return 'image';
            }

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return Photo::compatibility($photo);
            }
        };

        try {
            Compatibility::recompile($renamed::class);
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame(InvalidCompatibilityMap::because('photos', "install() recorded that it serves the files rows whose legacy_type is 'photo', and the map now serves the files rows whose legacy_type is 'image'; recompile() cannot move the generated columns of repointed foreign keys or the record in photos_retained, so uninstall() and install() the map to change it")->getMessage(), $refusal);
        $this->assertSame($view, DB::scalar("select pg_get_viewdef('photos'::regclass)"));
        $this->assertSame([1, 2, 3], Photo::query()->orderBy('id')->pluck('id')->all());
    }

    private function prepareFiles(): void
    {
        FileTables::create();
        FileTables::seed();

        foreach (FileTables::MODELS as $model) {
            Compatibility::prepare($model);
        }

        FileTables::backfill();
    }

    /**
     * @return array<string, string>
     */
    private function photoForeignKeys(): array
    {
        return array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where contype = 'f' and conrelid in ('photo_comments'::regclass, 'photo_shares'::regclass) order by conname"), 'definition', 'conname');
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
