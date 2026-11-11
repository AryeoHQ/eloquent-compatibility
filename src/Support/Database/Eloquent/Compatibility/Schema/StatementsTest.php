<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(Statements::class)]
final class StatementsTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_lists_the_successor_key_constraint_and_the_backfill_tables_prepare_adds_and_writes_no_successor(): void
    {
        $statements = Statements::prepare($this->map(), DB::connection());
        $all = implode(
            separator: "\n",
            array: $statements->all(),
        );

        $this->assertSame('prepare', $statements->operation);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "compatibility_backfills" (', $all);
        $this->assertStringContainsString("INSERT INTO \"compatibility_backfills\" (legacy_type) VALUES ('listings') ON CONFLICT (legacy_type) DO NOTHING", $all);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "listings_backfill" (', $all);
        $this->assertContains('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "listings_job_id_unique" ON "listings" ("job_id")', $statements->afterwards);
        $this->assertStringContainsString('ALTER TABLE "listings" ADD CONSTRAINT "listings_job_id_unique" UNIQUE USING INDEX "listings_job_id_unique";', $all);
        $this->assertStringNotContainsString('ALTER TABLE "jobs"', $all);
        $this->assertStringNotContainsString('ADD COLUMN', $all);
    }

    #[Test]
    public function it_adds_a_missing_successor_key_to_the_legacy_table(): void
    {
        DB::statement('alter table listings drop column job_id');

        $this->assertContains('ALTER TABLE "listings" ADD COLUMN IF NOT EXISTS "job_id" uuid', Statements::prepare(
            map: $this->map(),
            connection: DB::connection(),
        )->transaction);
    }

    #[Test]
    public function it_lists_install_as_one_transaction_that_renames_drops_checks_creates_the_view_and_adds_the_foreign_key_last(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();

        $statements = Statements::install(
            map: $this->map(),
            connection: DB::connection(),
        );
        $leading = array_map(
            callback: fn (string $statement) => strtok(
                string: $statement,
                token: "\n",
            ),
            array: $statements->transaction,
        );

        $this->assertSame([[], []], [$statements->afterwards, $statements->steps]);
        $this->assertSame('LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE', $statements->transaction[0]);
        $this->assertSame([
            'ALTER TABLE "listings" RENAME TO "listings_retained"',
            'ALTER TABLE "listings_retained"',
            'ALTER TABLE "listings_retained" ADD CONSTRAINT "listings_retained_job_id_not_null" CHECK ("job_id" IS NOT NULL) NOT VALID',
        ], array_slice(
            array: $leading,
            offset: 5,
            length: 3,
        ));
        $this->assertSame('CREATE VIEW "listings" AS', $leading[9]);
        $this->assertSame('ALTER TABLE "listings_retained" ADD CONSTRAINT "listings_retained_job_id_foreign" FOREIGN KEY ("job_id") REFERENCES "jobs" ("id") ON DELETE CASCADE NOT VALID', $statements->transaction[count($statements->transaction) - 1]);
        $this->assertStringContainsString('DELETE FROM "compatibility_backfills" WHERE legacy_type = \'listings\'', $statements->transaction[count($statements->transaction) - 2]);
        $this->assertSame([
            ['listings', "SHARE UPDATE EXCLUSIVE from install's first statement, then SHARE ROW EXCLUSIVE, then ACCESS EXCLUSIVE from the rename until commit"],
            ['jobs', "SHARE UPDATE EXCLUSIVE from install's first statement, then SHARE ROW EXCLUSIVE from the foreign key, install's last statement, until commit"],
            ['places', "SHARE UPDATE EXCLUSIVE from install's first statement until commit"],
            ['job_sites', "SHARE UPDATE EXCLUSIVE from install's first statement until commit"],
        ], $statements->locks);
        $this->assertStringNotContainsString('ALTER TABLE "listing_photos"', implode(
            separator: "\n",
            array: $statements->transaction,
        ));
    }

    #[Test]
    public function it_probes_under_the_lock_only_for_a_null_successor_key_and_inserts_native_roots_right_after_the_mapped_columns_drop(): void
    {
        Compatibility::prepare(Listing::class);
        $inserts = fn () => null;

        $statements = Statements::install(
            map: $this->map(),
            connection: DB::connection(),
            nativeRoots: $inserts,
        );

        $this->assertStringContainsString('  IF EXISTS (SELECT FROM "listings" WHERE "listings"."job_id" IS NULL) THEN', $statements->transaction[3]);
        $this->assertStringNotContainsString('NOT EXISTS (SELECT FROM "jobs"', implode("\n", $statements->transaction));
        $this->assertSame([6], array_keys($statements->steps));
        $this->assertSame([$inserts], $statements->steps[6]);
        $this->assertStringStartsWith("ALTER TABLE \"listings_retained\"\n  DROP COLUMN", $statements->transaction[6]);
    }

    #[Test]
    public function it_locks_the_primary_and_related_successors_against_writes_before_rollback_copies_them(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame('LOCK TABLE "jobs", "places", "job_sites" IN SHARE ROW EXCLUSIVE MODE', Statements::rollback(
            map: $this->map(),
            connection: DB::connection(),
        )->transaction[0]);
    }

    #[Test]
    public function it_lists_the_one_statement_that_updates_the_view(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertEquals(new Statements(
            operation: 'update',
            transaction: [(new ViewCompiler(
                columnCatalog: new ColumnCatalog(DB::connection()),
                connection: DB::connection(),
            ))->replace($this->map())],
        ), Statements::update(
            map: $this->map(),
            connection: DB::connection(),
        ));
    }

    #[Test]
    public function it_lists_the_statements_that_restore_the_legacy_table_from_the_successors(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $statements = Statements::rollback(
            map: $this->map(),
            connection: DB::connection(),
        );

        $this->assertSame([
            'LOCK TABLE "jobs", "places", "job_sites" IN SHARE ROW EXCLUSIVE MODE',
            'DROP VIEW "listings"',
            'ALTER TABLE "listings_retained" RENAME TO "listings"',
            <<<'SQL'
            ALTER TABLE "listings"
              ADD COLUMN "title" character varying(255),
              ADD COLUMN "company_id" uuid,
              ADD COLUMN "price" numeric(10,2),
              ADD COLUMN "status" character varying(255),
              ADD COLUMN "street" character varying(255),
              ADD COLUMN "city" character varying(255),
              ADD COLUMN "latitude" double precision,
              ADD COLUMN "created_at" timestamp(0) without time zone,
              ADD COLUMN "updated_at" timestamp(0) without time zone,
              ADD COLUMN "deleted_at" timestamp(0) without time zone
            SQL,
            <<<'SQL'
            UPDATE "listings" AS "listings_retained" SET
              "title" = CAST("jobs"."name" AS character varying(255)) COLLATE pg_catalog."default",
              "company_id" = CAST("jobs"."company_id" AS uuid),
              "price" = CAST((CAST("jobs"."price_cents" AS numeric) / 100) AS numeric(10,2)),
              "status" = CAST((CASE CAST("jobs"."lifecycle_status" AS text) WHEN 'drafting' THEN 'Draft' WHEN 'live' THEN 'Active' WHEN 'closed' THEN 'Sold' END) AS character varying(255)) COLLATE pg_catalog."default",
              "street" = CAST("places"."street" AS character varying(255)) COLLATE pg_catalog."default",
              "city" = CAST("places"."city" AS character varying(255)) COLLATE pg_catalog."default",
              "latitude" = CAST("job_sites"."latitude" AS double precision),
              "created_at" = CAST(("jobs"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone),
              "updated_at" = CAST(("jobs"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone),
              "deleted_at" = CAST(("jobs"."deleted_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone)
            FROM "jobs"
            LEFT JOIN (SELECT * FROM "places" WHERE ("places"."parent_id" is null) AND "places"."deleted_at" IS NULL) AS "places" ON "places"."job_id" = "jobs"."id"
            LEFT JOIN "job_sites" ON "job_sites"."job_id" = "jobs"."id"
            WHERE "jobs"."id" = "listings_retained"."job_id"
            SQL,
            <<<'SQL'
            ALTER TABLE "listings"
              ALTER COLUMN "status" SET DEFAULT 'Draft'::character varying
            SQL,
            <<<'SQL'
            ALTER TABLE "listings"
              ALTER COLUMN "title" SET NOT NULL,
              ALTER COLUMN "company_id" SET NOT NULL,
              ALTER COLUMN "status" SET NOT NULL
            SQL,
            'CREATE INDEX listings_updated_at_index ON public.listings USING btree (updated_at)',
            'COMMENT ON TABLE "listings" IS NULL',
            "SET LOCAL lock_timeout = '5000ms'",
            <<<'SQL'
            ALTER TABLE "listings"
              DROP CONSTRAINT IF EXISTS "listings_retained_job_id_foreign",
              DROP CONSTRAINT IF EXISTS "listings_retained_job_id_not_null"
            SQL,
        ], array_values(array_filter(
            array: $statements->transaction,
            callback: fn (string $statement) => ! str_contains(
                haystack: $statement,
                needle: 'compatibility.grants',
            ),
        )));
        $this->assertSame([], $statements->afterwards);
    }

    #[Test]
    public function it_lists_the_statements_that_retire_the_view_the_retained_table_and_the_ledger(): void
    {
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        DB::statement('drop table listing_photos, listing_shares');

        $statements = Statements::retire(
            map: $this->map(),
            connection: DB::connection(),
        );

        $this->assertSame(['DROP VIEW "listings"', 'DROP TABLE "listings_retained"'], array_slice(
            array: $statements->transaction,
            offset: 0,
            length: 2,
        ));
        $this->assertStringContainsString('DELETE FROM "compatibility_backfills" WHERE legacy_type = \'listings\'', $statements->transaction[2]);
        $this->assertSame('DROP TABLE IF EXISTS "listings_backfill"', $statements->transaction[3]);
        $this->assertSame([
            'retire() discards listings_retained: 3 rows, with the legacy-only columns uuid, mls_number, featured',
            'retire() discards listings_backfill, the backfill ledger of listings',
            'retire() discards the compatibility_backfills row of listings',
        ], $statements->notes);
    }

    /**
     * @return iterable<string, array{Closure(): mixed, Closure(ResolvedMap): Statements, string}>
     */
    public static function refusals(): iterable
    {
        $prepared = fn () => Compatibility::prepare(Listing::class);
        $installed = function (): void {
            Compatibility::prepare(Listing::class);
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
        };
        $install = fn (ResolvedMap $map) => Statements::install($map, DB::connection());

        yield 'install over a view the package did not create' => [
            function (): void {
                DB::statement('drop table listings cascade');
                DB::statement('create view listings as select 1 as id');
            },
            $install,
            'listings is not a plain table, so install() cannot serve it through a view; update() replaces an installed view',
        ];
        yield 'update without a view' => [$prepared, fn (ResolvedMap $map) => Statements::update(
            map: $map,
            connection: DB::connection(),
        ), 'listings is not a view, so update() has nothing to replace; install() creates it'];
        yield 'rollback without a legacy table' => [fn () => DB::statement('drop table listings cascade'), fn (ResolvedMap $map) => Statements::rollback(
            map: $map,
            connection: DB::connection(),
        ), 'listings is not a view, so rollback() has nothing to reverse'];
        yield 'a foreign key to a mapped attribute' => [
            function (): void {
                Compatibility::prepare(Listing::class);
                DB::statement('alter table listings add constraint listings_title_unique unique (title)');
                DB::statement('create table listing_titles (title varchar(255) references listings (title))');
            },
            $install,
            'the foreign key listing_titles_title_fkey on listing_titles references listings.title, which install() drops from listings_retained; reference a column the retained table keeps instead',
        ];
        yield 'a legacy table under row-level security' => [
            function (): void {
                Compatibility::prepare(Listing::class);
                DB::statement('alter table listings enable row level security');
            },
            $install,
            "listings enables row-level security, and the view would read its successors with its owner's privileges and serve rows a policy hides",
        ];
    }

    /**
     * @param  Closure(): mixed  $arrange
     * @param  Closure(ResolvedMap): Statements  $statements
     */
    #[DataProvider('refusals')]
    #[Test]
    public function it_refuses_an_operation_the_legacy_shape_cannot_take(Closure $arrange, Closure $statements, string $problem): void
    {
        $arrange();

        try {
            $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', $problem));

            $statements($this->map());
        } finally {
            DB::statement('drop table if exists listing_titles');
        }
    }

    private function map(): ResolvedMap
    {
        return Compatibility::of(Listing::class)->resolve();
    }
}
