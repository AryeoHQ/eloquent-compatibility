<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pdo\Pgsql;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Contracts\ManagesDependents;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Photo;
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

    /** @var class-string<ManagesDependents> */
    public static string $dependents = ManagesDependents::class;

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
    public function it_locks_every_morph_target_while_it_installs_and_rolls_back_a_map_through_a_morph(): void
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
            Compatibility::install(
                model: PlaceVideo::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            Compatibility::rollback(PlaceVideo::class);
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
        $file = DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('place_asset_id'),
        )->value('asset_id');
        $writer = $this->writer();
        $landed = false;
        DB::listen(function (QueryExecuted $query) use ($writer, &$landed): void {
            if (! $landed && $query->connectionName === DB::getDefaultConnection() && str_starts_with(
                haystack: $query->sql,
                needle: 'update "compatibility_backfills" set watermark_at',
            )) {
                $landed = true;
                $writer->update("update place_videos set youtube_embed_url = 'https://youtu.be/tour', original_file_name = null, size_kb = null, updated_at = localtimestamp(0) where id = 1");
            }
        });

        try {
            Compatibility::install(
                model: PlaceVideo::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $videos = PlaceVideo::query()->orderBy('id')->get()->map(fn (PlaceVideo $video) => [$video->id, $video->original_file_name, $video->youtube_embed_url])->all();
            $asset = DB::table('place_assets')->where(
                column: 'id',
                operator: '=',
                value: DB::table('place_videos')->where(
                    column: 'id',
                    operator: '=',
                    value: 1,
                )->value('place_asset_id'),
            )->first(['asset_type', 'asset_id']);
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
        $file = DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 3,
            )->value('place_asset_id'),
        )->value('asset_id');
        $writer = $this->writer();
        $landed = false;
        DB::listen(function (QueryExecuted $query) use ($writer, &$landed): void {
            if (! $landed && $query->connectionName === DB::getDefaultConnection() && str_starts_with(
                haystack: $query->sql,
                needle: 'update "compatibility_backfills" set watermark_at',
            )) {
                $landed = true;
                $writer->delete('delete from place_videos where id = 3');
            }
        });

        try {
            Compatibility::install(
                model: PlaceVideo::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $videos = PlaceVideo::query()->orderBy('id')->pluck('id')->all();
            $state = [DB::table('place_assets')->where(
                column: 'legacy_type',
                operator: '=',
                value: 'place_video',
            )->count(), DB::table('asset_files')->where(
                column: 'id',
                operator: '=',
                value: $file,
            )->value('filename')];
        } finally {
            DB::purge('writer');
            PlaceAssetTables::drop();
        }

        $this->assertTrue($landed);
        $this->assertSame([1, 2], $videos);
        $this->assertSame([2, 'drone.mov'], $state);
    }

    #[Test]
    public function it_serves_every_legacy_row_with_the_same_columns_and_values_once_installed(): void
    {
        $before = $this->listings();

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame('v', $this->kind('listings'));
        $this->assertSame($before, $this->listings());
        $this->assertSame(['id', 'uuid', 'job_id', 'mls_number', 'featured'], array_keys((array) DB::selectOne('select * from listings_retained limit 1')));
    }

    #[Test]
    public function it_never_serves_a_successor_key_as_a_legacy_identifier(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        DB::table('jobs')->insert(['name' => 'Birch Barn', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame(0, DB::scalar('select count(*) from listings where cast(uuid as text) in (select cast(id as text) from jobs)'));
        $this->assertSame(3, DB::scalar('select count(*) from listings where uuid is not null and uuid not in (select id from jobs)'));
    }

    #[Test]
    public function it_refuses_every_write_through_the_view(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
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
    public function it_keeps_each_inbound_foreign_key_on_the_retained_table_and_adds_one_to_the_root(): void
    {
        $before = $this->foreignKeys();

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $installed = $this->foreignKeys();
        Compatibility::rollback(Listing::class);

        $this->assertSame([
            'listing_photos_listing_id_foreign' => 'FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE',
            'listing_shares_listing_uuid_foreign' => 'FOREIGN KEY (listing_uuid) REFERENCES listings(uuid) ON UPDATE CASCADE ON DELETE SET NULL',
        ], $before);
        $this->assertSame([
            'listing_photos_listing_id_foreign' => 'FOREIGN KEY (listing_id) REFERENCES listings_retained(id) ON DELETE CASCADE',
            'listing_shares_listing_uuid_foreign' => 'FOREIGN KEY (listing_uuid) REFERENCES listings_retained(uuid) ON UPDATE CASCADE ON DELETE SET NULL',
            'listings_retained_job_id_foreign' => 'FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE NOT VALID',
        ], $installed);
        $this->assertSame($before, $this->foreignKeys());
    }

    #[Test]
    public function it_round_trips_every_legacy_column_value_through_install_and_rollback(): void
    {
        $before = $this->listings();

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Listing::class);

        $this->assertSame('r', $this->kind('listings'));
        $this->assertNull($this->kind('listings_retained'));
        $this->assertSame($this->sorted($before), $this->sorted($this->listings()));
        $this->assertSame(['listing_id' => 1, 'path' => 'oak/front.jpg'], (array) DB::selectOne('select listing_id, path from listing_photos order by id limit 1'));
    }

    #[Test]
    public function it_restores_the_legacy_table_with_its_keys_defaults_and_sequence(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Listing::class);

        DB::table('listings')->insert(['title' => 'Cedar Cabin', 'company_id' => ListingTables::COMPANY]);

        $this->assertEquals((object) ['id' => 4, 'status' => 'Draft', 'featured' => false, 'has_uuid' => true], DB::selectOne("select id, status, featured, uuid is not null as has_uuid from listings where title = 'Cedar Cabin'"));
        $this->assertSame(
            ['listings_job_id_unique' => 'UNIQUE (job_id)', 'listings_pkey' => 'PRIMARY KEY (id)', 'listings_uuid_unique' => 'UNIQUE (uuid)'],
            array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'listings'::regclass and contype in ('p', 'u') order by conname"), 'definition', 'conname'),
        );
        $this->assertSame('public.listings_id_seq', DB::scalar("select pg_get_serial_sequence('listings', 'id')"));
    }

    #[Test]
    public function it_grants_the_view_every_privilege_the_legacy_table_granted(): void
    {
        $role = 'compatibility_reader_'.getmypid();
        DB::statement("create role {$role}");

        try {
            DB::statement("grant select on listings to {$role}");
            DB::statement("grant update on listings to {$role} with grant option");

            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

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

            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

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

            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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

            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

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

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $served = [$createdAt('UTC'), $createdAt('America/Denver')];
        DB::statement("set time zone 'America/Denver'");

        try {
            Compatibility::rollback(Listing::class);
        } finally {
            DB::statement("set time zone 'UTC'");
        }

        $this->assertSame(['2026-01-01 10:00:00', '2026-01-01 10:00:00', '2026-01-01 10:00:00'], [...$served, $createdAt('UTC')]);
    }

    #[Test]
    public function it_installs_and_rolls_back_over_a_foreign_key_from_the_legacy_table_to_itself(): void
    {
        DB::statement('alter table listings add column parent_listing_id integer references listings (id)');
        DB::update('update listings set parent_listing_id = 1 where id = 3');
        $foreignKey = fn () => (array) DB::selectOne("select conrelid::regclass::text as on_table, pg_get_constraintdef(oid) as definition, convalidated from pg_constraint where conname = 'listings_parent_listing_id_fkey'");

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $installed = $foreignKey();
        Compatibility::rollback(Listing::class);

        $this->assertSame(['on_table' => 'listings_retained', 'definition' => 'FOREIGN KEY (parent_listing_id) REFERENCES listings_retained(id)', 'convalidated' => true], $installed);
        $this->assertSame(['on_table' => 'listings', 'definition' => 'FOREIGN KEY (parent_listing_id) REFERENCES listings(id)', 'convalidated' => true], $foreignKey());
    }

    #[Test]
    public function it_keeps_an_inbound_foreign_key_that_was_not_valid_before_install_not_valid_through_update_and_rollback(): void
    {
        DB::statement('create table listing_notes (listing_id integer)');
        $foreignKey = fn () => (array) DB::selectOne("select pg_get_constraintdef(oid) as definition, convalidated from pg_constraint where conname = 'listing_notes_listing_id_foreign'");

        try {
            DB::insert('insert into listing_notes (listing_id) values (1), (99)');
            DB::statement('alter table listing_notes add constraint listing_notes_listing_id_foreign foreign key (listing_id) references listings (id) not valid');
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $installed = $foreignKey();
            Compatibility::update(Listing::class);
            $recompiled = $foreignKey();
            Compatibility::rollback(Listing::class);
            $uninstalled = $foreignKey();
        } finally {
            DB::statement('drop table listing_notes');
        }

        $this->assertSame([
            ['definition' => 'FOREIGN KEY (listing_id) REFERENCES listings_retained(id) NOT VALID', 'convalidated' => false],
            ['definition' => 'FOREIGN KEY (listing_id) REFERENCES listings_retained(id) NOT VALID', 'convalidated' => false],
            ['definition' => 'FOREIGN KEY (listing_id) REFERENCES listings(id) NOT VALID', 'convalidated' => false],
        ], [$installed, $recompiled, $uninstalled]);
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

            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            Compatibility::rollback(Listing::class);

            $this->assertSame($before, $this->shape());
            $this->assertSame(['company_id', 'featured', 'id', 'status', 'title', 'uuid'], $before['not null']);
            $this->assertSame(['listings_company_id_foreign', 'listings_job_id_unique', 'listings_pkey', 'listings_price_check', 'listings_uuid_unique'], array_keys($before['constraints']));
            $this->assertSame(['listings_job_id_unique', 'listings_lower_title_index', 'listings_pkey', 'listings_status_index', 'listings_updated_at_index', 'listings_uuid_unique'], array_keys($before['indexes']));
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
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.'),
                        )
                        ->attribute(
                            name: 'title_upper',
                            definition: fn (Attribute $attribute) => $attribute->readAs('upper("jobs"."name")')->readOnly(because: 'The legacy table generates it.'),
                        ));
            }
        };

        Compatibility::install(
            model: $model::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $served = DB::scalar('select title_upper from listings where id = 1');
        Compatibility::rollback($model::class);

        $this->assertSame('OAK HOUSE', $served);
        $this->assertEquals(
            (object) ['title_upper' => 'OAK HOUSE', 'generated' => 's', 'expression' => 'upper((title)::text)'],
            DB::selectOne("select title_upper, (select attgenerated::text from pg_attribute where attrelid = 'listings'::regclass and attname = 'title_upper') as generated, (select pg_get_expr(adbin, adrelid) from pg_attrdef join pg_attribute on attrelid = adrelid and attnum = adnum where adrelid = 'listings'::regclass and attname = 'title_upper') as expression from listings where id = 1"),
        );
    }

    #[Test]
    public function it_reports_how_many_legacy_rows_a_hide_rule_hides_at_install(): void
    {
        DB::update('update jobs set merged_into_job_id = (select job_id from listings where id = 3) where id = (select job_id from listings where id = 1)');
        $notices = [];
        $pdo = DB::connection()->getPdo();
        $this->assertInstanceOf(Pgsql::class, $pdo);
        $pdo->setNoticeCallback(function (string $notice) use (&$notices): void {
            $notices[] = $notice;
        });

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
        } finally {
            $pdo->setNoticeCallback(null);
        }

        $this->assertStringContainsString("Compatibility map 'listings' install hides 1 listings rows, because a hide() rule matches their jobs row; the first are id 1.", implode("\n", $notices));
        $this->assertSame([2, 3], array_map(intval(...), array_column(DB::select('select id from listings order by id'), 'id')));
    }

    #[Test]
    public function it_hides_a_merged_job_from_the_view(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        DB::update('update jobs set merged_into_job_id = (select job_id from listings where id = 3) where id = (select job_id from listings where id = 1)');

        $this->assertSame([2, 3], array_map(intval(...), array_column(DB::select('select id from listings order by id'), 'id')));
    }

    #[Test]
    public function it_deletes_the_retained_row_with_its_job(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        DB::delete('delete from jobs where id = (select job_id from listings where id = 3)');

        $this->assertSame([1, 2], array_map(intval(...), array_column(DB::select('select id from listings order by id'), 'id')));
        $this->assertSame(2, DB::scalar('select count(*) from listings_retained'));
        $this->assertSame(['oak/front.jpg', 'oak/back.jpg'], array_column(DB::select('select path from listing_photos order by id'), 'path'));
    }

    #[Test]
    public function it_refuses_to_install_while_a_legacy_row_has_no_primary(): void
    {
        DB::delete('delete from jobs where id in (select job_id from listings where id in (1, 3))');

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $this->fail('install() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'listings' cannot install: 2 listings rows have no jobs row that their job_id names; the first are id 1, 3. Backfill them before install().", $exception->getMessage());
        }

        $this->assertSame('r', $this->kind('listings'));
        $this->assertSame([], DB::select("select conname from pg_constraint where confrelid = 'jobs'::regclass and conname like 'listing%'"));
    }

    #[Test]
    public function it_installs_and_rolls_back_inside_a_caller_s_transaction_when_no_catch_up_is_pending(): void
    {
        DB::transaction(function (): void {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            Compatibility::rollback(Listing::class);
        });

        $this->assertSame(['r', null], [$this->kind('listings'), $this->kind('listings_retained')]);
    }

    #[Test]
    public function it_refuses_to_install_while_a_legacy_row_holds_an_external_value_its_read_does_not_return(): void
    {
        $this->prepareFiles();
        FakeMediaLibrary::bound()->put(
            asset: (string) DB::table('files')->where(
                column: 'id',
                operator: '=',
                value: $this->fileOf(2),
            )->value('zml_asset_id'),
            metadata: new FileMetadata('image/png'),
        );
        DB::table('files')->where(
            column: 'id',
            operator: '=',
            value: $this->fileOf(3),
        )->update(['zml_asset_id' => null]);

        try {
            Compatibility::install(
                model: Photo::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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
            Compatibility::install(
                model: Photo::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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
                    ->retainedIn('photos_retained')
                    ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
                        ->when(fn (Builder $files) => $files->where('mime_type', 'like', 'image/%'))
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'file_name',
                            definition: fn (Attribute $attribute) => $attribute->from('filename'),
                        )
                        ->attribute(
                            name: 'mime_type',
                            definition: fn (Attribute $attribute) => $attribute->external(
                                read: fn (Collection $files): array => $files->mapWithKeys(fn (Model $file) => [$file->getKey() => DB::table('photos')->where(
                                    column: 'file_id',
                                    operator: '=',
                                    value: $file->getKey(),
                                )->value('id') === 1 ? 'image/jpeg' : true])->all(),
                                write: fn (File $file, mixed $value) => null),
                        )
                        ->attribute(
                            name: 'size_kb',
                            definition: fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000),
                        ));
            }
        };

        try {
            Compatibility::install(
                model: $truthful::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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
            Compatibility::install(
                model: Photo::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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
            Compatibility::install(
                model: Photo::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $refusal) {
            $this->assertSame("Compatibility map 'photos' is declared wrongly: photos.mime_type owns the sequence public.photos_mime_type_seq, and attribute 'mime_type' is external(), so install() would drop the sequence with the column and rollback() could not restore the default that reads it; detach it with ALTER SEQUENCE public.photos_mime_type_seq OWNED BY NONE first.", $refusal->getMessage());
        }

        $this->assertSame(['r', 'public.photos_mime_type_seq'], [$this->kind('photos'), DB::scalar("select pg_get_serial_sequence('photos', 'mime_type')")]);
        $this->assertSame([], FakeMediaLibrary::bound()->calls);

        DB::statement('alter sequence photos_mime_type_seq owned by none');
        Compatibility::install(
            model: Photo::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Photo::class);
        DB::table('photos')->insert(['file_name' => 'ash.jpg']);

        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png', 'image/1'], array_column(DB::select('select mime_type from photos order by id'), 'mime_type'));

        DB::statement('alter sequence photos_mime_type_seq owned by photos.mime_type');
    }

    #[Test]
    public function it_restores_the_default_and_not_null_flag_of_an_external_column_once_its_values_are_back(): void
    {
        $this->prepareFiles();
        DB::statement("alter table photos alter column mime_type set default 'application/octet-stream'");

        Compatibility::install(
            model: Photo::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Photo::class);
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

        Compatibility::install(
            model: Photo::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Photo::class);

        $this->assertSame(['C', 'C'], [$before, $collation()]);
        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png'], array_column(DB::select('select mime_type from photos order by id'), 'mime_type'));
    }

    #[Test]
    public function it_pages_install_verification_and_rollback_restore_by_an_indexed_key_without_an_offset(): void
    {
        $this->prepareFiles();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        Compatibility::install(
            model: Photo::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Photo::class);

        $paged = array_values(array_filter($statements, fn (string $sql) => str_contains($sql, ' limit 500')));

        $this->assertCount(2, $paged);
        $this->assertMatchesRegularExpression('/^select "id", "mime_type" from "photos" (where "id" is not null )?order by "id" asc limit 500$/', $paged[0]);
        $this->assertMatchesRegularExpression('/^select "id" from "photos" (where "id" is not null )?order by "id" asc limit 500$/', $paged[1]);
        $this->assertStringEndsWith(' WHERE "photos"."id" = CAST(restored.key AS integer)', array_values(array_filter(
            array: $statements,
            callback: fn (string $sql) => str_starts_with(
                haystack: $sql,
                needle: 'UPDATE "photos" SET "mime_type"',
            ),
        ))[0] ?? '');
    }

    #[Test]
    public function it_stores_no_external_value_once_installed_and_restores_each_through_its_read_on_rollback(): void
    {
        $this->prepareFiles();
        DB::table('photos')->where('id', 2)->update(['mime_type' => 'image/webp']);
        DB::table('files')->where(
            column: 'id',
            operator: '=',
            value: $this->fileOf(2),
        )->update(['mime_type' => 'image/webp']);
        FakeMediaLibrary::bound()->put(
            asset: (string) DB::table('files')->where(
                column: 'id',
                operator: '=',
                value: $this->fileOf(2),
            )->value('zml_asset_id'),
            metadata: new FileMetadata('image/webp'),
        );
        $before = DB::select('select id, uuid, file_name, mime_type, caption, views, size_kb, created_at, updated_at from photos order by id');

        Compatibility::install(
            model: Photo::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $installed = DB::select("select table_name from information_schema.columns where table_name in ('photos', 'photos_retained') and column_name = 'mime_type'");
        Photo::query()->findOrFail(3)->update(['mime_type' => 'image/gif']);
        Compatibility::rollback(Photo::class);

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
            Compatibility::install(
                model: $model::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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
            Compatibility::install(
                model: $model::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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
            'a successor key without its unique index' => ['alter table listings drop constraint listings_job_id_unique', 'listings.job_id has no unique index of its own'],
            'no backfill ledger' => ['drop table listings_backfill', 'listings_backfill does not exist'],
            'no backfill row' => ['delete from compatibility_backfills', 'compatibility_backfills holds no row for listings'],
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
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $outcome = [$concurrent, (int) DB::scalar($exists), DB::scalar("select relkind::text from pg_class where oid = to_regclass('listings')")];
        } finally {
            DB::purge('compatibility_concurrent');
            DB::statement('drop table if exists listing_notes');
        }

        $this->assertSame(['55P03', 0, 'v'], $outcome);
    }

    #[Test]
    public function it_holds_back_a_write_to_a_related_successor_until_rollback_commits_the_values_it_copied(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
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
                    DB::connection('compatibility_concurrent')->update("update places set street = '9 Moved St' where job_id = (select job_id from listings_retained where id = 1)");
                });
            } catch (QueryException $exception) {
                $concurrent = (string) $exception->getCode();
            }
        });

        try {
            Compatibility::rollback(Listing::class);
        } finally {
            DB::purge('compatibility_concurrent');
        }

        $this->assertSame(
            ['55P03', '1 Oak St', '1 Oak St'],
            [$concurrent, DB::scalar('select street from listings where id = 1'), DB::scalar('select street from places where job_id = (select job_id from listings where id = 1)')],
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

            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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
        DB::update('update jobs set score = 0.01 where id = (select job_id from listings where id = 1)');
        DB::update('update jobs set score = 0.009999999999999998 where id = (select job_id from listings where id = 3)');
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

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
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.'),
                        ))
                    ->hide(fn (Builder $jobs) => $jobs->where('score', 0.009999999999999998), because: 'A scored job is internal.');
            }
        };
        $hidden = array_map(
            callback: intval(...),
            array: DB::table('listings')->whereIn(
                column: 'job_id',
                values: Job::query()->withTrashed()->where(
                    column: 'score',
                    operator: '=',
                    value: 0.009999999999999998,
                )->select('id'),
            )->pluck('id')->all(),
        );

        Compatibility::install(
            model: $model::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([[1], [2, 3]], [$hidden, array_map(intval(...), DB::table('listings')->orderBy('id')->pluck('id')->all())]);
    }

    /**
     * @return array<string, array{string, string, Closure(Builder<Job>): mixed, bool}>
     */
    public static function hideBindings(): array
    {
        return [
            'a backslash in a string, with standard_conforming_strings off' => ['alter table jobs add column note text', "update jobs set note = 'C:\\new' where id = (select job_id from listings where id = 3)", static fn (Builder $jobs) => $jobs->where(
                column: 'note',
                operator: '=',
                value: 'C:\\new',
            ), true],
            'a backslash before a quote, with standard_conforming_strings off' => ['alter table jobs add column note text', "update jobs set note = 'it\\''s' where id = (select job_id from listings where id = 3)", static fn (Builder $jobs) => $jobs->where(
                column: 'note',
                operator: '=',
                value: "it\\'s",
            ), true],
            'an integer against a boolean column' => ['alter table jobs add column archived boolean not null default false', 'update jobs set archived = true where id = (select job_id from listings where id = 3)', static fn (Builder $jobs) => $jobs->where(
                column: 'archived',
                operator: '=',
                value: 1,
            ), false],
            'a boolean against an integer column' => ['alter table jobs add column rank integer not null default 0', 'update jobs set rank = 1 where id = (select job_id from listings where id = 3)', static fn (Builder $jobs) => $jobs->where(
                column: 'rank',
                operator: '=',
                value: true,
            ), false],
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
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.'),
                        ))
                    ->hide(self::$hide ?? fn (Builder $jobs) => $jobs, because: 'A marked job is internal.');
            }
        };
        $model::$hide = $hide;
        $hidden = array_map(
            callback: intval(...),
            array: DB::table('listings')->whereIn(
                column: 'job_id',
                values: Job::query()->withTrashed()->where($hide)->select('id'),
            )->pluck('id')->all(),
        );

        try {
            DB::statement('set standard_conforming_strings = '.($escapesOff ? 'off' : 'on'));
            Compatibility::install(
                model: $model::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
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

            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $installed = [$collations(), $behaviour()];
            Compatibility::update(Listing::class);
            $recompiled = [$collations(), $behaviour()];
            Compatibility::rollback(Listing::class);

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
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.'),
                        )
                        ->attribute(
                            name: 'listed_wall',
                            definition: fn (Attribute $attribute) => $attribute->readAs('"listings_retained"."listed_before"')->readOnly(because: 'The legacy table keeps the first listing time.'),
                        ));
            }
        };
        $read = fn () => DB::table('listings')->orderBy('id')->pluck('listed_wall', 'id')->map(fn (mixed $value) => $value === null ? null : (string) $value)->all();

        Compatibility::install(
            model: $model::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $served = [$this->kind('listings'), $read()];
        Compatibility::update($model::class);
        $recompiled = $read();
        Compatibility::rollback($model::class);

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
                Compatibility::install(
                    model: $model::class,
                    statementTimeout: CarbonInterval::seconds(30),
                );
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
    public function it_updates_an_installed_view_in_place(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        DB::statement('grant select on listings to public');
        $before = $this->listings();

        Compatibility::update(Listing::class);

        $this->assertSame($before, $this->listings());
        $this->assertSame(1, DB::scalar("select count(*) from information_schema.role_table_grants where table_name = 'listings' and grantee = 'PUBLIC'"));
    }

    #[Test]
    public function it_catches_up_the_inserts_updates_and_hard_deletes_that_land_after_its_last_pass_before_it_takes_the_lock(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::table('jobs')->insert(['name' => 'Native job', 'company_id' => ListingTables::COMPANY]);
        DB::update('update jobs set merged_into_job_id = (select job_id from listings where id = 1) where id = (select job_id from listings where id = 2)');
        $writer = $this->writer();
        $landed = false;
        DB::listen(function (QueryExecuted $query) use ($writer, &$landed): void {
            if (! $landed && $query->connectionName === DB::getDefaultConnection() && str_starts_with(
                haystack: $query->sql,
                needle: 'update "compatibility_backfills" set watermark_at',
            )) {
                $landed = true;
                $writer->insert("insert into listings (title, company_id) values ('Ash Barn', ?)", [ListingTables::COMPANY]);
                $writer->update("update listings set title = 'Oak Manor', updated_at = localtimestamp(0) where id = 1");
                $writer->delete('delete from listings where id in (2, 3)');
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertTrue($landed);
        $this->assertSame([[1, 'Oak Manor'], [4, 'Ash Barn']], array_map(
            callback: fn (array $row) => [$row['id'], $row['title']],
            array: $this->listings(),
        ));
        $this->assertSame(['Ash Barn', 'Native job', 'Oak Manor', 'Pine Flat'], DB::table('jobs')->orderBy('name')->pluck('name')->all());
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
            if ($query->connectionName === DB::getDefaultConnection() && str_starts_with(
                haystack: $query->sql,
                needle: 'update "compatibility_backfills" set watermark_at',
            )) {
                $passes++;
                $churn();
            }
        });
        $churn();

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertStringStartsWith("Compatibility map 'listings' cannot install: its catch-up pass 5 still read 1100 legacy rows and changed 1100 successor rows, and install() takes the lock only once a pass reads and changes fewer than 1000 rows each. Run php artisan compatibility:backfill ".Listing::class.' again, then install()', $exception->getMessage());
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

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

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
        DB::listen(function (QueryExecuted $query) use (&$locked): void {
            if ($locked === null && $query->sql === 'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE') {
                $locked = microtime(true);
                usleep(500000);
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
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
        $this->assertSame([], array_values(array_filter(
            array: $written,
            callback: fn (array $write) => $write[1] - $write[0] >= 0.3 && $write[1] < $locked,
        )));
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
        DB::delete('delete from jobs where id = (select job_id from listings where id = 2)');

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $this->fail('install() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'listings' cannot install: 1 listings rows have no jobs row that their job_id names; the first are id 2. Backfill them before install().", $exception->getMessage());
        }

        $this->assertSame('r', $this->kind('listings'));
    }

    #[Test]
    public function it_retries_the_lock_after_postgresql_ends_its_attempt_to_break_a_deadlock_with_a_legacy_writer(): void
    {
        DB::beginTransaction();

        try {
            DB::statement("set local deadlock_timeout = '10s'");
            Listing::query()->findOrFail(1);
            $install = $this->startInAnotherProcess('Illuminate\Support\Facades\DB::listen(function ($query) { if ($query->sql === \'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE\') { usleep(1500000); } });'
                .' Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class, statementTimeout: \\Carbon\\CarbonInterval::seconds(30))');
            $this->awaitHolder('ShareRowExclusiveLock');
            DB::table('listings')->where('id', 1)->update(['title' => 'Oak Manor']);
        } finally {
            DB::commit();
        }

        $this->assertSame([0, ''], $this->finish($install));
        $this->assertSame('v', $this->kind('listings'));
    }

    #[Test]
    public function it_catches_up_on_a_reinstall_the_legacy_writes_made_after_a_rollback_once_prepare_and_a_pass_run_again(): void
    {
        $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Listing::class);
        Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $refusal = 'no refusal';
        } catch (QueryException $exception) {
            $refusal = $exception->getMessage();
        }

        Compatibility::prepare(Listing::class);
        $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertStringContainsString("Compatibility map 'listings' cannot install: compatibility_backfills holds no row for listings, so prepare() has not finished.", $refusal);
        $this->assertSame(['v', 'Oak Manor'], [$this->kind('listings'), DB::table('listings')->where('id', 1)->value('title')]);
    }

    #[Test]
    public function it_deletes_the_job_of_a_listing_hard_deleted_between_the_backfill_and_install(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::delete('delete from listings where id = 3');

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([1, 2], array_column($this->listings(), 'id'));
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame(['listings_backfill', 'deleted'], [DB::scalar("select to_regclass('listings_backfill')::text"), DB::table('listings_backfill')->where(
            column: 'legacy_id',
            operator: '=',
            value: 3,
        )->value('status')]);
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

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([[1, 'Oak House'], [2, 'Pine Flat']], array_map(
            callback: fn (array $row) => [$row['id'], $row['title']],
            array: $this->listings(),
        ));
        $this->assertSame(['Native job', 'Oak House', 'Pine Flat', 'Prior job'], DB::table('jobs')->orderBy('name')->pluck('name')->all());
    }

    #[Test]
    public function it_deletes_on_a_reinstall_the_job_of_a_listing_hard_deleted_after_an_rollback_and_keeps_a_job_created_since(): void
    {
        $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Listing::class);
        DB::delete('delete from listings where id = 3');
        DB::table('jobs')->insert(['name' => 'Native job', 'company_id' => ListingTables::COMPANY]);
        Compatibility::prepare(Listing::class);
        $this->backfiller(Listing::class)->run();

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([[1, 'Oak House'], [2, 'Pine Flat']], array_map(
            callback: fn (array $row) => [$row['id'], $row['title']],
            array: $this->listings(),
        ));
        $this->assertSame(['Native job', 'Oak House', 'Pine Flat'], DB::table('jobs')->orderBy('name')->pluck('name')->all());
    }

    #[Test]
    public function it_catches_up_on_a_reinstall_a_legacy_edit_that_a_transaction_open_since_before_the_rollback_commits_after_it(): void
    {
        $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $writer = $this->writer();
        $writer->beginTransaction();

        try {
            $writer->select('select 1');
            usleep(1200000);
            Compatibility::rollback(Listing::class);
            $writer->update("update listings set title = 'Oak Manor', updated_at = localtimestamp where id = 1");
            $writer->commit();
        } finally {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
        }

        Compatibility::prepare(Listing::class);
        $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

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

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertTrue($deleted);
        $this->assertSame([1, 2], array_column($this->listings(), 'id'));
        $this->assertSame(2, DB::table('jobs')->count());
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
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        ));
            }
        };
        $this->backfiller($listing::class)->run();

        $this->expectExceptionObject(InvalidCompatibilityMap::cannot('listings', 'install', 'listings has no updated_at column its legacy model writes, so install() cannot limit its catch-up to the rows written since the backfill, and would walk every legacy row while it holds the lock'));

        Compatibility::install(
            model: $listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
    }

    #[Test]
    public function it_refuses_to_take_the_lock_while_its_last_catch_up_pass_still_reads_a_thousand_rows_it_changes_nothing_in(): void
    {
        DB::statement("insert into listings (title, company_id, created_at, updated_at) select 'Bulk ' || n, ?, localtimestamp(0), localtimestamp(0) from generate_series(1, 1100) as n", [ListingTables::COMPANY]);
        $this->backfiller(Listing::class)->run();
        $holding = $this->holdConcurrently('select 1', 30);
        $this->writer()->update("update listings set title = title || '.', updated_at = localtimestamp(0) where title like 'Bulk %'");

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertStringStartsWith("Compatibility map 'listings' cannot install: its catch-up pass 5 still read 1100 legacy rows and changed 0 successor rows, and install() takes the lock only once a pass reads and changes fewer than 1000 rows each.", $exception->getMessage());
        }

        $this->assertSame(["locked\n", 'r'], [$holding, $this->kind('listings')]);
    }

    #[Test]
    public function it_installs_and_rolls_back_two_maps_whose_legacy_tables_reference_each_other_in_either_order(): void
    {
        $this->prepareFiles();
        DB::statement('alter table legacy_files add column photo_id integer references photos (id)');
        DB::statement('alter table photos add column legacy_file_id integer references legacy_files (id)');
        DB::update('update legacy_files set photo_id = 1 where id = 1');
        DB::update('update photos set legacy_file_id = 1 where id = 2');
        $foreignKeys = fn () => array_column(
            array: DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where contype = 'f' and conrelid in (to_regclass('photos'), to_regclass('photos_retained'), to_regclass('legacy_files'), to_regclass('legacy_files_retained')) and conname not like '%_file_id_foreign' order by conname"),
            column_key: 'definition',
            index_key: 'conname',
        );
        $before = [DB::select('select * from photos order by id'), DB::select('select * from legacy_files order by id'), $foreignKeys()];

        foreach ([[Photo::class, LegacyFile::class], [LegacyFile::class, Photo::class]] as $order) {
            foreach ($order as $model) {
                Compatibility::install(
                    model: $model,
                    statementTimeout: CarbonInterval::seconds(30),
                );
            }

            $served = [DB::select('select * from photos order by id'), DB::select('select * from legacy_files order by id')];
            $codes = [];

            foreach ([
                fn () => LegacyFile::query()->findOrFail(2)->update(['photo_id' => 999]),
                fn () => Photo::query()->findOrFail(3)->update(['legacy_file_id' => 999]),
            ] as $write) {
                try {
                    $write();
                    $codes[] = 'stored';
                } catch (QueryException $exception) {
                    $codes[] = $exception->getCode();
                }
            }

            foreach (array_reverse($order) as $model) {
                Compatibility::rollback($model);
                Compatibility::prepare($model);
            }

            $this->assertEquals([array_map(
                callback: fn (object $row) => (object) array_diff_key((array) $row, ['mime_type' => true]),
                array: $before[0],
            ), $before[1]], array_map(
                callback: fn (array $rows) => array_map(
                    callback: fn (object $row) => (object) array_intersect_key((array) $row, (array) ($rows === $served[0] ? $before[0][0] : $before[1][0])),
                    array: $rows,
                ),
                array: $served,
            ));
            $this->assertSame(['23503', '23503'], $codes);
            $this->assertEquals($before, [DB::select('select * from photos order by id'), DB::select('select * from legacy_files order by id'), $foreignKeys()]);
        }
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
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
        } finally {
            DB::purge('compatibility_concurrent');
        }

        $this->assertSame(['55P03', 'v', false], [$concurrent, $this->kind('listings'), DB::scalar("select count(*) > 0 from pg_attribute where attrelid = 'listings_retained'::regclass and attname = 'notes'")]);
    }

    #[Test]
    public function it_adds_the_foreign_key_to_the_root_as_the_last_statement_under_its_lock_timeout_and_rollback_drops_it(): void
    {
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = [$query->sql, DB::transactionLevel()];
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $installed = array_values(array_filter(
            array: $statements,
            callback: fn (array $statement) => $statement[1] > 0,
        ));
        $last = end($installed);
        $timeouts = array_keys(array_filter(
            array: $installed,
            callback: fn (array $statement) => str_starts_with(
                haystack: $statement[0],
                needle: 'SET LOCAL lock_timeout',
            ),
        ));
        $foreignKey = (array) DB::selectOne("select conrelid::regclass::text as on_table, pg_get_constraintdef(oid) as definition, convalidated from pg_constraint where conname = 'listings_retained_job_id_foreign'");
        Compatibility::rollback(Listing::class);

        $this->assertIsArray($last);
        $this->assertSame('ALTER TABLE "listings_retained" ADD CONSTRAINT "listings_retained_job_id_foreign" FOREIGN KEY ("job_id") REFERENCES "jobs" ("id") ON DELETE CASCADE NOT VALID', $last[0]);
        $this->assertSame(["SET LOCAL lock_timeout = '5s'"], array_map(
            callback: fn (int $index) => $installed[$index][0],
            array: $timeouts,
        ));
        $this->assertSame(['on_table' => 'listings_retained', 'definition' => 'FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE NOT VALID', 'convalidated' => false], $foreignKey);
        $this->assertSame(0, DB::scalar("select count(*) from pg_constraint where conname in ('listings_retained_job_id_foreign', 'listings_retained_job_id_not_null')"));
    }

    #[Test]
    public function it_retries_the_install_when_the_foreign_key_cannot_take_its_lock_on_the_root_within_the_lock_timeout(): void
    {
        $holding = $this->holdConcurrently(
            sql: "update jobs set name = name where name = 'Oak House'",
            seconds: 6,
        );
        $attempts = 0;
        DB::listen(function (QueryExecuted $query) use (&$attempts): void {
            if (str_starts_with(
                haystack: $query->sql,
                needle: 'SET LOCAL lock_timeout',
            )) {
                $attempts++;
            }
        });
        $started = microtime(true);

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame(["locked\n", 2, 'v'], [$holding, $attempts, $this->kind('listings')]);
        $this->assertGreaterThanOrEqual(5.0, microtime(true) - $started);
        $this->assertSame('', $this->concurrentFailure());
    }

    #[Test]
    public function it_sets_the_statement_timeout_under_its_lock_and_rolls_back_once_the_hold_spends_it_naming_the_step(): void
    {
        $timeouts = [];
        DB::listen(function (QueryExecuted $query) use (&$timeouts): void {
            if (str_starts_with(
                haystack: $query->sql,
                needle: 'SET LOCAL statement_timeout',
            )) {
                $timeouts[] = $query->sql;
            }

            if ($query->sql === 'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE') {
                usleep(300000);
            }
        });

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::milliseconds(100),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame(["SET LOCAL statement_timeout = '100ms'"], $timeouts);
        $this->assertMatchesRegularExpression("/^Compatibility map 'listings' cannot install: it held its lock for \\d+ ms of its 100 ms statementTimeout before the step LOCK TABLE \"listings\" IN SHARE ROW EXCLUSIVE MODE, so it rolled back\\./", $refusal);
        $this->assertSame(['r', null], [$this->kind('listings'), $this->kind('listings_retained')]);
    }

    #[Test]
    public function it_runs_the_dependents_hooks_inside_the_install_and_rollback_transactions_around_a_view_over_the_legacy_table(): void
    {
        DB::statement('create view listing_titles as select id, title from listings');
        $dependents = new class implements ManagesDependents
        {
            /** @var list<array{string, int}> */
            public static array $calls = [];

            public function beforeInstall(Connection $connection): void
            {
                self::$calls[] = ['beforeInstall', $connection->transactionLevel()];
                $connection->statement('drop view listing_titles');
            }

            public function afterInstall(Connection $connection): void
            {
                self::$calls[] = ['afterInstall', $connection->transactionLevel()];
                $connection->statement('create view listing_titles as select id, title from listings');
            }

            public function beforeRollback(Connection $connection): void
            {
                self::$calls[] = ['beforeRollback', $connection->transactionLevel()];
                $connection->statement('drop view listing_titles');
            }

            public function afterRollback(Connection $connection): void
            {
                self::$calls[] = ['afterRollback', $connection->transactionLevel()];
                $connection->statement('create view listing_titles as select id, title from listings');
            }
        };
        self::$dependents = $dependents::class;
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return Listing::compatibility($listing)->dependents(InstallerTest::$dependents);
            }
        };

        try {
            Compatibility::install(
                model: $model::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $installed = [$this->kind('listings'), DB::table('listing_titles')->orderBy('id')->pluck('title')->all()];
            Compatibility::rollback($model::class);
            $rolledBack = [$this->kind('listings'), DB::table('listing_titles')->orderBy('id')->pluck('title')->all()];
        } finally {
            DB::statement('drop view if exists listing_titles');
        }

        $this->assertSame([['beforeInstall', 1], ['afterInstall', 1], ['beforeRollback', 1], ['afterRollback', 1]], $dependents::$calls);
        $this->assertSame([['v', ['Oak House', 'Pine Flat', 'Elm Cottage']], ['r', ['Oak House', 'Pine Flat', 'Elm Cottage']]], [$installed, $rolledBack]);
    }

    #[Test]
    public function it_carries_the_privileges_of_the_view_back_to_the_restored_table_at_rollback(): void
    {
        $role = 'compatibility_restored_'.getmypid();
        DB::statement("create role {$role}");

        try {
            DB::statement("grant select, delete on listings to {$role}");
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            DB::statement("revoke delete on listings from {$role}");
            DB::statement('grant select (title) on listings to public');

            Compatibility::rollback(Listing::class);

            $this->assertSame(
                ['r', true, false, true],
                [$this->kind('listings'), DB::scalar(
                    query: "select has_table_privilege(?, 'listings', 'SELECT')",
                    bindings: [$role],
                ), DB::scalar(
                    query: "select has_table_privilege(?, 'listings', 'DELETE')",
                    bindings: [$role],
                ), DB::scalar("select has_column_privilege('public', 'listings', 'title', 'SELECT')")],
            );
        } finally {
            DB::statement('revoke all on listings from public');
            DB::statement("drop owned by {$role}");
            DB::statement("drop role {$role}");
        }
    }

    #[Test]
    public function it_refuses_to_retire_while_an_inbound_foreign_key_or_a_dependent_view_reads_the_retained_table_and_retires_once_they_are_gone(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        DB::statement('create view listing_titles as select id, title from listings');

        try {
            Compatibility::retire(Listing::class);
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        DB::statement('drop view listing_titles');
        DB::statement('drop table listing_photos, listing_shares');
        Compatibility::retire(Listing::class);
        Compatibility::retire(Listing::class);

        $this->assertSame("Compatibility map 'listings' cannot retire: view listing_titles, the foreign key listing_photos_listing_id_foreign on listing_photos, the foreign key listing_shares_listing_uuid_foreign on listing_shares depend on listings or listings_retained; drop each one, then retire again.", $refusal);
        $this->assertSame([null, null, null, 3], [$this->kind('listings'), $this->kind('listings_retained'), $this->kind('listings_backfill'), DB::table('jobs')->count()]);
    }

    private function fileOf(int $photo): mixed
    {
        return DB::table('photos')->where(
            column: 'id',
            operator: '=',
            value: $photo,
        )->value('file_id');
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
        return array_column(
            array: DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where contype = 'f' and confrelid in (to_regclass('listings'), to_regclass('listings_retained'), to_regclass('jobs')) and conrelid not in ('jobs'::regclass, 'places'::regclass, 'job_sites'::regclass) order by conname"),
            column_key: 'definition',
            index_key: 'conname',
        );
    }

    private function kind(string $relation): null|string
    {
        $kind = DB::scalar('select relkind::text from pg_class where oid = to_regclass(?)', [$relation]);

        return is_string($kind) ? $kind : null;
    }
}
