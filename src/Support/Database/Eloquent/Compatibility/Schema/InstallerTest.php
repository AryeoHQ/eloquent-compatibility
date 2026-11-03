<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
use Support\Database\Eloquent\Compatibility\Definition\Successors;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\Fixtures\Support\PlaceAssets\Video as PlaceVideo;
use Tests\TestCase;

#[CoversClass(Installer::class)]
#[CoversClass(Statements::class)]
#[CoversClass(Compatibility::class)]
final class InstallerTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        if ($this->concurrent !== null) {
            proc_terminate($this->concurrent[0]);
            proc_close($this->concurrent[0]);
            $this->concurrent = null;
        }

        ListingTables::drop();
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_locks_every_morph_target_while_it_installs_and_uninstalls_a_map_through_a_morph(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        PlaceAssetTables::prepare();
        PlaceAssetTables::backfill();
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks): void {
            if (str_starts_with($query->sql, 'LOCK TABLE')) {
                $locks[] = $query->sql;
            }
        });

        try {
            Compatibility::install(PlaceVideo::class);
            Compatibility::uninstall(PlaceVideo::class);
        } finally {
            PlaceAssetTables::drop();
        }

        $this->assertContains('LOCK TABLE place_videos, place_assets, asset_files, asset_links IN SHARE UPDATE EXCLUSIVE MODE', $locks);
        $this->assertContains('LOCK TABLE "place_assets", "asset_files", "asset_links" IN SHARE ROW EXCLUSIVE MODE', $locks);
    }

    #[Test]
    public function it_moves_a_record_whose_legacy_row_changes_target_after_its_last_pass_before_it_takes_the_lock(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        PlaceAssetTables::prepare();
        PlaceAssetTables::backfill();
        $file = DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 1)->value('asset_id');
        $writer = $this->writer();
        $landed = false;
        DB::listen(function (QueryExecuted $query) use ($writer, &$landed): void {
            if (! $landed && $query->connectionName === DB::getDefaultConnection() && str_starts_with($query->sql, 'COMMENT ON TABLE "place_videos" IS')) {
                $landed = true;
                $writer->update("update place_videos set youtube_embed_url = 'https://youtu.be/tour', original_file_name = null, size_kb = null, updated_at = localtimestamp(0) where id = 1");
            }
        });

        try {
            Compatibility::install(PlaceVideo::class);
            $videos = PlaceVideo::query()->orderBy('id')->get()->map(fn (PlaceVideo $video) => [$video->id, $video->original_file_name, $video->youtube_embed_url])->all();
            $asset = DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 1)->first(['asset_type', 'asset_id']);
            $state = [$asset?->asset_type, DB::table('asset_links')->where('id', $asset?->asset_id)->value('url'), DB::table('asset_files')->where('id', $file)->exists()];
        } finally {
            DB::purge('writer');
            PlaceAssetTables::drop();
        }

        $this->assertTrue($landed);
        $this->assertSame([[1, null, 'https://youtu.be/tour'], [2, null, 'https://youtu.be/oak'], [3, 'drone.mov', null]], $videos);
        $this->assertSame(['place_asset_link', 'https://youtu.be/tour', false], $state);
    }

    #[Test]
    public function it_deletes_the_place_asset_of_a_legacy_row_deleted_after_its_last_pass_and_leaves_its_target(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        PlaceAssetTables::prepare();
        PlaceAssetTables::backfill();
        $file = DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 3)->value('asset_id');
        $writer = $this->writer();
        $landed = false;
        DB::listen(function (QueryExecuted $query) use ($writer, &$landed): void {
            if (! $landed && $query->connectionName === DB::getDefaultConnection() && str_starts_with($query->sql, 'COMMENT ON TABLE "place_videos" IS')) {
                $landed = true;
                $writer->delete('delete from place_videos where id = 3');
            }
        });

        try {
            Compatibility::install(PlaceVideo::class);
            $videos = PlaceVideo::query()->orderBy('id')->pluck('id')->all();
            $state = [DB::table('place_assets')->where('legacy_type', 'place_video')->orderBy('legacy_id')->pluck('legacy_id')->all(), DB::table('asset_files')->where('id', $file)->value('filename')];
        } finally {
            DB::purge('writer');
            PlaceAssetTables::drop();
        }

        $this->assertTrue($landed);
        $this->assertSame([1, 2], $videos);
        $this->assertSame([[1, 2], 'drone.mov'], $state);
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
    public function it_grants_the_view_no_privilege_the_installer_s_default_privileges_add_and_the_legacy_table_did_not_grant(): void
    {
        $role = 'compatibility_denied_'.getmypid();
        DB::statement("create role {$role}");

        try {
            DB::statement("alter default privileges in schema public grant select, insert on tables to {$role}");
            DB::statement('alter default privileges in schema public grant select on tables to public');

            Compatibility::install(Listing::class);

            $this->assertSame(
                [false, false, false, true],
                [
                    DB::scalar("select has_table_privilege(?, 'listings', 'SELECT')", [$role]),
                    DB::scalar("select has_table_privilege(?, 'listings', 'INSERT')", [$role]),
                    DB::scalar("select exists (select from information_schema.role_table_grants where table_name = 'listings' and grantee = 'PUBLIC')"),
                    DB::scalar("select has_table_privilege('listings', 'SELECT WITH GRANT OPTION')"),
                ],
            );
        } finally {
            DB::statement('alter default privileges in schema public revoke select on tables from public');
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
            $this->assertSame(['listings_lower_title_index', 'listings_pkey', 'listings_status_index', 'listings_updated_at_index', 'listings_uuid_unique'], array_keys($before['indexes']));
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
    public function it_refuses_to_install_while_a_legacy_row_holds_an_external_value_its_read_does_not_return(): void
    {
        $this->prepareFiles();
        FakeMediaLibrary::bound()->put((string) DB::table('files')->where('legacy_type', 'photo')->where('legacy_id', 2)->value('zml_asset_id'), new FileMetadata('image/png'));
        DB::table('files')->where('legacy_type', 'photo')->where('legacy_id', 3)->update(['zml_asset_id' => null]);

        try {
            Compatibility::install(Photo::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $refusal) {
            $this->assertSame("Compatibility map 'photos' cannot install: 2 photos rows hold a mime_type that the external() read of attribute 'mime_type' does not return; the first are id 2, 3. Write each value through the attribute's external() write before install(), because install() drops photos.mime_type.", $refusal->getMessage());
        }

        $this->assertSame('r', $this->kind('photos'));
        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png'], array_column(DB::select('select mime_type from photos order by id'), 'mime_type'));
    }

    #[Test]
    public function it_refuses_to_install_while_a_legacy_row_holds_null_where_the_read_returns_a_value(): void
    {
        $this->prepareFiles();
        DB::statement('alter table photos alter column mime_type drop not null');
        DB::table('photos')->where('id', 3)->update(['mime_type' => null]);

        try {
            Compatibility::install(Photo::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $refusal) {
            $this->assertSame("Compatibility map 'photos' cannot install: 1 photos rows hold a mime_type that the external() read of attribute 'mime_type' does not return; the first are id 3. Write each value through the attribute's external() write before install(), because install() drops photos.mime_type.", $refusal->getMessage());
        }

        $this->assertSame('r', $this->kind('photos'));
    }

    #[Test]
    public function it_refuses_to_install_while_the_read_returns_a_boolean_for_a_legacy_value_that_is_not_that_boolean(): void
    {
        $this->prepareFiles();
        DB::table('photos')->where('id', 3)->update(['mime_type' => 'false']);
        $truthful = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'photos';

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return $photo
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('photos_retained')
                    ->timestamps()
                    ->servedFrom(File::class, fn (Successor $file) => $file
                        ->when(fn (Builder $files) => $files->where('mime_type', 'like', 'image/%'))
                        ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                        ->attribute('mime_type', fn (Attribute $attribute) => $attribute->external(
                            read: fn (Collection $files): array => $files->mapWithKeys(fn (Model $file) => [$file->getKey() => $file->getAttribute('legacy_id') === 1 ? 'image/jpeg' : true])->all(),
                            write: fn (File $file, mixed $value) => null))
                        ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000)));
            }
        };

        try {
            Compatibility::install($truthful::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $refusal) {
            $this->assertSame("Compatibility map 'photos' cannot install: 2 photos rows hold a mime_type that the external() read of attribute 'mime_type' does not return; the first are id 2, 3. Write each value through the attribute's external() write before install(), because install() drops photos.mime_type.", $refusal->getMessage());
        }

        $this->assertSame('r', $this->kind('photos'));
    }

    #[Test]
    public function it_refuses_to_install_an_external_attribute_over_a_generated_legacy_column(): void
    {
        $this->prepareFiles();
        DB::statement('alter table photos drop column mime_type');
        DB::statement("alter table photos add column mime_type varchar(255) generated always as ('image/' || 'jpeg') stored");

        try {
            Compatibility::install(Photo::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $refusal) {
            $this->assertSame("Compatibility map 'photos' is declared wrongly: photos.mime_type is a generated column, and attribute 'mime_type' is external(), so its value would come from outside the database and nothing would keep it generated from its columns; serve it with readAs() and readOnly().", $refusal->getMessage());
        }

        $this->assertSame(['r', 's'], [$this->kind('photos'), DB::scalar("select attgenerated::text from pg_attribute where attrelid = 'photos'::regclass and attname = 'mime_type'")]);
    }

    #[Test]
    public function it_refuses_to_install_an_external_attribute_over_a_legacy_column_that_owns_a_sequence(): void
    {
        $this->prepareFiles();
        DB::statement('drop sequence if exists photos_mime_type_seq cascade');
        DB::statement('create sequence photos_mime_type_seq owned by photos.mime_type');
        DB::statement("alter table photos alter column mime_type set default 'image/' || nextval('photos_mime_type_seq')");
        FakeMediaLibrary::bound()->calls = [];

        try {
            Compatibility::install(Photo::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $refusal) {
            $this->assertSame("Compatibility map 'photos' is declared wrongly: photos.mime_type owns the sequence public.photos_mime_type_seq, and attribute 'mime_type' is external(), so install() would drop the sequence with the column and uninstall() could not restore the default that reads it; detach it with ALTER SEQUENCE public.photos_mime_type_seq OWNED BY NONE first.", $refusal->getMessage());
        }

        $this->assertSame(['r', 'public.photos_mime_type_seq'], [$this->kind('photos'), DB::scalar("select pg_get_serial_sequence('photos', 'mime_type')")]);
        $this->assertSame([], FakeMediaLibrary::bound()->calls);

        DB::statement('alter sequence photos_mime_type_seq owned by none');
        Compatibility::install(Photo::class);
        Compatibility::uninstall(Photo::class);
        DB::table('photos')->insert(['file_name' => 'ash.jpg']);

        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png', 'image/1'], array_column(DB::select('select mime_type from photos order by id'), 'mime_type'));

        DB::statement('alter sequence photos_mime_type_seq owned by photos.mime_type');
    }

    #[Test]
    public function it_restores_the_default_and_not_null_flag_of_an_external_column_once_its_values_are_back(): void
    {
        $this->prepareFiles();
        DB::statement("alter table photos alter column mime_type set default 'application/octet-stream'");

        Compatibility::install(Photo::class);
        Compatibility::uninstall(Photo::class);
        DB::table('photos')->insert(['file_name' => 'ash.jpg']);

        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png', 'application/octet-stream'], array_column(DB::select('select mime_type from photos order by id'), 'mime_type'));
        $this->assertSame([true, "'application/octet-stream'::character varying"], [
            DB::scalar("select attnotnull from pg_attribute where attrelid = 'photos'::regclass and attname = 'mime_type'"),
            DB::scalar("select pg_get_expr(adbin, adrelid) from pg_attrdef join pg_attribute on attrelid = adrelid and attnum = adnum where adrelid = 'photos'::regclass and attname = 'mime_type'"),
        ]);
    }

    #[Test]
    public function it_restores_an_external_column_in_the_collation_the_legacy_column_declares(): void
    {
        $this->prepareFiles();
        DB::statement('alter table photos alter column mime_type type varchar(255) collate "C"');
        $collation = fn () => DB::scalar("select collname from pg_attribute join pg_collation on pg_collation.oid = attcollation where attrelid = 'photos'::regclass and attname = 'mime_type'");
        $before = $collation();

        Compatibility::install(Photo::class);
        Compatibility::uninstall(Photo::class);

        $this->assertSame(['C', 'C'], [$before, $collation()]);
        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png'], array_column(DB::select('select mime_type from photos order by id'), 'mime_type'));
    }

    #[Test]
    public function it_pages_install_verification_and_uninstall_restore_by_an_indexed_key_without_an_offset(): void
    {
        $this->prepareFiles();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        Compatibility::install(Photo::class);
        Compatibility::uninstall(Photo::class);

        $paged = array_values(array_filter($statements, fn (string $sql) => str_contains($sql, ' limit 500')));

        $this->assertCount(2, $paged);
        $this->assertMatchesRegularExpression('/^select "id", "mime_type" from "photos" (where "id" is not null )?order by "id" asc limit 500$/', $paged[0]);
        $this->assertMatchesRegularExpression('/^select "file_id", "id" from "photos" (where "file_id" is not null )?order by "file_id" asc limit 500$/', $paged[1]);
        $this->assertStringEndsWith(' WHERE "photos"."file_id" = CAST(restored.key AS uuid)', array_values(array_filter($statements, fn (string $sql) => str_starts_with($sql, 'UPDATE "photos" SET "mime_type"')))[0] ?? '');
    }

    #[Test]
    public function it_stores_no_external_value_once_installed_and_restores_each_through_its_read_on_uninstall(): void
    {
        $this->prepareFiles();
        DB::table('photos')->where('id', 2)->update(['mime_type' => 'image/webp']);
        DB::table('files')->where('legacy_type', 'photo')->where('legacy_id', 2)->update(['mime_type' => 'image/webp']);
        FakeMediaLibrary::bound()->put((string) DB::table('files')->where('legacy_type', 'photo')->where('legacy_id', 2)->value('zml_asset_id'), new FileMetadata('image/webp'));
        $before = DB::select('select id, uuid, file_name, mime_type, caption, views, size_kb, created_at, updated_at from photos order by id');

        Compatibility::install(Photo::class);
        $installed = DB::select("select table_name from information_schema.columns where table_name in ('photos', 'photos_retained') and column_name = 'mime_type'");
        Photo::query()->findOrFail(3)->update(['mime_type' => 'image/gif']);
        Compatibility::uninstall(Photo::class);

        $before[2]->mime_type = 'image/gif';
        $before[2]->updated_at = DB::scalar('select updated_at from photos where id = 3');

        $this->assertSame([], $installed);
        $this->assertSame('r', $this->kind('photos'));
        $this->assertEquals($before, DB::select('select id, uuid, file_name, mime_type, caption, views, size_kb, created_at, updated_at from photos order by id'));
        $this->assertSame([true, 'character varying(255)'], [
            DB::scalar("select attnotnull from pg_attribute where attrelid = 'photos'::regclass and attname = 'mime_type'"),
            DB::scalar("select format_type(atttypid, atttypmod) from pg_attribute where attrelid = 'photos'::regclass and attname = 'mime_type'"),
        ]);
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
    public function it_installs_when_the_legacy_and_successor_connections_reach_one_database_through_a_url_that_overrides_the_database_setting(): void
    {
        $pgsql = (array) config('database.connections.pgsql');
        $url = sprintf('pgsql://%s:%s@%s:%s/%s', rawurlencode((string) $pgsql['username']), rawurlencode((string) $pgsql['password']), $pgsql['host'], $pgsql['port'], rawurlencode((string) $pgsql['database']));
        $connection = [...$pgsql, 'url' => $url, 'database' => 'laravel'];
        config(['database.connections.compatibility_url_legacy' => $connection, 'database.connections.compatibility_url_successor' => $connection, 'database.default' => 'compatibility_url_successor']);
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $connection = 'compatibility_url_legacy';

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return Listing::compatibility($listing);
            }
        };

        try {
            Compatibility::install($model::class);
        } finally {
            DB::purge('compatibility_url_legacy');
            DB::purge('compatibility_url_successor');
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
        $locked = false;

        DB::listen(function (QueryExecuted $query) use (&$concurrent, &$locked, $change): void {
            if ($query->connectionName === 'compatibility_concurrent') {
                return;
            }

            $locked = $locked || str_ends_with($query->sql, ' IN SHARE UPDATE EXCLUSIVE MODE');

            if ($concurrent !== null || ! $locked || ! str_contains($query->sql, 'obj_description(')) {
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
            if ($concurrent !== null || $query->connectionName === 'compatibility_concurrent' || ! str_starts_with($query->sql, 'UPDATE "listings" AS "listings_retained" SET')) {
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
    public function it_serves_and_restores_each_mapped_column_in_the_collation_the_legacy_column_declares(): void
    {
        DB::statement("create collation compatibility_case_insensitive (provider = icu, locale = 'und-u-ks-level2', deterministic = false)");

        try {
            DB::statement('alter table listings alter column title type varchar(255) collate compatibility_case_insensitive, alter column street type varchar(255) collate "C"');
            DB::statement('alter table places alter column street type varchar(255) collate compatibility_case_insensitive');
            $collations = fn () => DB::table('pg_attribute')->join('pg_collation', 'pg_collation.oid', '=', 'pg_attribute.attcollation')
                ->whereRaw("attrelid = 'listings'::regclass")->whereIn('attname', ['title', 'street', 'city'])->orderBy('attname')->pluck('collname', 'attname')->all();
            $behaviour = fn () => [
                array_map(intval(...), DB::table('listings')->where('title', 'OAK HOUSE')->pluck('id')->all()),
                array_map(intval(...), DB::table('listings')->where('street', '1 OAK ST')->pluck('id')->all()),
            ];
            $before = [$collations(), $behaviour()];

            Compatibility::install(Listing::class);
            $installed = [$collations(), $behaviour()];
            Compatibility::recompile(Listing::class);
            $recompiled = [$collations(), $behaviour()];
            Compatibility::uninstall(Listing::class);

            $this->assertSame(
                array_fill(0, 4, [['city' => 'default', 'street' => 'C', 'title' => 'compatibility_case_insensitive'], [[1], []]]),
                [$before, $installed, $recompiled, [$collations(), $behaviour()]],
            );
        } finally {
            ListingTables::drop();
            DB::statement('drop collation if exists compatibility_case_insensitive');
        }
    }

    #[Test]
    public function it_serves_and_restores_a_raw_wall_clock_read_of_a_column_the_retained_table_keeps(): void
    {
        DB::statement('alter table listings add column listed_wall timestamp(0), add column listed_before timestamp(0)');
        DB::update("update listings set listed_wall = '2026-01-01 10:00:00', listed_before = '2026-01-01 10:00:00' where id = 1");
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
                        ->attribute('listed_wall', fn (Attribute $attribute) => $attribute->readAs('"listings_retained"."listed_before"')->readOnly(because: 'The legacy table keeps the first listing time.')));
            }
        };
        $read = fn () => DB::table('listings')->orderBy('id')->pluck('listed_wall', 'id')->map(fn (mixed $value) => $value === null ? null : (string) $value)->all();

        Compatibility::install($model::class);
        $served = [$this->kind('listings'), $read()];
        Compatibility::recompile($model::class);
        $recompiled = $read();
        Compatibility::uninstall($model::class);

        $this->assertSame(
            [['v', [1 => '2026-01-01 10:00:00', 2 => null, 3 => null]], [1 => '2026-01-01 10:00:00', 2 => null, 3 => null], ['r', [1 => '2026-01-01 10:00:00', 2 => null, 3 => null]]],
            [$served, $recompiled, [$this->kind('listings'), $read()]],
        );
    }

    #[Test]
    public function it_refuses_a_raw_hide_condition_whose_string_literal_holds_a_backslash_and_installs_nothing(): void
    {
        DB::statement('alter table jobs add column note text');
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return Listing::compatibility($listing)
                    ->hide(fn (Builder $jobs) => $jobs->whereRaw("note = 'it\\'s?' or name = ?", ['Elm Cottage']), because: 'A noted job is internal.');
            }
        };
        $refusals = [];

        foreach (['off', 'on'] as $setting) {
            try {
                DB::statement("set standard_conforming_strings = {$setting}");
                Compatibility::install($model::class);
            } catch (InvalidArgumentException $refusal) {
                $refusals[] = $refusal->getMessage();
            } finally {
                DB::statement('set standard_conforming_strings = on');
            }
        }

        $this->assertSame(array_fill(0, 2, "The SQL [note = 'it\\'s?' or name = ?] has a backslash in the string literal 'it\\', and PostgreSQL reads that backslash as an escape only while standard_conforming_strings is off; write the string as E'…' so that it reads the same under either setting."), $refusals);
        $this->assertSame('r', $this->kind('listings'));
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
    public function it_refuses_to_install_a_shared_successor_map_until_prepare_builds_the_unique_index_on_the_discriminator_and_the_integer_identifier_even_with_no_inbound_foreign_key(): void
    {
        $this->prepareFiles();
        DB::statement('drop table photo_comments, photo_shares');
        DB::statement('alter table files drop constraint files_legacy_type_legacy_id_unique');

        try {
            Compatibility::install(Photo::class);
            $this->fail('install() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'photos' cannot install: files.legacy_id is not yet NOT NULL with a unique index on (legacy_type, legacy_id), so prepare() has not finished. Run Compatibility::prepare() until it completes, then install again.", $exception->getMessage());
        }

        $this->assertSame('r', $this->kind('photos'));

        Compatibility::prepare(Photo::class);
        Compatibility::install(Photo::class);

        $this->assertSame('v', $this->kind('photos'));
        $this->assertSame([1, 2, 3], Photo::query()->orderBy('id')->pluck('id')->all());
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
    public function it_catches_up_the_inserts_updates_and_hard_deletes_that_land_after_its_last_pass_before_it_takes_the_lock(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::table('jobs')->insert(['name' => 'Native job', 'company_id' => ListingTables::COMPANY]);
        DB::update('update jobs set merged_into_job_id = (select id from jobs where legacy_id = 1) where legacy_id = 2');
        $writer = $this->writer();
        $landed = false;
        DB::listen(function (QueryExecuted $query) use ($writer, &$landed): void {
            if (! $landed && $query->connectionName === DB::getDefaultConnection() && str_starts_with($query->sql, 'COMMENT ON TABLE "listings" IS')) {
                $landed = true;
                $writer->insert("insert into listings (title, company_id) values ('Ash Barn', ?)", [ListingTables::COMPANY]);
                $writer->update("update listings set title = 'Oak Manor', updated_at = localtimestamp(0) where id = 1");
                $writer->delete('delete from listings where id in (2, 3)');
            }
        });

        Compatibility::install(Listing::class);

        $this->assertTrue($landed);
        $this->assertSame([[1, 'Oak Manor'], [4, 'Native job'], [5, 'Ash Barn']], array_map(fn (array $row) => [$row['id'], $row['title']], $this->listings()));
        $this->assertSame([1, 2, 4, 5], array_map(intval(...), array_column(DB::select('select legacy_id from jobs order by legacy_id'), 'legacy_id')));
    }

    #[Test]
    public function it_refuses_to_take_the_lock_while_its_last_catch_up_pass_still_changes_a_thousand_rows(): void
    {
        DB::statement("insert into listings (title, company_id, created_at, updated_at) select 'Bulk ' || n, ?, localtimestamp(0), localtimestamp(0) from generate_series(1, 1100) as n", [ListingTables::COMPANY]);
        $this->backfiller(Listing::class)->run();
        $writer = $this->writer();
        $churn = fn () => $writer->update("update listings set title = title || '.', updated_at = localtimestamp(0) where title like 'Bulk %'");
        $passes = 0;
        DB::listen(function (QueryExecuted $query) use ($churn, &$passes): void {
            if ($query->connectionName === DB::getDefaultConnection() && str_starts_with($query->sql, 'COMMENT ON TABLE "listings" IS')) {
                $passes++;
                $churn();
            }
        });
        $churn();

        try {
            Compatibility::install(Listing::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertStringStartsWith("Compatibility map 'listings' cannot install: its catch-up pass 5 still read 1100 legacy rows and changed 1100 successor rows, and install() takes the lock only once a pass reads and changes fewer than 1000 rows each. Run php artisan compatibility:backfill ".Listing::class.' --since=', $exception->getMessage());
        }

        $this->assertSame([5, 'r'], [$passes, $this->kind('listings')]);
    }

    #[Test]
    public function it_retries_the_lock_after_a_legacy_writer_holds_it_past_the_lock_timeout(): void
    {
        $holding = $this->holdConcurrently('update listings set title = title where id = 1', 6);
        $attempts = 0;
        DB::listen(function (QueryExecuted $query) use (&$attempts): void {
            if (str_starts_with($query->sql, 'SET LOCAL lock_timeout')) {
                $attempts++;
            }
        });
        $started = microtime(true);

        Compatibility::install(Listing::class);

        $this->assertSame(["locked\n", 2, 'v'], [$holding, $attempts, $this->kind('listings')]);
        $this->assertGreaterThanOrEqual(5.0, microtime(true) - $started);
        $this->assertSame('', $this->concurrentFailure());
    }

    #[Test]
    public function it_blocks_legacy_writes_only_while_it_holds_the_lock_and_serves_every_row_written_before_it(): void
    {
        $this->backfiller(Listing::class)->run();
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $writer = $this->start('$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            .' $writes = []; echo "writing\n"; fflush(STDOUT); $deadline = microtime(true) + 30;'
            .' for ($write = 0; microtime(true) < $deadline; $write++) {'
            .'   $started = microtime(true);'
            .'   try {'
            .'     if ($write % 2 === 0) { $id = (int) $pdo->query("insert into listings (title, company_id, updated_at) values (\'Row $write\', \''.ListingTables::COMPANY.'\', localtimestamp(0)) returning id")->fetchColumn(); }'
            .'     else { $id = (int) $pdo->query("update listings set title = \'Edit $write\', updated_at = localtimestamp(0) where id = (select max(id) from listings) returning id")->fetchColumn(); }'
            .'     $writes[] = [$started, microtime(true), $id, $write % 2 === 0 ? "Row $write" : "Edit $write"];'
            .'   } catch (PDOException $exception) { $writes[] = [$started, microtime(true), null, $exception->getCode()]; break; }'
            .'   usleep(5000);'
            .' }'
            .' echo json_encode($writes);');
        usleep(300000);
        $locked = null;
        $retaining = [];
        DB::listen(function (QueryExecuted $query) use (&$locked, &$retaining): void {
            if ($locked === null && $query->sql === 'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE') {
                $locked = microtime(true);
                usleep(500000);
            }

            if (str_starts_with($query->sql, 'ALTER TABLE "listings" ADD COLUMN IF NOT EXISTS "job_id"')) {
                $retaining = [microtime(true) - $query->time / 1000, microtime(true)];
            }

            if (str_starts_with($query->sql, 'COMMENT ON COLUMN "listings"."job_id"')) {
                $retaining[1] = microtime(true) + 0.05;
            }
        });

        Compatibility::install(Listing::class);
        $writes = json_decode($this->concurrentFailure(), true);

        $this->assertSame(["writing\n", 'v'], [$writer, $this->kind('listings')]);
        $this->assertIsArray($writes);
        $this->assertIsFloat($locked);
        $written = array_filter($writes, fn (array $write) => $write[2] !== null);
        $blocked = end($writes);
        $last = [];

        foreach ($written as [, , $id, $title]) {
            $last[$id] = $title;
        }

        ksort($last);
        $this->assertNotEmpty(array_filter($written, fn (array $write) => $write[1] < $locked));
        $this->assertCount(2, $retaining);
        $this->assertSame([], array_values(array_filter($written, fn (array $write) => $write[1] - $write[0] >= 0.3 && ($write[0] > $retaining[1] || $write[1] < $retaining[0]))));
        $this->assertIsArray($blocked);
        $this->assertSame([null, '55000'], [$blocked[2], $blocked[3]]);
        $this->assertGreaterThanOrEqual($locked - 0.05, $blocked[0]);
        $this->assertGreaterThanOrEqual(0.3, $blocked[1] - $blocked[0]);
        $this->assertSame($last, array_column(DB::select('select id, title from listings where id in ('.implode(', ', array_keys($last)).') order by id'), 'title', 'id'));
        $this->assertSame(0, DB::scalar('select count(*) from listings_retained left join jobs on jobs.id = listings_retained.job_id where jobs.id is null'));
    }

    #[Test]
    public function it_refuses_to_install_a_legacy_row_whose_primary_went_missing_outside_its_watermark(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::delete('delete from jobs where legacy_id = 2');

        try {
            Compatibility::install(Listing::class);
            $this->fail('install() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'listings' cannot install: 1 listings rows have no jobs row with the same id and uuid; the first are id 2. Backfill them before install().", $exception->getMessage());
        }

        $this->assertSame('r', $this->kind('listings'));
        $this->assertSame(0, DB::scalar("select count(*) from pg_attribute where attrelid = 'listings'::regclass and attname = 'job_id' and not attisdropped"));
    }

    #[Test]
    public function it_refuses_to_install_a_map_over_two_successors_while_a_legacy_row_is_in_neither_or_in_both(): void
    {
        $this->prepareMedia();
        DB::delete("delete from links where legacy_type = 'video' and legacy_id = 2");
        $refusals = [];

        foreach ([fn () => null, fn () => DB::insert("insert into links (legacy_type, legacy_id, legacy_uuid, url) select 'video', id, uuid, 'https://youtu.be/tour' from videos where id = 1")] as $break) {
            $break();

            try {
                Compatibility::install(Video::class);
            } catch (QueryException $exception) {
                $refusals[] = substr($exception->getMessage(), (int) strpos($exception->getMessage(), 'Compatibility map'), (int) strpos($exception->getMessage(), 'install().') + 10 - (int) strpos($exception->getMessage(), 'Compatibility map'));
            }

            DB::insert("insert into links (legacy_type, legacy_id, legacy_uuid, url) select 'video', id, uuid, youtube_embed_url from videos where id = 2 on conflict do nothing");
        }

        $this->assertSame([
            "Compatibility map 'videos' cannot install: 1 videos rows have no files or links row with the same id and uuid; the first are id 2. Backfill them before install().",
            "Compatibility map 'videos' cannot install: 1 videos rows have a row with the same id and uuid in more than one of files or links; the first are id 1. Backfill them before install().",
        ], $refusals);
        $this->assertSame('r', $this->kind('videos'));
    }

    #[Test]
    public function it_refuses_to_install_a_map_over_two_successors_that_a_foreign_key_references(): void
    {
        $this->prepareMedia();
        DB::statement('create table video_comments (id serial primary key, video_id integer references videos (id))');

        try {
            $this->expectExceptionObject(InvalidCompatibilityMap::because('videos', 'the foreign key video_comments_video_id_fkey on video_comments references videos.id, and install() serves videos from File and Link, so no one table can take the reference'));

            Compatibility::install(Video::class);
        } finally {
            DB::statement('drop table video_comments');
        }
    }

    #[Test]
    public function it_refuses_to_install_a_map_over_two_successors_whose_retained_rows_take_another_key(): void
    {
        $this->prepareMedia();
        $video = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'videos';

            public function getMorphClass()
            {
                return 'video';
            }

            public static function compatibility(Compatibility $video): Compatibility
            {
                return $video
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('videos_retained', key: 'video_id')
                    ->timestamps()
                    ->servedFrom(
                        choose: fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null ? Link::class : File::class,
                        successors: fn (Successors $successors) => $successors
                            ->as(File::class, fn (Successor $file) => $file
                                ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                                ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type'))
                                ->attribute('size_kb', fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000))
                                ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->fixed(null)))
                            ->as(Link::class, fn (Successor $link) => $link
                                ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                                ->attribute('mime_type', fn (Attribute $attribute) => $attribute->fixed(null))
                                ->attribute('size_kb', fn (Attribute $attribute) => $attribute->fixed(null))
                                ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url'))));
            }
        };

        $this->expectExceptionObject(InvalidCompatibilityMap::because('videos', 'retainedIn() keys videos_retained by video_id, and install() keeps the retained rows of a map over File and Link under the legacy key id'));

        Compatibility::install($video::class);
    }

    #[Test]
    public function it_refuses_to_install_a_map_over_two_successors_while_a_legacy_row_sits_in_the_successor_its_selector_does_not_choose(): void
    {
        $this->prepareMedia();
        DB::insert("insert into files (legacy_type, legacy_id, legacy_uuid, filename, mime_type) select 'video', id, uuid, 'oak.mp4', 'video/mp4' from videos where id = 2");
        DB::delete("delete from links where legacy_type = 'video' and legacy_id = 2");

        try {
            Compatibility::install(Video::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertSame("Compatibility map 'videos' cannot install: 1 videos rows sit in a successor their servedFrom() selector does not choose; the first are id 2. Run php artisan compatibility:backfill ".Video::class.', then install().', $exception->getMessage());
        }

        $this->assertSame('r', $this->kind('videos'));
    }

    #[Test]
    public function it_retries_the_lock_after_postgresql_ends_its_attempt_to_break_a_deadlock_with_a_legacy_writer(): void
    {
        DB::beginTransaction();

        try {
            DB::statement("set local deadlock_timeout = '10s'");
            Listing::query()->findOrFail(1);
            $install = $this->startInAnotherProcess('Illuminate\Support\Facades\DB::listen(function ($query) { if ($query->sql === \'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE\') { usleep(1500000); } });'
                .' Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class)');
            $this->awaitHolder('ShareRowExclusiveLock');
            DB::table('listings')->where('id', 1)->update(['title' => 'Oak Manor']);
        } finally {
            DB::commit();
        }

        $this->assertSame([0, ''], $this->finish($install));
        $this->assertSame('v', $this->kind('listings'));
    }

    #[Test]
    public function it_catches_up_on_a_reinstall_the_legacy_writes_made_after_an_uninstall(): void
    {
        $this->backfiller(Listing::class)->run();
        Compatibility::install(Listing::class);
        Compatibility::uninstall(Listing::class);
        Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);

        Compatibility::install(Listing::class);

        $this->assertSame(['v', 'Oak Manor'], [$this->kind('listings'), DB::table('listings')->where('id', 1)->value('title')]);
    }

    #[Test]
    public function it_deletes_the_job_of_a_listing_hard_deleted_between_the_backfill_and_install(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::delete('delete from listings where id = 3');

        Compatibility::install(Listing::class);

        $this->assertSame([1, 2], array_column($this->listings(), 'id'));
        $this->assertSame([1, 2], array_map(intval(...), array_column(DB::select('select legacy_id from jobs order by legacy_id'), 'legacy_id')));
        $this->assertNull(DB::scalar("select to_regclass('listings_backfilled')::text"));
    }

    #[Test]
    public function it_keeps_at_install_every_job_no_backfill_wrote_while_it_deletes_the_job_of_a_hard_deleted_listing(): void
    {
        ListingTables::create();
        ListingTables::seed();
        DB::table('jobs')->insert(['name' => 'Prior job', 'company_id' => ListingTables::COMPANY]);
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        $this->backfiller(Listing::class)->run();
        DB::table('jobs')->insert(['name' => 'Native job', 'company_id' => ListingTables::COMPANY]);
        DB::delete('delete from listings where id = 3');

        Compatibility::install(Listing::class);

        $this->assertSame([[1, 'Oak House'], [2, 'Pine Flat'], [4, 'Prior job'], [5, 'Native job']], array_map(fn (array $row) => [$row['id'], $row['title']], $this->listings()));
    }

    #[Test]
    public function it_deletes_on_a_reinstall_the_job_of_a_listing_hard_deleted_after_an_uninstall_and_keeps_a_job_created_since(): void
    {
        $this->backfiller(Listing::class)->run();
        Compatibility::install(Listing::class);
        Compatibility::uninstall(Listing::class);
        DB::delete('delete from listings where id = 3');
        DB::table('jobs')->insert(['name' => 'Native job', 'company_id' => ListingTables::COMPANY]);

        Compatibility::install(Listing::class);

        $this->assertSame([[1, 'Oak House'], [2, 'Pine Flat'], [4, 'Native job']], array_map(fn (array $row) => [$row['id'], $row['title']], $this->listings()));
        $this->assertSame([1, 2, 4], array_map(intval(...), array_column(DB::select('select legacy_id from jobs order by legacy_id'), 'legacy_id')));
    }

    #[Test]
    public function it_catches_up_on_a_reinstall_a_legacy_edit_that_a_transaction_open_since_before_the_uninstall_commits_after_it(): void
    {
        $this->backfiller(Listing::class)->run();
        Compatibility::install(Listing::class);
        $writer = $this->writer();
        $writer->beginTransaction();

        try {
            $writer->select('select 1');
            usleep(1200000);
            Compatibility::uninstall(Listing::class);
            $writer->update("update listings set title = 'Oak Manor', updated_at = localtimestamp where id = 1");
            $writer->commit();
        } finally {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
        }

        Compatibility::install(Listing::class);

        $this->assertSame(['v', 'Oak Manor'], [$this->kind('listings'), DB::table('listings')->where('id', 1)->value('title')]);
    }

    #[Test]
    public function it_deletes_at_install_the_job_of_a_listing_hard_deleted_between_the_two_deletes_of_an_off_lock_catch_up_pass(): void
    {
        $this->backfiller(Listing::class)->run();
        $writer = $this->writer();
        $deleted = false;
        DB::listen(function (QueryExecuted $query) use ($writer, &$deleted): void {
            if (! $deleted && $query->connectionName === DB::getDefaultConnection() && str_contains($query->sql, 'DELETE FROM "jobs"')) {
                $deleted = true;
                $writer->delete('delete from listings where id = 3');
            }
        });

        Compatibility::install(Listing::class);

        $this->assertTrue($deleted);
        $this->assertSame([1, 2], array_column($this->listings(), 'id'));
        $this->assertSame([1, 2], array_map(intval(...), array_column(DB::select('select legacy_id from jobs order by legacy_id'), 'legacy_id')));
    }

    #[Test]
    public function it_catches_up_off_the_lock_the_legacy_writes_that_land_while_it_fills_the_retained_key(): void
    {
        $this->backfiller(Listing::class)->run();
        $writer = $this->writer();
        $churned = false;
        $locked = false;
        $walked = 0;
        DB::listen(function (QueryExecuted $query) use ($writer, &$churned, &$locked, &$walked): void {
            if ($query->connectionName !== DB::getDefaultConnection()) {
                return;
            }

            if (! $churned && str_starts_with($query->sql, 'CREATE UNIQUE INDEX CONCURRENTLY')) {
                $churned = true;
                $writer->insert("insert into listings (title, company_id, created_at, updated_at) select 'Bulk ' || n, ?, localtimestamp(0) - interval '1 hour', localtimestamp(0) - interval '1 hour' from generate_series(1, 1100) as n", [ListingTables::COMPANY]);
            }

            $locked = $locked || $query->sql === 'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE';
            $walked += $locked && str_starts_with($query->sql, 'select * from "listings"') ? 1 : 0;
        });

        Compatibility::install(Listing::class);

        $this->assertSame([true, true, 1], [$churned, $locked, $walked]);
        $this->assertSame(['v', 1100], [$this->kind('listings'), DB::table('listings')->where('title', 'like', 'Bulk %')->count()]);
    }

    #[Test]
    public function it_refuses_to_install_from_a_watermark_while_the_legacy_table_has_no_updated_at_its_model_writes(): void
    {
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id')));
            }
        };
        $this->backfiller($listing::class)->run();

        $this->expectExceptionObject(InvalidCompatibilityMap::cannot('listings', 'install', 'listings has no updated_at column its legacy model writes, so install() cannot limit its catch-up to the rows written since the backfill, and would walk every legacy row while it holds the lock'));

        Compatibility::install($listing::class);
    }

    #[Test]
    public function it_refuses_to_install_from_a_watermark_that_records_no_legacy_key_while_the_legacy_table_holds_rows(): void
    {
        $this->backfiller(Listing::class)->run(since: '2026-01-01T00:00:00Z');

        try {
            Compatibility::install(Listing::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertSame("Compatibility map 'listings' cannot install: the watermark of the last backfill records no legacy key it walked, so install() would walk every legacy row while it holds the lock. Run php artisan compatibility:backfill ".Listing::class.' without --since, then install().', $exception->getMessage());
        }

        $this->assertSame('r', $this->kind('listings'));
    }

    #[Test]
    public function it_refuses_to_take_the_lock_while_its_last_catch_up_pass_still_reads_a_thousand_rows_it_changes_nothing_in(): void
    {
        DB::statement("insert into listings (title, company_id, created_at, updated_at) select 'Bulk ' || n, ?, localtimestamp(0), localtimestamp(0) from generate_series(1, 1100) as n", [ListingTables::COMPANY]);
        $this->backfiller(Listing::class)->run();
        $holding = $this->holdConcurrently('select 1', 30);
        $this->writer()->update("update listings set title = title || '.', updated_at = localtimestamp(0) where title like 'Bulk %'");

        try {
            Compatibility::install(Listing::class);
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertStringStartsWith("Compatibility map 'listings' cannot install: its catch-up pass 5 still read 1100 legacy rows and changed 0 successor rows, and install() takes the lock only once a pass reads and changes fewer than 1000 rows each.", $exception->getMessage());
        }

        $this->assertSame(["locked\n", 'r'], [$holding, $this->kind('listings')]);
    }

    #[Test]
    public function it_keeps_legacy_reads_available_while_it_fills_the_retained_key_under_the_lock(): void
    {
        $this->backfiller(Listing::class)->run();
        $locked = false;
        $read = null;
        DB::listen(function (QueryExecuted $query) use (&$locked, &$read): void {
            if ($query->sql === 'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE') {
                $locked = true;
            }

            if ($locked && $read === null && str_starts_with($query->sql, 'UPDATE "listings" SET "job_id"')) {
                $read = $this->readConcurrently("set lock_timeout = '1s'; select count(*) from listings");
            }
        });

        Compatibility::install(Listing::class);

        $this->assertSame(['3', 'v'], [$read, $this->kind('listings')]);
        $this->assertSame([
            'listings_retained_job_id_foreign' => 'FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE',
            'listings_retained_pkey' => 'PRIMARY KEY (job_id)',
        ], array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'listings_retained'::regclass and contype <> 'n' order by conname"), 'definition', 'conname'));
        $this->assertSame(0, DB::scalar('select count(*) from listings_retained left join jobs on jobs.id = listings_retained.job_id where jobs.id is null'));
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

            $this->assertEquals([array_map(fn (object $row) => (object) array_diff_key((array) $row, ['mime_type' => true]), $before[0]), $before[1]], $served);
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

    #[Test]
    public function it_compiles_an_install_from_a_watermark_only_once_it_holds_the_lock_that_keeps_the_legacy_shape(): void
    {
        $this->backfiller(Listing::class)->run();
        config(['database.connections.compatibility_concurrent' => config('database.connections.pgsql')]);
        $locked = false;
        $concurrent = null;

        DB::listen(function (QueryExecuted $query) use (&$locked, &$concurrent): void {
            if ($query->connectionName === 'compatibility_concurrent') {
                return;
            }

            $locked = $locked || str_ends_with($query->sql, ' IN SHARE UPDATE EXCLUSIVE MODE');

            if (! $locked || $concurrent !== null || ! str_contains($query->sql, 'format_type(table_column.atttypid')) {
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

        $this->assertSame(['55P03', 'v', false], [$concurrent, $this->kind('listings'), DB::scalar("select count(*) > 0 from pg_attribute where attrelid = 'listings_retained'::regclass and attname = 'notes'")]);
    }

    #[Test]
    public function it_installs_and_uninstalls_a_map_over_two_successors_that_maps_nothing_but_its_primary_identifier(): void
    {
        FileTables::create();
        FileTables::seedMedia();
        DB::statement('alter table videos drop column uuid');
        $video = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'videos';

            public function getMorphClass()
            {
                return 'video';
            }

            public static function compatibility(Compatibility $video): Compatibility
            {
                return $video
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->retainedIn('videos_retained')
                    ->servedFrom(
                        choose: fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null ? Link::class : File::class,
                        successors: fn (Successors $successors) => $successors
                            ->as(File::class, fn (Successor $file) => $file)
                            ->as(Link::class, fn (Successor $link) => $link));
            }
        };
        $before = $this->sorted(array_map(fn (object $row) => (array) $row, DB::select('select * from videos order by id')));
        Compatibility::prepare($video::class);
        DB::statement("insert into files (legacy_type, legacy_id, filename, mime_type) select 'video', id, 'video.bin', 'application/octet-stream' from videos where youtube_embed_url is null");
        DB::statement("insert into links (legacy_type, legacy_id, url) select 'video', id, youtube_embed_url from videos where youtube_embed_url is not null");

        Compatibility::install($video::class);
        $installed = [$this->kind('videos'), $this->sorted(array_map(fn (object $row) => (array) $row, DB::select('select * from videos order by id')))];
        Compatibility::uninstall($video::class);

        $this->assertSame([['v', $before], 'r'], [$installed, $this->kind('videos')]);
        $this->assertSame($before, $this->sorted(array_map(fn (object $row) => (array) $row, DB::select('select * from videos order by id'))));
    }

    #[Test]
    public function it_uninstalls_a_map_over_two_successors_that_maps_only_its_primary_identifier_and_an_external_attribute_and_restores_the_external_values(): void
    {
        FileTables::create();
        FileTables::seedMedia();
        DB::statement('alter table videos drop column uuid');
        $video = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'videos';

            public function getMorphClass()
            {
                return 'video';
            }

            public static function compatibility(Compatibility $video): Compatibility
            {
                return $video
                    ->discriminatedBy('legacy_type')
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->retainedIn('videos_retained')
                    ->servedFrom(
                        choose: fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null ? Link::class : File::class,
                        successors: fn (Successors $successors) => $successors
                            ->as(File::class, fn (Successor $file) => $file
                                ->attribute('mime_type', fn (Attribute $attribute) => $attribute
                                    ->external(
                                        read: fn (Collection $files): array => File::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType)->all(),
                                        write: fn (File $file, null|string $mimeType) => $file->metadata()->mimeType = $mimeType)))
                            ->as(Link::class, fn (Successor $link) => $link
                                ->attribute('mime_type', fn (Attribute $attribute) => $attribute->fixed(null))));
            }
        };
        $before = $this->sorted(array_map(fn (object $row) => (array) $row, DB::select('select * from videos order by id')));
        Compatibility::prepare($video::class);
        DB::statement("insert into files (legacy_type, legacy_id, filename, mime_type) select 'video', id, 'video.bin', mime_type from videos where youtube_embed_url is null");
        DB::statement("insert into links (legacy_type, legacy_id, url) select 'video', id, youtube_embed_url from videos where youtube_embed_url is not null");
        FileTables::catalog('video');
        Compatibility::install($video::class);

        Compatibility::uninstall($video::class);

        $this->assertSame('r', $this->kind('videos'));
        $this->assertSame($before, $this->sorted(array_map(fn (object $row) => (array) $row, DB::select('select * from videos order by id'))));
    }

    #[Test]
    public function it_makes_the_retained_key_it_filled_off_the_lock_the_primary_key_only_after_commit_once_a_validated_check_proves_it_holds_no_null(): void
    {
        $this->backfiller(Listing::class)->run();
        $proven = null;
        $connection = DB::connection();

        $connection->beforeExecuting(function (string $query) use (&$proven, $connection): void {
            if ($proven === null && str_contains($query, 'PRIMARY KEY USING INDEX')) {
                $proven = [$connection->transactionLevel(), $connection->scalar("select count(*) from pg_constraint where conrelid = to_regclass('listings_retained') and contype = 'c' and convalidated and pg_get_constraintdef(oid) = 'CHECK ((job_id IS NOT NULL))'")];
            }
        });

        Compatibility::install(Listing::class);

        $this->assertSame([0, 1], $proven);
        $this->assertSame(
            ['listings_retained_job_id_foreign' => 'FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE', 'listings_retained_pkey' => 'PRIMARY KEY (job_id)'],
            array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'listings_retained'::regclass order by conname"), 'definition', 'conname'),
        );
        $this->assertSame([true, 3], [DB::scalar("select attnotnull from pg_attribute where attrelid = 'listings_retained'::regclass and attname = 'job_id'"), DB::scalar('select count(*) from listings')]);
    }

    #[Test]
    public function it_refuses_to_install_from_a_watermark_over_a_preparation_that_has_not_finished_and_drops_the_retained_key_it_filled(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::statement('drop table listing_photos, listing_shares');
        DB::statement('alter table jobs alter column legacy_id drop not null');

        try {
            Compatibility::install(Listing::class);
            $refusal = 'no refusal';
        } catch (QueryException $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString("Compatibility map 'listings' cannot install: jobs.legacy_id is not yet NOT NULL with a unique index of its own, so prepare() has not finished. Run Compatibility::prepare() until it completes, then install again.", $refusal);
        $this->assertSame(['r', false], [$this->kind('listings'), DB::scalar("select count(*) > 0 from pg_attribute where attrelid = 'listings'::regclass and attname = 'job_id' and not attisdropped")]);
    }

    private function prepareMedia(): void
    {
        FileTables::create();
        FileTables::seedMedia();
        Compatibility::prepare(Video::class);
        FileTables::backfillMedia();
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function backfiller(string $model): Backfiller
    {
        return new Backfiller(Compatibility::of($model)->resolve(), DB::connection());
    }

    private function writer(): ConnectionInterface
    {
        config(['database.connections.writer' => config('database.connections.'.DB::getDefaultConnection())]);

        return DB::connection('writer');
    }

    private function holdConcurrently(string $sql, int $seconds): string
    {
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();

        return $this->start('$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).');'
            .' $pdo->beginTransaction(); $pdo->exec('.var_export($sql, true).'); echo "locked\n"; fflush(STDOUT); sleep('.$seconds.'); $pdo->commit();');
    }

    private function start(string $concurrent): string
    {
        $process = proc_open([PHP_BINARY, '-r', $concurrent], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            $this->fail('The concurrent writer did not start.');
        }

        $this->concurrent = [$process, $pipes];

        return (string) fgets($pipes[1]);
    }

    /**
     * @return array{resource, array<int, resource>}
     */
    private function startInAnotherProcess(string $statement): array
    {
        $child = 'require '.var_export(dirname(__DIR__, 6).'/vendor/autoload.php', true).';'
            .' Tests\\TestCase::optimizeClassmap();'
            .' Orchestra\Testbench\Foundation\Application::create();'
            .' '.$statement.';'
            .' Illuminate\Support\Facades\DB::disconnect();';
        $process = proc_open([PHP_BINARY, '-r', $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['APP_ENV' => 'testing', 'DB_DATABASE' => DB::connection()->getDatabaseName()] + getenv());

        if ($process === false) {
            $this->fail('The other process did not start.');
        }

        return [$process, $pipes];
    }

    /**
     * @param  array{resource, array<int, resource>}  $process
     * @return array{int, string}
     */
    private function finish(array $process): array
    {
        [$handle, $pipes] = $process;
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);

        return [proc_close($handle), $output];
    }

    private function awaitHolder(string $mode): void
    {
        $deadline = microtime(true) + 10;

        while (DB::scalar("select count(*) from pg_locks where relation = to_regclass('listings') and mode = ? and granted and pid <> pg_backend_pid()", [$mode]) === 0) {
            if (microtime(true) > $deadline) {
                $this->fail("No other session took {$mode} on listings.");
            }

            usleep(20000);
        }
    }

    private function readConcurrently(string $sql): string
    {
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $process = proc_open([PHP_BINARY, '-r', '$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            .' try { foreach (explode(";", '.var_export($sql, true).') as $statement) { if (trim($statement) !== "") { $read = $pdo->query($statement)->fetchColumn(); } } echo $read; } catch (PDOException $exception) { echo $exception->getMessage(); }'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            $this->fail('The concurrent reader did not start.');
        }

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        proc_close($process);

        return $output;
    }

    private function concurrentFailure(): string
    {
        if ($this->concurrent === null) {
            $this->fail('No concurrent writer is running.');
        }

        [$process, $pipes] = $this->concurrent;
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        proc_close($process);
        $this->concurrent = null;

        return $output;
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
