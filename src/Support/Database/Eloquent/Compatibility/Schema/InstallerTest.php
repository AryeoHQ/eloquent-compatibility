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
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Console\Backfill;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Contracts\ManagesDependents;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Support\Database\Eloquent\Compatibility\Write\Origin;
use Support\Database\Eloquent\Compatibility\Write\Outcome;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Listings\InitializeLegacyListing;
use Tests\Fixtures\Support\Listings\InitializeNativeListing;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingOfAnyPlace;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\NameJobFromTitle;
use Tests\Fixtures\Support\Listings\RecordingWriteScope;
use Tests\Fixtures\Support\Listings\UnstampedJob;
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

    /** @var list<array{string, string}> */
    private array $logged = [];

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

        if ($this->connectionsToTransact !== []) {
            DB::rollBack(0);
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
            $updated = $foreignKey();
            Compatibility::rollback(Listing::class);
            $rolledBack = $foreignKey();
        } finally {
            DB::statement('drop table listing_notes');
        }

        $this->assertSame([
            ['definition' => 'FOREIGN KEY (listing_id) REFERENCES listings_retained(id) NOT VALID', 'convalidated' => false],
            ['definition' => 'FOREIGN KEY (listing_id) REFERENCES listings_retained(id) NOT VALID', 'convalidated' => false],
            ['definition' => 'FOREIGN KEY (listing_id) REFERENCES listings(id) NOT VALID', 'convalidated' => false],
        ], [$installed, $updated, $rolledBack]);
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
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: false),
                        )
                        ->attribute(
                            name: 'title_upper',
                            definition: fn (Attribute $attribute) => $attribute->readAs('upper("jobs"."name")')->readOnly(because: 'The legacy table generates it.', ignoreWrites: false),
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
        $this->listenToTheLog();
        $loggedBeforeTheLock = null;
        DB::listen(function (QueryExecuted $query) use (&$loggedBeforeTheLock): void {
            if ($loggedBeforeTheLock === null && str_starts_with(
                haystack: $query->sql,
                needle: 'SET LOCAL lock_timeout',
            )) {
                $loggedBeforeTheLock = $this->logged;
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([['info', "Compatibility map 'listings' install: hides 1 listings rows, because a hide() rule matches their jobs row; the first are id 1. Install read this count before it took its lock."]], array_values(array_filter(
            array: $loggedBeforeTheLock ?? [],
            callback: fn (array $logged) => str_contains(
                haystack: $logged[1],
                needle: ' hides ',
            ),
        )));
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
    public function it_recreates_at_install_the_root_of_a_created_row_whose_root_was_hard_deleted_with_its_key_and_links_its_surviving_place(): void
    {
        $this->backfiller(Listing::class)->run();
        $jobs = [ListingTables::jobOf(1), ListingTables::jobOf(3)];
        $places = DB::table('places')->orderBy('street')->pluck('id')->all();
        DB::statement('alter table places drop constraint places_job_id_foreign');
        DB::delete('delete from jobs where id in (select job_id from listings where id in (1, 3))');

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame('v', $this->kind('listings'));
        $this->assertSame([[1, 'Oak House', $jobs[0]], [3, 'Elm Cottage', $jobs[1]]], array_map(
            callback: fn (array $row) => [$row['id'], $row['title'], $row['job_id']],
            array: array_values(array_filter($this->listings(), fn (array $row) => $row['id'] !== 2)),
        ));
        $this->assertSame($places, DB::table('places')->orderBy('street')->pluck('id')->all());
        $this->assertSame(['1 Oak St', '3 Elm St'], array_column(DB::select('select street from listings where id in (1, 3) order by id'), 'street'));
    }

    #[Test]
    public function it_refuses_to_install_while_a_row_is_refused_naming_its_reason_and_installs_once_the_row_is_fixed(): void
    {
        DB::update("update listings set status = 'Archived' where id = 3");
        $pass = $this->backfiller(Listing::class)->run();

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $refused = $this->kind('listings');
        DB::update("update listings set status = 'Sold', updated_at = localtimestamp(0) where id = 3");
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([2, 1], [$pass->count(Outcome::Unchanged), $pass->count(Outcome::Refused)]);
        $this->assertStringStartsWith("Compatibility map 'listings' cannot install: 1 listings row is refused or failed in listings_backfill, and no row installs before every row can be served: 1 refused because A listing status is Draft, Active or Sold, and 'Archived' is none of them. (id 3).", $refusal);
        $this->assertSame(['r', 'v', 'Sold'], [$refused, $this->kind('listings'), DB::table('listings')->where('id', 3)->value('status')]);
    }

    #[Test]
    public function it_refuses_before_its_proofs_while_a_row_its_create_path_refused_has_no_root(): void
    {
        DB::insert("insert into listings (title, company_id, status, updated_at) values ('Ash Barn', ?, 'Archived', localtimestamp(0))", [ListingTables::COMPANY]);
        $this->backfiller(Listing::class)->run();

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringStartsWith("Compatibility map 'listings' cannot install: 1 listings row is refused or failed in listings_backfill, and no row installs before every row can be served: 1 refused because A listing status is Draft, Active or Sold, and 'Archived' is none of them. (id 4).", $refusal);
        $this->assertSame([null, 'r'], [DB::table('listings')->where('id', 4)->value('job_id'), $this->kind('listings')]);
    }

    #[Test]
    public function it_catches_up_before_its_lock_a_value_changed_without_touching_updated_at_and_installs(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::update("update listings set title = 'Oak Manor' where id = 1");

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame(['v', 'Oak Manor', 'updated'], [$this->kind('listings'), DB::table('listings')->where('id', 1)->value('title'), DB::table('listings_backfill')->where('legacy_id', 1)->value('status')]);
    }

    #[Test]
    public function it_refuses_to_install_a_value_that_still_differs_after_its_catch_up(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::update("update listings set title = 'Oak Manor' where id = 1");
        $writer = $this->writer();
        $proving = false;
        $reverted = false;
        DB::listen(function (QueryExecuted $query) use (&$proving): void {
            if (str_starts_with($query->sql, 'SELECT compared.key')) {
                $proving = true;
            }
        });
        Event::listen(TransactionCommitted::class, function () use (&$proving, &$reverted, $writer): void {
            if ($proving && ! $reverted && DB::transactionLevel() === 0) {
                $reverted = true;
                $writer->update("update jobs set name = 'Oak House' where id = ?", [ListingTables::jobOf(1)]);
            }
        });

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertTrue($reverted);
        $this->assertStringStartsWith("Compatibility map 'listings' cannot install: 1 listings rows still hold other values than their successors serve after install caught them up; the first are id 1.", $refusal);
        $this->assertSame('r', $this->kind('listings'));
        $this->assertSame(1, preg_match(
            pattern: "/ Run php artisan compatibility:check --model='([^']+)' to see each value, /",
            subject: $refusal,
            matches: $printed,
        ));
        $this->artisan('compatibility:check', ['--model' => [$printed[1]]])
            ->expectsOutputToContain('values differ')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_logs_each_catch_up_pass_before_its_lock_with_its_counts_and_the_keys_its_proofs_flag(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::update("update jobs set name = 'Renamed natively' where id = ?", [ListingTables::jobOf(1)]);
        $this->listenToTheLog();

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([
            ['info', "Compatibility map 'listings' install: catch-up pass 1 walked 0 listings rows and 0 whose legacy row is gone, and changed the outcome of 0: 0 refused, 0 failed"],
            ['info', "Compatibility map 'listings' install: its proofs flagged 1 listings rows, which it catches up before it proves them again"],
        ], array_values(array_filter(
            array: $this->logged,
            callback: fn (array $logged) => $logged[0] === 'info',
        )));
    }

    #[Test]
    public function it_keeps_a_root_a_pass_soft_deleted_out_of_every_install_and_retire_drops_the_ledger_and_the_watermark(): void
    {
        $this->backfiller(Listing::class)->run();
        $root = ListingTables::jobOf(3);
        DB::delete('delete from listings where id = 3');
        $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $first = DB::table('listings_retained')->where('job_id', $root)->count();
        Compatibility::rollback(Listing::class);
        Compatibility::prepare(Listing::class);
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $second = DB::table('listings_retained')->where('job_id', $root)->count();
        $ledger = (array) DB::selectOne('select status, attempts, successor_id from listings_backfill where legacy_id = 3');
        DB::statement('drop table listing_photos, listing_shares');
        DB::insert("insert into compatibility_backfills (legacy_type) values ('listings')");
        $tracked = DB::table('compatibility_backfills')->where('legacy_type', 'listings')->count();
        Compatibility::retire(Listing::class);

        $this->assertSame([0, 0], [$first, $second]);
        $this->assertSame(['status' => 'deleted', 'attempts' => 2, 'successor_id' => $root], $ledger);
        $this->assertTrue(DB::scalar('select deleted_at is not null from jobs where id = ?', [$root]));
        $this->assertSame([1, null, 0], [$tracked, DB::scalar("select to_regclass('listings_backfill')::text"), DB::table('compatibility_backfills')->where('legacy_type', 'listings')->count()]);
    }

    #[Test]
    public function it_inserts_under_its_lock_a_legacy_row_for_each_natively_created_member_root_through_its_initializer_with_origin_backfill(): void
    {
        $this->backfiller(Listing::class)->run();
        $native = Job::query()->create(['name' => 'Native job', 'company_id' => ListingTables::COMPANY]);
        InitializeLegacyListing::$seen = [];
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message;
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([[4, 'Native job', 'JOB-Native job', $native->getKey()]], array_map(
            callback: fn (array $row) => [$row['id'], $row['title'], $row['mls_number'], $row['job_id']],
            array: array_values(array_filter($this->listings(), fn (array $row) => $row['id'] === 4)),
        ));
        $this->assertSame([[4, $native->getKey(), Origin::Backfill]], InitializeLegacyListing::$seen);
        $this->assertContains("Compatibility map 'listings' install: 1 natively created Job root has no listings row, so install inserts one for each under its lock", $logged);
    }

    #[Test]
    public function it_inserts_a_legacy_row_for_a_root_created_after_its_pre_lock_read_and_within_the_margin(): void
    {
        $this->backfiller(Listing::class)->run();
        $writer = $this->writer();
        $created = null;
        DB::listen(function (QueryExecuted $query) use ($writer, &$created): void {
            if ($created === null && str_starts_with($query->sql, 'select "jobs"."id" from "jobs" where NOT EXISTS')) {
                $created = (string) $writer->table('jobs')->insertGetId(['name' => 'Late job', 'company_id' => ListingTables::COMPANY, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertIsString($created);
        $this->assertSame('Late job', DB::table('listings')->where('job_id', $created)->value('title'));
    }

    #[Test]
    public function it_aborts_install_naming_the_root_whose_legacy_initializer_refuses_under_the_lock(): void
    {
        $model = $this->nativeListing();
        Compatibility::prepare($model::class);
        $this->backfiller($model::class)->run();
        $refused = Job::query()->create(['name' => 'Refused job', 'company_id' => ListingTables::COMPANY]);

        try {
            Compatibility::install(
                model: $model::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame("Compatibility map 'listings' cannot install: InitializeNativeListing refused the legacy row of the natively created Job {$refused->getKey()}: A refused job has no listing..", $refusal);
        $this->assertSame([null, 'r'], [$this->kind('listings_retained'), $this->kind('listings')]);
    }

    #[Test]
    public function it_rolls_back_once_its_native_root_inserts_spend_the_statement_timeout_naming_that_step(): void
    {
        $model = $this->nativeListing();
        Compatibility::prepare($model::class);
        $this->backfiller($model::class)->run();

        foreach (['Ash job', 'Birch job', 'Cedar job', 'Dogwood job'] as $name) {
            Job::query()->create(['name' => $name, 'company_id' => ListingTables::COMPANY]);
        }

        InitializeNativeListing::$pause = 700000;

        try {
            Compatibility::install(
                model: $model::class,
                statementTimeout: CarbonInterval::milliseconds(1500),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        } finally {
            InitializeNativeListing::$pause = 0;
        }

        $this->assertMatchesRegularExpression("/^Compatibility map 'listings' cannot install: it held its lock for \\d+ ms of its 1500 ms statementTimeout before the step the insert of a natively created root's legacy row, so it rolled back\\./", $refusal);
        $this->assertSame('r', $this->kind('listings'));
    }

    #[Test]
    public function it_holds_the_advisory_lock_of_the_legacy_model_from_its_first_pass_until_it_finishes(): void
    {
        $this->backfiller(Listing::class)->run();
        $writer = $this->writer();
        $taken = null;
        DB::listen(function (QueryExecuted $query) use ($writer, &$taken): void {
            if ($taken === null && $query->sql === 'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE') {
                $taken = $writer->scalar("select pg_try_advisory_lock(hashtextextended('compatibility_backfills:listings', 0))");
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $after = $writer->scalar("select pg_try_advisory_lock(hashtextextended('compatibility_backfills:listings', 0))");
        $writer->scalar("select pg_advisory_unlock(hashtextextended('compatibility_backfills:listings', 0))");

        $this->assertSame([false, true], [$taken, $after]);
    }

    #[Test]
    public function it_rolls_back_when_its_final_pass_under_the_lock_refuses_a_row(): void
    {
        $model = $this->nativeListing();
        Compatibility::prepare($model::class);
        $this->backfiller($model::class)->run();
        $blanked = false;
        DB::listen(function (QueryExecuted $query) use (&$blanked): void {
            if (! $blanked && $query->sql === 'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE') {
                $blanked = true;
                DB::update("update listings set title = '', updated_at = localtimestamp(0) where id = 1");
            }
        });

        try {
            Compatibility::install(
                model: $model::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame("Compatibility map 'listings' cannot install: 1 listings row is refused or failed in listings_backfill, and no row installs before every row can be served: 1 refused because A job needs a name, and the listing title is blank. (id 1). Fix each row, run ".Backfill::command($model::class).', then install again.', $refusal);
        $this->assertSame(['r', 'Oak House'], [$this->kind('listings'), DB::table('listings')->where('id', 1)->value('title')]);
    }

    #[Test]
    public function it_rolls_back_when_the_write_of_a_row_fails_in_its_final_pass_under_the_lock(): void
    {
        $this->backfiller(Listing::class)->run();
        $failed = false;
        DB::listen(function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && $query->sql === 'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE') {
                $failed = true;
                DB::statement("alter table jobs add constraint jobs_name_is_not_secret check (name not like 'SECRET%')");
                DB::update("update listings set title = 'SECRET-1', updated_at = localtimestamp(0) where id = 1");
            }
        });

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame("Compatibility map 'listings' cannot install: 1 listings row is refused or failed in listings_backfill, and no row installs before every row can be served: 1 failed because Illuminate\\Database\\QueryException: SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation \"jobs\" violates check constraint \"jobs_name_is_not_secret\" (id 1). Fix each row, run ".Backfill::command(Listing::class).', then install again.', $refusal);
        $this->assertSame(['r', 'Oak House', 0], [$this->kind('listings'), DB::table('listings')->where('id', 1)->value('title'), DB::scalar("select count(*) from pg_constraint where conname = 'jobs_name_is_not_secret'")]);
    }

    #[Test]
    public function it_catches_up_before_its_lock_a_legacy_row_inserted_with_no_successor_key_and_an_updated_at_outside_its_watermark(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::insert("insert into listings (title, company_id, status, created_at, updated_at) values ('Ash Barn', ?, 'Draft', '2026-01-06 08:00:00', '2026-01-06 08:00:00')", [ListingTables::COMPANY]);

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame(['v', 'Ash Barn', 'created'], [$this->kind('listings'), DB::table('listings')->where('id', 4)->value('title'), DB::table('listings_backfill')->where('legacy_id', 4)->value('status')]);
    }

    #[Test]
    public function it_refuses_when_a_root_its_catch_up_recreated_is_missing_again_at_its_second_proof(): void
    {
        $this->backfiller(Listing::class)->run();
        $job = ListingTables::jobOf(2);
        DB::delete('delete from jobs where id = ?', [$job]);
        $writer = $this->writer();
        $proving = false;
        $removed = false;
        DB::listen(function (QueryExecuted $query) use (&$proving): void {
            if (str_starts_with($query->sql, 'SELECT compared.key')) {
                $proving = true;
            }
        });
        Event::listen(TransactionCommitted::class, function () use (&$proving, &$removed, $writer, $job): void {
            if ($proving && ! $removed && DB::transactionLevel() === 0) {
                $removed = true;
                $writer->delete('delete from jobs where id = ?', [$job]);
            }
        });

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        } finally {
            DB::purge('writer');
        }

        $this->assertTrue($removed);
        $this->assertSame("Compatibility map 'listings' cannot install: 1 listings rows still have no jobs row that their job_id names after install caught them up; the first are id 2. Backfill them before install().", $refusal);
        $this->assertSame('r', $this->kind('listings'));
    }

    #[Test]
    public function it_carries_under_its_lock_only_the_natively_created_roots_inside_the_margin_it_is_given(): void
    {
        $this->backfiller(Listing::class)->run();
        $writer = $this->writer();
        $created = null;
        DB::listen(function (QueryExecuted $query) use ($writer, &$created): void {
            if ($created === null && str_starts_with($query->sql, 'select "jobs"."id" from "jobs" where NOT EXISTS')) {
                $created = array_map(
                    callback: fn (string $age) => (string) $writer->scalar(
                        query: 'insert into jobs (name, company_id, created_at, updated_at) values (?, ?, now() - cast(? as interval), now()) returning id',
                        bindings: ["Job {$age} old", ListingTables::COMPANY, $age],
                    ),
                    array: ['30 seconds', '120 seconds'],
                );
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
            margin: CarbonInterval::seconds(60),
        );

        $this->assertIsArray($created);
        $this->assertSame(['Job 30 seconds old', null], array_map(
            callback: fn (string $job) => DB::table('listings')->where('job_id', $job)->value('title'),
            array: $created,
        ));
    }

    #[Test]
    public function it_calls_the_write_scope_in_the_passes_before_its_lock_and_not_in_its_final_pass(): void
    {
        $model = $this->scopedListing();
        Compatibility::prepare($model::class);
        $this->backfiller($model::class)->run();
        RecordingWriteScope::$received = [];
        DB::update("update listings set title = 'Oak Manor', updated_at = localtimestamp(0) where id = 1");
        $locked = null;
        DB::listen(function (QueryExecuted $query) use (&$locked): void {
            if ($locked === null && $query->sql === 'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE') {
                $locked = count(RecordingWriteScope::$received);
            }
        });

        Compatibility::install(
            model: $model::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertGreaterThan(0, $locked);
        $this->assertCount((int) $locked, RecordingWriteScope::$received);
        $this->assertSame('Oak Manor', DB::table('listings')->where('id', 1)->value('title'));
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
    public function it_installs_a_served_model_inside_the_transaction_refresh_database_opens(): void
    {
        $this->connectionsToTransact = [DB::getDefaultConnection()];
        $this->beginDatabaseTransaction();

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame(['v', 'r'], [$this->kind('listings'), $this->kind('listings_retained')]);
        $this->assertSame(['Oak House', 'Elm Cottage'], Listing::query()->orderBy('id')->pluck('title')->all());
    }

    #[Test]
    public function it_refuses_inside_a_caller_s_transaction_once_a_pass_left_a_watermark(): void
    {
        $this->artisan(
            command: 'compatibility:backfill',
            parameters: ['model' => Listing::class],
        )->assertSuccessful();
        DB::beginTransaction();

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $this->fail('install() did not refuse.');
        } catch (LogicException $exception) {
            $this->assertSame('Compatibility::install() catches up listings in transactions of its own before it takes the lock, and the connection is already inside a transaction. Run it from a migration that sets public $withinTransaction = false.', $exception->getMessage());
            $this->assertSame([1, 'r', null], [DB::transactionLevel(), $this->kind('listings'), $this->kind('listings_retained')]);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function it_installs_without_comparing_a_computed_value_its_read_does_not_return_and_serves_the_read(): void
    {
        $this->prepareFiles();
        $this->backfiller(Photo::class)->run();
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

        Compatibility::install(
            model: Photo::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame('v', $this->kind('photos'));
        $this->assertSame(['image/jpeg', 'image/png', null], Photo::query()->orderBy('id')->get()->map(fn (Photo $photo) => $photo->mime_type)->all());
    }

    #[Test]
    public function it_installs_without_comparing_a_null_legacy_value_where_the_computed_read_returns_one(): void
    {
        $this->prepareFiles();
        $this->backfiller(Photo::class)->run();
        DB::statement('alter table photos alter column mime_type drop not null');
        DB::table('photos')->where('id', 3)->update(['mime_type' => null]);

        Compatibility::install(
            model: Photo::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame('v', $this->kind('photos'));
        $this->assertSame('image/png', Photo::query()->findOrFail(3)->mime_type);
    }

    #[Test]
    public function it_installs_without_comparing_a_computed_read_that_returns_a_boolean_for_a_legacy_string(): void
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
                            definition: fn (Attribute $attribute) => $attribute->computedAs(read: fn (Collection $files): array => $files->mapWithKeys(fn (Model $file) => [$file->getKey() => DB::table('photos')->where(
                                column: 'file_id',
                                operator: '=',
                                value: $file->getKey(),
                            )->value('id') === 1 ? 'image/jpeg' : true])->all())->readOnly(because: 'The map reads it from outside the database.', ignoreWrites: false),
                        )
                        ->attribute(
                            name: 'size_kb',
                            definition: fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000),
                        ));
            }
        };

        Compatibility::install(
            model: $truthful::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame('v', $this->kind('photos'));
        $this->assertSame(['image/jpeg', true, true], $truthful::query()->orderBy('id')->get()->map(fn (Model $photo) => $photo->getAttribute('mime_type'))->all());
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
            $this->assertSame("Compatibility map 'photos' is declared wrongly: photos.mime_type is a generated column, and attribute 'mime_type' is computedAs(), so its value would come from outside the database and nothing would keep it generated from its columns; serve it with readAs() and readOnly().", $refusal->getMessage());
        }

        $this->assertSame(['r', 's'], [$this->kind('photos'), DB::scalar("select attgenerated::text from pg_attribute where attrelid = 'photos'::regclass and attname = 'mime_type'")]);
    }

    #[Test]
    public function it_refuses_to_install_an_external_attribute_over_a_legacy_column_that_owns_a_sequence(): void
    {
        $this->prepareFiles();
        $this->backfiller(Photo::class)->run();
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
            $this->assertSame("Compatibility map 'photos' is declared wrongly: photos.mime_type owns the sequence public.photos_mime_type_seq, and attribute 'mime_type' is computedAs(), so install() would drop the sequence with the column and rollback() could not restore the default that reads it; detach it with ALTER SEQUENCE public.photos_mime_type_seq OWNED BY NONE first.", $refusal->getMessage());
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
    public function it_pages_the_rollback_restore_by_an_indexed_key_without_an_offset(): void
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

        $this->assertCount(1, $paged);
        $this->assertMatchesRegularExpression('/^select "id" from "photos" (where "id" is not null )?order by "id" asc limit 500$/', $paged[0]);
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
            'a successor key without its unique index' => ['alter table listings drop constraint listings_job_id_unique', self::unfinished('listings.job_id has no unique index of its own')],
            'no backfill ledger' => ['drop table listings_backfill', "Compatibility map 'listings' cannot install: Compatibility::prepare() creates listings_backfill and inserts the compatibility_backfills row of listings, and install() records its passes in both; run Compatibility::prepare() first."],
            'no compatibility_backfills row' => ["delete from compatibility_backfills where legacy_type = 'listings'", "Compatibility map 'listings' cannot install: Compatibility::prepare() creates listings_backfill and inserts the compatibility_backfills row of listings, and install() records its passes in both; run Compatibility::prepare() first."],
            'no at-most-one index' => ['drop index places_listings_at_most_one', self::unfinished('places_listings_at_most_one is missing, not valid, or not the unique index prepare() builds on places')],
            'an at-most-one index not yet valid' => ["update pg_index set indisvalid = false where indexrelid = 'places_listings_at_most_one'::regclass", self::unfinished('places_listings_at_most_one is missing, not valid, or not the unique index prepare() builds on places')],
        ];
    }

    #[DataProvider('unfinishedPreparations')]
    #[Test]
    public function it_refuses_to_install_over_a_preparation_that_has_not_finished_even_with_no_inbound_foreign_key(string $unfinished, string $refusal): void
    {
        DB::statement('drop table listing_photos, listing_shares');
        DB::statement($unfinished);

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap|QueryException $exception) {
            $this->assertStringContainsString($refusal, $exception->getMessage());
        }

        $this->assertSame('r', $this->kind('listings'));
    }

    private static function unfinished(string $what): string
    {
        return "Compatibility map 'listings' cannot install: {$what}, so prepare() has not finished. Run Compatibility::prepare() until it completes, then install again.";
    }

    #[Test]
    public function it_reads_the_catalog_under_locks_that_keep_the_legacy_shape_until_install_commits(): void
    {
        config(['database.connections.compatibility_concurrent' => config('database.connections.pgsql')]);
        $concurrent = null;
        $locked = false;

        DB::listen(function (QueryExecuted $query) use (&$concurrent, &$locked): void {
            if ($query->connectionName !== 'compatibility_concurrent' && str_starts_with(
                haystack: $query->sql,
                needle: 'LOCK TABLE listings',
            )) {
                $locked = true;
            }

            if (! $locked || $concurrent !== null || $query->connectionName === 'compatibility_concurrent' || ! str_contains($query->sql, 'format_type(table_column.atttypid')) {
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
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: false),
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
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: false),
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
            $updated = [$collations(), $behaviour()];
            Compatibility::rollback(Listing::class);

            $this->assertSame(
                array_fill(0, 4, [['city' => 'default', 'street' => 'C', 'title' => 'compatibility_case_insensitive'], [[1], []]]),
                [$before, $installed, $updated, [$collations(), $behaviour()]],
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
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: false),
                        )
                        ->attribute(
                            name: 'listed_wall',
                            definition: fn (Attribute $attribute) => $attribute->readAs('"listings_retained"."listed_before"')->readOnly(because: 'The legacy table keeps the first listing time.', ignoreWrites: false),
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
        $updated = $read();
        Compatibility::rollback($model::class);

        $this->assertSame(
            [['v', [1 => '2026-01-01 10:00:00', 2 => null, 3 => null]], [1 => '2026-01-01 10:00:00', 2 => null, 3 => null], ['r', [1 => '2026-01-01 10:00:00', 2 => null, 3 => null]]],
            [$served, $updated, [$this->kind('listings'), $read()]],
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
    public function it_refuses_to_update_or_roll_back_through_an_at_most_one_index_whose_conditions_the_map_changed(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $job = ListingTables::jobOf(1);
        DB::table('places')->insert(['job_id' => $job, 'parent_id' => DB::table('places')->where('job_id', $job)->value('id'), 'street' => '0 Oak St, Unit B', 'city' => 'Seattle']);
        $before = $this->listings();
        $widened = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return ListingOfAnyPlace::compatibility($listing);
            }
        };

        $refusal = fn (string $operation) => "Compatibility map 'listings' cannot {$operation}: places_listings_at_most_one is missing, not valid, or not the unique index prepare() builds on places for the current conditions of the relation from Job to Place, so the view cannot join it plainly. Drop places_listings_at_most_one if it exists, run Compatibility::prepare(), then {$operation} again.";

        try {
            Compatibility::update($widened::class);
            $this->fail('update() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($refusal('update'), $exception->getMessage());
        }

        try {
            Compatibility::rollback($widened::class);
            $this->fail('rollback() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($refusal('rollback'), $exception->getMessage());
        }

        $this->assertSame('v', $this->kind('listings'));
        $this->assertSame($before, $this->listings());
        $this->assertSame([1, 2, 3], array_map(intval(...), array_column($before, 'id')));
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
        $this->assertSame([[1, 'Oak Manor'], [4, 'Ash Barn'], [5, 'Native job']], array_map(
            callback: fn (array $row) => [$row['id'], $row['title']],
            array: $this->listings(),
        ));
        $this->assertSame(['Ash Barn', 'Elm Cottage', 'Native job', 'Oak Manor', 'Pine Flat'], DB::table('jobs')->orderBy('name')->pluck('name')->all());
        $this->assertSame(['Elm Cottage', 'Pine Flat'], DB::table('jobs')->whereNotNull('deleted_at')->orderBy('name')->pluck('name')->all());
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
            $this->assertStringStartsWith("Compatibility map 'listings' cannot install: its catch-up pass 5 still changed the outcome of 1100 legacy rows, and install() takes the lock only once a pass changes fewer than 1000. Run php artisan compatibility:backfill '".Listing::class."' again, then install()", $exception->getMessage());
        }

        $this->assertSame([5, 'r'], [$passes, $this->kind('listings')]);
    }

    #[Test]
    public function it_retries_the_lock_after_a_legacy_writer_holds_it_past_the_lock_timeout(): void
    {
        $holding = $this->holdUntilReleased('update listings set title = title where id = 1');
        $attempts = 0;
        $released = null;
        DB::listen(function (QueryExecuted $query) use (&$attempts): void {
            if (str_starts_with($query->sql, 'SET LOCAL lock_timeout')) {
                $attempts++;
            }
        });
        Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$released): void {
            if ($released === null && $logged->level === 'warning' && str_contains(
                haystack: $logged->message,
                needle: "Compatibility map 'listings' install: attempt 1 of 4 could not take a lock, SQLSTATE 55P03",
            )) {
                $released = $this->release();
            }
        });
        $started = microtime(true);

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame(["locked\n", "committed\n", 2, 'v'], [$holding, $released, $attempts, $this->kind('listings')]);
        $this->assertGreaterThanOrEqual(Installer::LOCK_TIMEOUT / 1000, microtime(true) - $started);
        $this->assertSame('', $this->concurrentFailure());
    }

    #[Test]
    public function it_blocks_legacy_writes_only_while_it_holds_the_lock_and_serves_every_row_written_before_it(): void
    {
        $this->backfiller(Listing::class)->run();
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $writer = $this->start('$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            .' $pdo->exec("set lock_timeout = \'200ms\'"); $writes = []; echo "writing\n"; fflush(STDOUT);'
            .' $go = [STDIN]; $written = null; $failed = null; stream_select($go, $written, $failed, 30); echo "started\n"; fflush(STDOUT); $deadline = microtime(true) + 30;'
            .' for ($write = 0; microtime(true) < $deadline; $write++) {'
            .'   $started = microtime(true);'
            .'   try {'
            .'     if ($write % 2 === 0) { $id = (int) $pdo->query("insert into listings (title, company_id, updated_at) values (\'Row $write\', \''.ListingTables::COMPANY.'\', localtimestamp(0)) returning id")->fetchColumn(); }'
            .'     else { $id = (int) $pdo->query("update listings set title = \'Edit $write\', updated_at = localtimestamp(0) where id = (select max(id) from listings) returning id")->fetchColumn(); }'
            .'     $writes[] = [$started, microtime(true), $id, $write % 2 === 0 ? "Row $write" : "Edit $write"];'
            .'   } catch (PDOException $exception) { $writes[] = [$started, microtime(true), null, $exception->getCode()]; if ($exception->getCode() !== "55P03") { break; } }'
            .'   usleep(5000);'
            .' }'
            .' echo json_encode($writes);');
        $started = null;
        $locked = null;
        DB::listen(function (QueryExecuted $query) use (&$started, &$locked): void {
            if ($started === null && str_starts_with($query->sql, 'SET LOCAL lock_timeout')) {
                $started = $this->release();
                usleep(300000);
            }

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

        $this->assertSame(["writing\n", "started\n", 'v'], [$writer, $started, $this->kind('listings')]);
        $this->assertIsArray($writes);
        $this->assertIsFloat($locked);
        $written = array_filter($writes, fn (array $write) => $write[2] !== null);
        $waited = array_filter($writes, fn (array $write) => $write[3] === '55P03');
        $blocked = end($writes);
        $last = [];

        foreach ($written as [, , $id, $title]) {
            $last[$id] = $title;
        }

        ksort($last);
        $this->assertNotEmpty(array_filter($written, fn (array $write) => $write[1] < $locked));
        $this->assertSame([], array_values(array_filter(
            array: $waited,
            callback: fn (array $write) => $write[0] < $locked - 0.05,
        )));
        $this->assertNotEmpty($waited);
        $this->assertIsArray($blocked);
        $this->assertSame([null, '55000'], [$blocked[2], $blocked[3]]);
        $this->assertSame($last, array_column(DB::select('select id, title from listings where id in ('.implode(', ', array_keys($last)).') order by id'), 'title', 'id'));
        $this->assertSame(0, DB::scalar('select count(*) from listings_retained left join jobs on jobs.id = listings_retained.job_id where jobs.id is null'));
    }

    #[Test]
    public function it_catches_up_before_its_lock_a_legacy_row_whose_primary_went_missing_outside_its_watermark(): void
    {
        $this->backfiller(Listing::class)->run();
        $job = ListingTables::jobOf(2);
        DB::delete('delete from jobs where id = ?', [$job]);

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame(['v', 'Pine Flat', $job], [$this->kind('listings'), DB::table('listings')->where('id', 2)->value('title'), DB::table('listings')->where('id', 2)->value('job_id')]);
        $this->assertSame('created', DB::table('listings_backfill')->where('legacy_id', 2)->value('status'));
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
    public function it_catches_up_on_a_reinstall_the_legacy_writes_made_after_a_rollback(): void
    {
        $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Listing::class);
        Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);
        Compatibility::prepare(Listing::class);

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame(['v', 'Oak Manor'], [$this->kind('listings'), DB::table('listings')->where('id', 1)->value('title')]);
    }

    #[Test]
    public function it_catches_up_on_a_reinstall_the_legacy_edit_made_after_rolling_back_an_install_that_no_pass_preceded(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Listing::class);
        Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);
        Compatibility::prepare(Listing::class);

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame(['v', 'Oak Manor'], [$this->kind('listings'), DB::table('listings')->where(
            column: 'id',
            operator: '=',
            value: 1,
        )->value('title')]);
    }

    #[Test]
    public function it_serves_on_a_first_install_with_no_pass_a_mapped_value_written_to_the_legacy_table_after_prepare(): void
    {
        DB::table('listings')->where(
            column: 'id',
            operator: '=',
            value: 1,
        )->update(['title' => 'Oak Manor']);

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame(['v', 'Oak Manor'], [$this->kind('listings'), DB::table('listings')->where(
            column: 'id',
            operator: '=',
            value: 1,
        )->value('title')]);
    }

    #[Test]
    public function it_runs_the_full_pass_of_a_first_install_inside_a_caller_s_transaction(): void
    {
        DB::table('listings')->where(
            column: 'id',
            operator: '=',
            value: 1,
        )->update(['title' => 'Oak Manor']);

        $served = DB::transaction(function (): array {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

            return [$this->kind('listings'), DB::table('listings')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('title')];
        });

        $this->assertSame(['v', 'Oak Manor'], $served);
    }

    #[DataProvider('dependents')]
    #[Test]
    public function it_refuses_an_undeclared_dependent_before_its_catch_up_writes_a_successor_or_advances_the_pass(string $create, string $drop, string $dependent): void
    {
        $this->backfiller(Listing::class)->run();
        DB::table('listings')->where('id', 1)->update(['title' => 'Oak Manor', 'updated_at' => now()->addMinute()]);
        $job = DB::table('listings')->where('id', 1)->value('job_id');
        $before = [DB::table('compatibility_backfills')->where('legacy_type', 'listings')->value('pass'), DB::table('jobs')->where('id', $job)->value('name')];
        DB::statement($create);

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        } finally {
            DB::statement($drop);
        }

        $this->assertSame(InvalidCompatibilityMap::cannot(
            map: 'listings',
            operation: 'install',
            because: "{$dependent} depends on listings, and the map declares no dependents(); declare a ManagesDependents class that drops and recreates them inside the install transaction",
        )->getMessage(), $refusal);
        $this->assertSame($before, [DB::table('compatibility_backfills')->where('legacy_type', 'listings')->value('pass'), DB::table('jobs')->where('id', $job)->value('name')]);
        $this->assertSame([1, 'Oak House'], $before);
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
        $this->assertSame([3, true], [DB::table('jobs')->count(), DB::scalar("select deleted_at is not null from jobs where name = 'Elm Cottage'")]);
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
            array: array_slice($this->listings(), 0, 2),
        ));
        $this->assertSame(['Native job', 'Prior job'], DB::table('listings')->where('id', '>', 3)->orderBy('title')->pluck('title')->all());
        $this->assertSame(['Elm Cottage', 'Native job', 'Oak House', 'Pine Flat', 'Prior job'], DB::table('jobs')->orderBy('name')->pluck('name')->all());
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

        $this->assertSame([[1, 'Oak House'], [2, 'Pine Flat'], [4, 'Native job']], array_map(
            callback: fn (array $row) => [$row['id'], $row['title']],
            array: $this->listings(),
        ));
        $this->assertSame(['Elm Cottage', 'Native job', 'Oak House', 'Pine Flat'], DB::table('jobs')->orderBy('name')->pluck('name')->all());
        $this->assertSame([true, 0], [DB::scalar("select deleted_at is not null from jobs where name = 'Elm Cottage'"), DB::scalar("select count(*) from listings_retained join jobs on jobs.id = listings_retained.job_id where jobs.name = 'Elm Cottage'")]);
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
    public function it_reads_its_deletions_before_the_lock_and_leaves_live_for_the_check_the_job_of_a_listing_hard_deleted_after_its_last_read(): void
    {
        $this->backfiller(Listing::class)->run();
        $writer = $this->writer();
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use ($writer, &$reads): void {
            if ($query->connectionName === DB::getDefaultConnection() && str_starts_with($query->sql, 'SELECT ledger.legacy_id, ledger.successor_id, ledger.status FROM') && ++$reads === 2) {
                $writer->delete('delete from listings where id = 3');
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([2, [1, 2]], [$reads, array_column($this->listings(), 'id')]);
        $this->assertSame([3, false], [DB::table('jobs')->count(), DB::scalar("select deleted_at is not null from jobs where name = 'Elm Cottage'")]);
        $this->artisan('compatibility:check', ['--model' => [Listing::class]])
            ->expectsOutputToContain('unserved')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_rolls_back_and_retries_when_its_final_pass_waits_on_a_live_writer_of_a_root_past_its_lock_timeout(): void
    {
        $this->backfiller(Listing::class)->run();
        $this->listenToTheLog();
        $writer = $this->writer();
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use ($writer, &$reads): void {
            if ($query->connectionName === DB::getDefaultConnection() && str_starts_with($query->sql, 'SELECT ledger.legacy_id, ledger.successor_id, ledger.status FROM') && ++$reads === 2) {
                $writer->update("update listings set title = 'Oak Manor', updated_at = localtimestamp(0) where id = 1");
                $this->holdConcurrently(
                    sql: 'select id from jobs where id = (select job_id from listings where id = 1) for update',
                    seconds: 3,
                );
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(4),
        );

        $this->assertSame(['v', 'Oak Manor'], [$this->kind('listings'), DB::table('listings')->where('id', 1)->value('title')]);
        $this->assertSame([['warning', true]], array_values(array_map(
            callback: fn (array $logged) => [$logged[0], str_contains(
                haystack: $logged[1],
                needle: "Compatibility map 'listings' install: attempt 1 of 4 could not take a lock, SQLSTATE 55P03",
            )],
            array: array_filter(
                array: $this->logged,
                callback: fn (array $logged) => $logged[0] === 'warning' && str_contains(
                    haystack: $logged[1],
                    needle: ' attempt ',
                ),
            ),
        )));
    }

    #[Test]
    public function it_refuses_once_its_final_pass_spends_the_lock_budget_and_names_the_row_it_reached(): void
    {
        $this->backfiller(Listing::class)->run();
        $writer = $this->writer();
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use ($writer, &$reads): void {
            if ($query->connectionName === DB::getDefaultConnection() && str_starts_with($query->sql, 'SELECT ledger.legacy_id, ledger.successor_id, ledger.status FROM') && ++$reads === 2) {
                $writer->unprepared('create or replace function slow_job_update() returns trigger language plpgsql as $$ begin perform pg_sleep(0.6); return new; end $$; create trigger slow_job_update before update on jobs for each row execute function slow_job_update()');
                $writer->update("update listings set title = title || ' Revised', updated_at = localtimestamp(0)");
            }
        });

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(1),
            );
            $this->fail('Install held its lock past its statementTimeout.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertStringContainsString("ms statementTimeout before the step the final pass's write of listings id 3, so it rolled back.", $exception->getMessage());
        } finally {
            DB::unprepared('drop function if exists slow_job_update() cascade');
        }

        $this->assertSame('r', $this->kind('listings'));
    }

    #[Test]
    public function it_carries_more_natively_created_roots_than_postgresql_binds_parameters_in_one_statement(): void
    {
        $this->backfiller(Listing::class)->run();
        DB::statement("insert into jobs (name, company_id, created_at, updated_at) select 'Native ' || n, ?, now() - interval '1 day', now() - interval '1 day' from generate_series(1, 65536) as n", [ListingTables::COMPANY]);

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(600),
        );

        $this->assertSame(65539, DB::table('listings')->count());
    }

    #[Test]
    public function it_refuses_before_any_write_a_root_model_that_stamps_no_created_at(): void
    {
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: UnstampedJob::class, key: 'job_id', successor: fn (Successor $job) => $job
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
        Compatibility::prepare($listing::class);

        $this->expectExceptionObject(InvalidCompatibilityMap::cannot(
            map: 'listings',
            operation: 'install',
            because: 'UnstampedJob stamps no created_at column on jobs, so install() cannot find a root created natively between its read of native roots and its lock, and the view would serve no record for that root. Give UnstampedJob timestamps, then install again',
        ));

        try {
            Compatibility::install(
                model: $listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
        } finally {
            $this->assertSame([3, 'r'], [DB::table('jobs')->count(), $this->kind('listings')]);
        }
    }

    #[Test]
    public function it_carries_a_root_created_natively_inside_its_window_into_a_wall_clock_created_at_in_the_application_zone(): void
    {
        config(['app.timezone' => 'America/Chicago']);
        DB::statement('alter table jobs alter column created_at type timestamp(0) without time zone');
        $this->backfiller(Listing::class)->run();
        $writer = $this->writer();
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use ($writer, &$reads): void {
            if ($query->connectionName === DB::getDefaultConnection() && str_starts_with($query->sql, 'SELECT ledger.legacy_id, ledger.successor_id, ledger.status FROM') && ++$reads === 2) {
                $writer->insert("insert into jobs (name, company_id, created_at, updated_at) values ('Native job', ?, now() at time zone 'America/Chicago', now())", [ListingTables::COMPANY]);
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([2, true], [$reads, DB::table('listings')->where('title', 'Native job')->exists()]);
    }

    #[Test]
    public function it_warns_before_its_lock_when_no_index_leads_with_the_created_at_of_the_root_and_not_once_one_does(): void
    {
        $this->listenToTheLog();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Listing::class);
        Compatibility::prepare(Listing::class);
        DB::statement('create index jobs_created_at_index on jobs (created_at)');
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([['warning', "Compatibility map 'listings' install: no index leads with jobs.created_at, so the read of natively created roots under the lock scans jobs. Create one before install with CREATE INDEX CONCURRENTLY ON \"jobs\" (\"created_at\")"]], array_values(array_filter(
            array: $this->logged,
            callback: fn (array $logged) => $logged[0] === 'warning',
        )));
    }

    #[Test]
    public function it_catches_up_each_of_two_text_keys_that_compare_equal_as_numbers(): void
    {
        DB::statement('truncate jobs cascade');
        DB::statement('create table codes (code text primary key, title text not null, company_id uuid not null, created_at timestamp(0), updated_at timestamp(0))');
        DB::table('codes')->insert([
            ['code' => '01', 'title' => 'First code', 'company_id' => ListingTables::COMPANY, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00'],
            ['code' => '1', 'title' => 'Second code', 'company_id' => ListingTables::COMPANY, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00'],
        ]);
        $code = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $incrementing = false;

            protected $keyType = 'string';

            protected $primaryKey = 'code';

            protected $table = 'codes';

            public static function compatibility(Compatibility $code): Compatibility
            {
                return $code
                    ->retainedIn('codes_retained')
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

        try {
            Compatibility::prepare($code::class);
            $this->artisan('compatibility:backfill', ['model' => $code::class])->assertExitCode(0);
            DB::delete('delete from jobs where id in (select job_id from codes)');

            Compatibility::install(
                model: $code::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

            $this->assertSame(['01', '1'], DB::table('codes')->orderBy('code')->pluck('code')->all());
        } finally {
            DB::statement("do \$\$ begin if exists (select from pg_views where viewname = 'codes') then drop view codes; end if; end \$\$");
            DB::statement('drop table if exists codes_retained, codes, codes_backfill cascade');
            DB::delete("delete from compatibility_backfills where legacy_type = 'codes'");
        }
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
    public function it_refuses_before_any_write_while_as_many_rows_as_its_pass_size_have_a_null_updated_at(): void
    {
        DB::statement("insert into listings (title, company_id, created_at, updated_at) select 'Bulk ' || n, ?, localtimestamp(0), null from generate_series(1, 1100) as n", [ListingTables::COMPANY]);
        $this->backfiller(Listing::class)->run();
        $passes = (int) DB::scalar("select pass from compatibility_backfills where legacy_type = 'listings'");

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $this->fail('Install took its lock with 1,100 rows whose updated_at is NULL.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertSame("Compatibility map 'listings' cannot install: 1100 listings rows have a NULL updated_at, and every pass walks them again, the final pass under the lock included; install() takes the lock only while fewer than its passSize of 1000 do. Stamp updated_at on those rows, then install again.", $exception->getMessage());
        }

        $this->assertSame(['r', $passes], [$this->kind('listings'), (int) DB::scalar("select pass from compatibility_backfills where legacy_type = 'listings'")]);
    }

    #[Test]
    public function it_takes_the_lock_and_walks_the_rows_with_a_null_updated_at_while_fewer_than_its_pass_size_have_one(): void
    {
        DB::statement("insert into listings (title, company_id, created_at, updated_at) select 'Bulk ' || n, ?, localtimestamp(0), null from generate_series(1, 1100) as n", [ListingTables::COMPANY]);
        $this->backfiller(Listing::class)->run();
        $walked = [];
        DB::listen(function (QueryExecuted $query) use (&$walked): void {
            if (str_starts_with($query->sql, 'update "compatibility_backfills" set watermark_at')) {
                $walked[] = $query->bindings[1];
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
            passSize: 2000,
        );

        $this->assertSame([1100], $walked);
        $this->assertSame(['v', 1103], [$this->kind('listings'), DB::table('listings')->count()]);
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
        $this->assertSame(["SET LOCAL lock_timeout = '5000ms'"], array_map(
            callback: fn (int $index) => $installed[$index][0],
            array: $timeouts,
        ));
        $this->assertSame(['on_table' => 'listings_retained', 'definition' => 'FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE NOT VALID', 'convalidated' => false], $foreignKey);
        $this->assertSame(0, DB::scalar("select count(*) from pg_constraint where conname in ('listings_retained_job_id_foreign', 'listings_retained_job_id_not_null')"));
    }

    #[Test]
    public function it_retries_the_install_when_the_foreign_key_cannot_take_its_lock_on_the_root_within_the_lock_timeout(): void
    {
        $this->backfiller(Listing::class)->run();
        $holding = $this->holdUntilReleased("update jobs set name = name where name = 'Oak House'");
        $attempts = 0;
        $released = null;
        DB::listen(function (QueryExecuted $query) use (&$attempts, &$released): void {
            if (str_starts_with(
                haystack: $query->sql,
                needle: 'SET LOCAL lock_timeout',
            ) && ++$attempts === 2) {
                $released = $this->release();
            }
        });
        $this->listenToTheLog();

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame(["locked\n", "committed\n", 2, 'v'], [$holding, $released, $attempts, $this->kind('listings')]);
        $this->logged = array_values(array_filter(
            array: $this->logged,
            callback: fn (array $logged) => str_contains(
                haystack: $logged[1],
                needle: ' attempt ',
            ),
        ));
        $this->assertCount(2, $this->logged);
        $this->assertSame('warning', $this->logged[0][0]);
        $this->assertMatchesRegularExpression("/^Compatibility map 'listings' install: attempt 1 of 4 could not take a lock, SQLSTATE 55P03 on ALTER TABLE \"listings_retained\" ADD CONSTRAINT \"listings_retained_job_id_foreign\" FOREIGN KEY \\(\"job_id\"\\) REFERENCES \"jobs\" \\(\"id\"\\) ON DELETE CASCADE NOT VALID after \\d+ ms, so it rolled back and retries$/", $this->logged[0][1]);
        $this->assertSame(['info', "Compatibility map 'listings' install: took its locks on attempt 2 of 4"], $this->logged[1]);
        $this->assertSame('', $this->concurrentFailure());
    }

    #[Test]
    public function it_retries_a_lock_wait_on_the_root_under_a_statement_timeout_shorter_than_its_lock_timeout(): void
    {
        $this->backfiller(Listing::class)->run();
        $holding = $this->holdUntilReleased("update jobs set name = name where name = 'Oak House'");
        $timeouts = [];
        $released = null;
        DB::listen(function (QueryExecuted $query) use (&$timeouts, &$released): void {
            if (str_starts_with(
                haystack: $query->sql,
                needle: 'SET LOCAL lock_timeout',
            )) {
                $timeouts[] = $query->sql;

                if (count($timeouts) === 2) {
                    $released = $this->release();
                }
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(2),
        );

        $this->assertSame(
            [["SET LOCAL lock_timeout = '1000ms'", "SET LOCAL lock_timeout = '1000ms'"], "locked\n", "committed\n", 'v'],
            [$timeouts, $holding, $released, $this->kind('listings')],
        );
        $this->assertSame('', $this->concurrentFailure());
    }

    #[Test]
    public function it_refuses_naming_the_statement_its_statement_timeout_cancels_and_rolls_back(): void
    {
        $dependents = new class implements ManagesDependents
        {
            public function beforeInstall(Connection $connection): void
            {
                $connection->statement('select pg_sleep(2)');
            }

            public function afterInstall(Connection $connection): void {}

            public function beforeRollback(Connection $connection): void {}

            public function afterRollback(Connection $connection): void {}
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
                statementTimeout: CarbonInterval::milliseconds(500),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertMatchesRegularExpression("/^Compatibility map 'listings' cannot install: it held its lock for \\d+ ms of its 500 ms statementTimeout during the step select pg_sleep\\(2\\), so it rolled back\\./", $refusal);
        $this->assertSame(['r', null], [$this->kind('listings'), $this->kind('listings_retained')]);
    }

    #[Test]
    public function it_rolls_back_once_its_last_statement_spends_the_statement_timeout(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(
                haystack: $query->sql,
                needle: 'ALTER TABLE "listings_retained" ADD CONSTRAINT "listings_retained_job_id_foreign"',
            )) {
                usleep(1200000);
            }
        });

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(1),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertMatchesRegularExpression("/^Compatibility map 'listings' cannot install: it held its lock for \\d+ ms of its 1000 ms statementTimeout after the step ALTER TABLE \"listings_retained\" ADD CONSTRAINT \"listings_retained_job_id_foreign\" FOREIGN KEY \\(\"job_id\"\\) REFERENCES \"jobs\" \\(\"id\"\\) ON DELETE CASCADE NOT VALID, so it rolled back\\./", $refusal);
        $this->assertSame(['r', null], [$this->kind('listings'), $this->kind('listings_retained')]);
    }

    #[Test]
    public function it_rolls_back_once_the_hold_spends_its_statement_timeout_after_the_rename_naming_the_next_step(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if ($query->sql === 'ALTER TABLE "listings" RENAME TO "listings_retained"') {
                usleep(1200000);
            }
        });

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(1),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertMatchesRegularExpression("/^Compatibility map 'listings' cannot install: it held its lock for \\d+ ms of its 1000 ms statementTimeout before the step ALTER TABLE \"listings_retained\", so it rolled back\\./", $refusal);
        $this->assertSame(['r', null], [$this->kind('listings'), $this->kind('listings_retained')]);
    }

    #[Test]
    public function it_refuses_a_statement_timeout_under_one_millisecond(): void
    {
        $this->expectExceptionObject(new LogicException('Compatibility::install() takes a statementTimeout of at least one millisecond, which bounds how long it holds its lock.'));

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::milliseconds(0),
        );
    }

    #[Test]
    public function it_keeps_the_root_readable_through_its_rollback_until_its_last_statement_drops_the_foreign_key(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        config(['database.connections.compatibility_concurrent' => config('database.connections.pgsql')]);
        $read = null;
        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$read, &$statements): void {
            if ($query->connectionName === 'compatibility_concurrent') {
                return;
            }

            $statements[] = $query->sql;

            if ($read !== null || ! str_starts_with(
                haystack: $query->sql,
                needle: 'UPDATE "listings" AS "listings_retained" SET',
            )) {
                return;
            }

            try {
                $read = DB::connection('compatibility_concurrent')->transaction(function (): mixed {
                    DB::connection('compatibility_concurrent')->statement("set local lock_timeout = '200ms'");

                    return DB::connection('compatibility_concurrent')->scalar('select count(*) from jobs');
                });
            } catch (QueryException $exception) {
                $read = (string) $exception->getCode();
            }
        });

        try {
            Compatibility::rollback(Listing::class);
        } finally {
            DB::purge('compatibility_concurrent');
        }

        $drop = array_search(
            needle: "ALTER TABLE \"listings\"\n  DROP CONSTRAINT IF EXISTS \"listings_retained_job_id_foreign\",\n  DROP CONSTRAINT IF EXISTS \"listings_retained_job_id_not_null\"",
            haystack: $statements,
            strict: true,
        );

        $this->assertSame(3, $read);
        $this->assertIsInt($drop);
        $this->assertSame("SET LOCAL lock_timeout = '5000ms'", $statements[$drop - 1]);
        $this->assertStringStartsWith('DO $compatibility$', $statements[$drop - 2]);
        $this->assertSame(0, DB::scalar("select count(*) from pg_constraint where conname in ('listings_retained_job_id_foreign', 'listings_retained_job_id_not_null')"));
    }

    #[Test]
    public function it_retries_the_rollback_when_dropping_the_foreign_key_cannot_take_its_lock_on_the_root_within_the_lock_timeout(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $holding = $this->holdUntilReleased('select count(*) from jobs');
        $attempts = 0;
        $released = null;
        DB::listen(function (QueryExecuted $query) use (&$attempts, &$released): void {
            if (str_starts_with(
                haystack: $query->sql,
                needle: 'LOCK TABLE "jobs"',
            ) && ++$attempts === 2) {
                $released = $this->release();
            }
        });
        $this->listenToTheLog();

        Compatibility::rollback(Listing::class);

        $this->assertSame(["locked\n", "committed\n", 2, 'r'], [$holding, $released, $attempts, $this->kind('listings')]);
        $this->assertCount(2, $this->logged);
        $this->assertMatchesRegularExpression("/^Compatibility map 'listings' rollback: attempt 1 of 4 could not take a lock, SQLSTATE 55P03 on ALTER TABLE \"listings\" after \\d+ ms, so it rolled back and retries$/", $this->logged[0][1]);
        $this->assertSame(['info', "Compatibility map 'listings' rollback: took its locks on attempt 2 of 4"], $this->logged[1]);
        $this->assertSame('', $this->concurrentFailure());
    }

    #[Test]
    public function it_restores_the_statement_and_lock_timeouts_of_the_caller_s_transaction_after_it_installs_and_rolls_back_inside_it(): void
    {
        $timeouts = DB::transaction(function (): array {
            DB::statement("set local statement_timeout = '7s'");
            DB::statement("set local lock_timeout = '3s'");
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $installed = [DB::scalar('show statement_timeout'), DB::scalar('show lock_timeout')];
            Compatibility::rollback(Listing::class);

            return [$installed, [DB::scalar('show statement_timeout'), DB::scalar('show lock_timeout')]];
        });

        $this->assertSame([['7s', '3s'], ['7s', '3s']], $timeouts);
    }

    #[Test]
    public function it_restores_the_statement_and_lock_timeouts_of_the_caller_s_transaction_after_it_installs_an_installed_model_inside_it(): void
    {
        $timeouts = DB::transaction(function (): array {
            DB::statement("set local statement_timeout = '7s'");
            DB::statement("set local lock_timeout = '3s'");
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

            return [DB::scalar('show statement_timeout'), DB::scalar('show lock_timeout'), $this->kind('listings')];
        });

        $this->assertSame(['7s', '3s', 'v'], $timeouts);
    }

    #[Test]
    public function it_prints_a_lock_for_every_table_install_holds_when_its_last_statement_runs(): void
    {
        $printed = array_column(
            array: Statements::install(
                map: Compatibility::of(Listing::class)->resolve(),
                connection: DB::connection(),
            )->locks,
            column_key: 1,
            index_key: 0,
        );
        $tables = array_column(
            array: DB::select("select cast(oid as bigint) as oid, relname from pg_class where oid in (to_regclass('listings'), to_regclass('jobs'), to_regclass('places'), to_regclass('job_sites'))"),
            column_key: 'relname',
            index_key: 'oid',
        );
        $held = [];
        DB::listen(function (QueryExecuted $query) use (&$held, $tables): void {
            if (! str_starts_with(
                haystack: $query->sql,
                needle: 'ALTER TABLE "listings_retained" ADD CONSTRAINT "listings_retained_job_id_foreign"',
            )) {
                return;
            }

            foreach (DB::select("select cast(relation as bigint) as oid, mode from pg_locks where pid = pg_backend_pid() and locktype = 'relation' and granted and mode in ('ShareUpdateExclusiveLock', 'ShareRowExclusiveLock', 'AccessExclusiveLock')") as $lock) {
                if (array_key_exists($lock->oid, $tables)) {
                    $held[$tables[$lock->oid]][] = $lock->mode;
                }
            }
        });

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        ksort($held);
        ksort($printed);

        $this->assertSame(array_keys($held), array_keys($printed));

        foreach ($held as $table => $modes) {
            foreach ($modes as $mode) {
                $this->assertStringContainsString(['ShareUpdateExclusiveLock' => 'SHARE UPDATE EXCLUSIVE', 'ShareRowExclusiveLock' => 'SHARE ROW EXCLUSIVE', 'AccessExclusiveLock' => 'ACCESS EXCLUSIVE'][$mode], $printed[$table]);
            }
        }
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
    public function it_lists_the_declared_dependents_at_install_and_at_rollback_before_their_hooks_drop_them(): void
    {
        DB::statement('create view listing_titles as select id, title from listings');
        $dependents = new class implements ManagesDependents
        {
            public function beforeInstall(Connection $connection): void
            {
                $connection->statement('drop view listing_titles');
            }

            public function afterInstall(Connection $connection): void
            {
                $connection->statement('create view listing_titles as select id, title from listings');
            }

            public function beforeRollback(Connection $connection): void
            {
                $connection->statement('drop view listing_titles');
            }

            public function afterRollback(Connection $connection): void
            {
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
        $this->listenToTheLog();

        try {
            Compatibility::install(
                model: $model::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            Compatibility::rollback($model::class);
        } finally {
            DB::statement('drop view if exists listing_titles');
        }

        $this->assertSame([
            ['info', "Compatibility map 'listings' install: view listing_titles depends on listings"],
            ['info', "Compatibility map 'listings' rollback: view listing_titles depends on listings"],
        ], array_values(array_filter(
            array: $this->logged,
            callback: fn (array $logged) => str_contains(
                haystack: $logged[1],
                needle: ' depends on ',
            ),
        )));
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
        DB::statement('create view retained_ids as select id from listings_retained');

        try {
            Compatibility::retire(Listing::class);
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        DB::statement('drop view listing_titles, retained_ids');
        DB::statement('drop table listing_photos, listing_shares');
        $this->listenToTheLog();
        Compatibility::retire(Listing::class);
        Compatibility::retire(Listing::class);

        $this->assertSame("Compatibility map 'listings' cannot retire: view listing_titles, view retained_ids, the foreign key listing_photos_listing_id_foreign on listing_photos, the foreign key listing_shares_listing_uuid_foreign on listing_shares depend on listings or listings_retained; drop each one, then retire again.", $refusal);
        $this->assertSame([
            ['info', "Compatibility map 'listings' retire: retire() discards listings_retained: 3 rows, with the legacy-only columns uuid, mls_number, featured"],
            ['info', "Compatibility map 'listings' retire: retire() discards listings_backfill, the backfill ledger of listings"],
            ['info', "Compatibility map 'listings' retire: retire() discards the compatibility_backfills row of listings"],
        ], $this->logged);
        $this->assertSame([null, null, null, 3], [$this->kind('listings'), $this->kind('listings_retained'), $this->kind('listings_backfill'), DB::table('jobs')->count()]);
    }

    #[Test]
    public function it_retires_a_legacy_table_whose_foreign_key_references_itself(): void
    {
        DB::statement('alter table listings add column parent_listing_id integer references listings (id)');
        DB::update('update listings set parent_listing_id = 1 where id = 3');
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        DB::statement('drop table listing_photos, listing_shares');

        Compatibility::retire(Listing::class);

        $this->assertSame([null, null], [$this->kind('listings'), $this->kind('listings_retained')]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function dependents(): array
    {
        return [
            'a view' => ['create view listing_headlines as select title from listings', 'drop view listing_headlines', 'view listing_headlines'],
            'a materialized view' => ['create materialized view listing_counts as select count(*) as listings from listings', 'drop materialized view listing_counts', 'materialized view listing_counts'],
            'a rule' => ['create rule listings_kept as on delete to listings do instead nothing', 'drop rule listings_kept on listings', 'rule listings_kept on listings'],
            'a policy' => ['create policy listings_visible on listings using (true)', 'drop policy listings_visible on listings', 'policy listings_visible on listings'],
            'an SQL function' => ['create function listing_count() returns bigint language sql begin atomic select count(*) from listings; end', 'drop function listing_count()', 'function listing_count()'],
        ];
    }

    private function listenToTheLog(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $logged): void {
            $this->logged[] = [$logged->level, $logged->message];
        });
    }

    private function holdUntilReleased(string $sql): string
    {
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $process = proc_open(
            command: [PHP_BINARY, '-r', '$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
                .' $pdo->beginTransaction(); $pdo->exec('.var_export($sql, true).'); echo "locked\n"; fflush(STDOUT);'
                .' $released = [STDIN]; $written = null; $failed = null; stream_select($released, $written, $failed, 30);'
                .' $pdo->commit(); echo "committed\n"; fflush(STDOUT);'],
            descriptor_spec: [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
        );

        if ($process === false) {
            $this->fail('The concurrent holder did not start.');
        }

        $this->concurrent = [$process, $pipes];

        return (string) fgets($pipes[1]);
    }

    private function release(): string
    {
        if ($this->concurrent === null) {
            $this->fail('No concurrent holder is running.');
        }

        fwrite($this->concurrent[1][0], "release\n");
        fflush($this->concurrent[1][0]);

        return (string) fgets($this->concurrent[1][1]);
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

    private function nativeListing(): Model
    {
        return new class extends Model implements Compatible
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
                            definition: fn (Attribute $attribute) => $attribute->from('name')->writtenBy(NameJobFromTitle::class),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        ))
                    ->legacyInitializedBy(InitializeNativeListing::class);
            }
        };
    }

    private function scopedListing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->writesWithin(RecordingWriteScope::class)
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        ));
            }
        };
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
        $process = proc_open([PHP_BINARY, '-r', $concurrent], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

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
