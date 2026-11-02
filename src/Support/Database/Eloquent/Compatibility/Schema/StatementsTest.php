<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

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
    public function it_lists_the_columns_and_the_concurrent_index_prepare_adds(): void
    {
        $statements = Statements::prepare($this->map(), DB::connection());

        $this->assertSame('prepare', $statements->operation);
        $this->assertSame([
            <<<'SQL'
            DO $compatibility$
            DECLARE
              duplicated bigint;
              first_keys text;
              zone text := current_setting('TimeZone');
              west text;
              east text;
            BEGIN
              EXECUTE format('CREATE TEMPORARY TABLE %I (LIKE %I.%I)', 'places', current_schema(), 'places');
              PERFORM set_config('TimeZone', 'Etc/GMT+12', true);
              CREATE INDEX compatibility_west ON "places" ("job_id") WHERE ("places"."parent_id" is null) AND "places"."deleted_at" IS NULL;
              PERFORM set_config('TimeZone', 'Etc/GMT-14', true);
              CREATE INDEX compatibility_east ON "places" ("job_id") WHERE ("places"."parent_id" is null) AND "places"."deleted_at" IS NULL;
              PERFORM set_config('TimeZone', zone, true);
              SELECT pg_get_expr(indpred, indrelid) INTO west FROM pg_index WHERE indexrelid = 'pg_temp.compatibility_west'::regclass;
              SELECT pg_get_expr(indpred, indrelid) INTO east FROM pg_index WHERE indexrelid = 'pg_temp.compatibility_east'::regclass;
              DROP TABLE pg_temp."places";
              IF west IS DISTINCT FROM east THEN
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot prepare: atMostOne() on the relation from Job to Place has a condition whose instant follows the session time zone, and the index and the view would each read it in the time zone of their own session. Compare a timestamp with time zone with a value that names its offset.';
              END IF;
              SELECT count(*) INTO duplicated FROM (SELECT "job_id" FROM "places" WHERE ("places"."parent_id" is null) AND "places"."deleted_at" IS NULL AND "job_id" IS NOT NULL GROUP BY "job_id" HAVING count(*) > 1) AS duplicates;
              IF duplicated > 0 THEN
                SELECT string_agg(CAST(duplicate AS text), ', ' ORDER BY duplicate) INTO first_keys FROM (SELECT "job_id" FROM "places" WHERE ("places"."parent_id" is null) AND "places"."deleted_at" IS NULL AND "job_id" IS NOT NULL GROUP BY "job_id" HAVING count(*) > 1 ORDER BY "job_id" LIMIT 5) AS first_duplicates (duplicate);
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot prepare: atMostOne() on the relation from Job to Place allows one places row for each job_id, and ' || duplicated || ' values of job_id have more; the first are ' || first_keys;
              END IF;
            END
            $compatibility$
            SQL,
            <<<'SQL'
            ALTER TABLE "jobs"
              ADD COLUMN IF NOT EXISTS "legacy_id" integer,
              ADD COLUMN IF NOT EXISTS "legacy_uuid" uuid
            SQL,
            <<<'SQL'
            DO $compatibility$
            DECLARE
              found text;
            BEGIN
              SELECT pg_get_constraintdef(oid) INTO found FROM pg_constraint WHERE conrelid = to_regclass(quote_ident(current_schema()) || '."jobs"') AND conname = 'jobs_legacy_id_filled';
              IF found NOT IN ('CHECK ((' || quote_ident('legacy_id') || ' IS NOT NULL))', 'CHECK ((' || quote_ident('legacy_id') || ' IS NOT NULL))' || ' NOT VALID') THEN
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot prepare: jobs_legacy_id_filled already exists on jobs and is not the check prepare() adds. Drop or rename it, then prepare again.';
              END IF;
              SELECT string_agg(inserter, ', ' ORDER BY inserter) INTO found FROM (
                SELECT CASE WHEN granted.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(granted.grantee)) END AS inserter, granted.grantee FROM pg_class relation CROSS JOIN LATERAL aclexplode(coalesce(relation.relacl, acldefault('r', relation.relowner))) AS granted WHERE relation.oid = to_regclass(quote_ident(current_schema()) || '."jobs"') AND granted.privilege_type = 'INSERT'
                UNION SELECT CASE WHEN granted.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(granted.grantee)) END AS inserter, granted.grantee FROM pg_attribute attribute CROSS JOIN LATERAL aclexplode(attribute.attacl) AS granted WHERE attribute.attrelid = to_regclass(quote_ident(current_schema()) || '."jobs"') AND attribute.attnum > 0 AND NOT attribute.attisdropped AND granted.privilege_type = 'INSERT'
              ) AS inserters WHERE NOT has_sequence_privilege(CAST(CASE WHEN grantee = 0 THEN 'public' ELSE pg_get_userbyid(grantee) END AS name), CAST('listings_id_seq'::regclass AS oid), 'USAGE, UPDATE');
              IF found IS NOT NULL THEN
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot prepare: jobs.legacy_id draws from ' || CAST('listings_id_seq'::regclass AS text) || ', and ' || found || ' can insert into jobs without USAGE on it. Grant USAGE ON SEQUENCE ' || CAST('listings_id_seq'::regclass AS text) || ' to each of them, then prepare again.';
              END IF;
              SELECT pg_get_constraintdef(oid) INTO found FROM pg_constraint WHERE conrelid = to_regclass(quote_ident(current_schema()) || '."jobs"') AND conname = 'jobs_legacy_uuid_filled';
              IF found NOT IN ('CHECK ((' || quote_ident('legacy_uuid') || ' IS NOT NULL))', 'CHECK ((' || quote_ident('legacy_uuid') || ' IS NOT NULL))' || ' NOT VALID') THEN
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot prepare: jobs_legacy_uuid_filled already exists on jobs and is not the check prepare() adds. Drop or rename it, then prepare again.';
              END IF;
            END
            $compatibility$
            SQL,
            <<<'SQL'
            ALTER TABLE "jobs"
              ALTER COLUMN "legacy_id" SET DEFAULT nextval('listings_id_seq'::regclass),
              DROP CONSTRAINT IF EXISTS "jobs_legacy_id_filled",
              ALTER COLUMN "legacy_uuid" SET DEFAULT gen_random_uuid(),
              DROP CONSTRAINT IF EXISTS "jobs_legacy_uuid_filled"
            SQL,
        ], $statements->transaction);
        $this->assertSame([
            <<<'SQL'
            DO $compatibility$
            DECLARE
              filled_through uuid;
              fill_upto uuid;
            BEGIN
              LOOP
                SELECT chunk."id" INTO fill_upto FROM (SELECT "id" FROM "jobs" WHERE filled_through IS NULL OR "id" > filled_through ORDER BY "id" LIMIT 1000) AS chunk ORDER BY chunk."id" DESC LIMIT 1;
                EXIT WHEN fill_upto IS NULL;
                UPDATE "jobs" SET "legacy_id" = COALESCE("legacy_id", nextval('listings_id_seq'::regclass)), "legacy_uuid" = COALESCE("legacy_uuid", gen_random_uuid()) WHERE ("legacy_id" IS NULL OR "legacy_uuid" IS NULL) AND "id" <= fill_upto AND (filled_through IS NULL OR "id" > filled_through);
                filled_through := fill_upto;
                COMMIT;
              END LOOP;
            END
            $compatibility$
            SQL,
            <<<'SQL'
            ALTER TABLE "jobs"
              ADD CONSTRAINT "jobs_legacy_id_filled" CHECK ("legacy_id" IS NOT NULL) NOT VALID,
              ADD CONSTRAINT "jobs_legacy_uuid_filled" CHECK ("legacy_uuid" IS NOT NULL) NOT VALID
            SQL,
            <<<'SQL'
            ALTER TABLE "jobs"
              VALIDATE CONSTRAINT "jobs_legacy_id_filled",
              VALIDATE CONSTRAINT "jobs_legacy_uuid_filled"
            SQL,
            <<<'SQL'
            ALTER TABLE "jobs"
              ALTER COLUMN "legacy_id" SET NOT NULL,
              ALTER COLUMN "legacy_uuid" SET NOT NULL
            SQL,
            <<<'SQL'
            ALTER TABLE "jobs"
              DROP CONSTRAINT "jobs_legacy_id_filled",
              DROP CONSTRAINT "jobs_legacy_uuid_filled"
            SQL,
            <<<'SQL'
            DO $compatibility$
            DECLARE
              existing regclass := to_regclass(quote_ident(current_schema()) || '."jobs_legacy_id_unique"');
              expected text;
              found text;
            BEGIN
              IF existing IS NULL THEN
                RETURN;
              END IF;
              EXECUTE format('CREATE TEMPORARY TABLE %I (LIKE %I.%I)', 'jobs', current_schema(), 'jobs');
              CREATE UNIQUE INDEX "jobs_legacy_id_unique" ON "jobs" ("legacy_id");
              SELECT substr(pg_get_indexdef(indexrelid), strpos(pg_get_indexdef(indexrelid), ' USING ')) INTO expected FROM pg_index WHERE indrelid = 'pg_temp."jobs"'::regclass;
              SELECT substr(pg_get_indexdef(indexrelid), strpos(pg_get_indexdef(indexrelid), ' USING ')) INTO found FROM pg_index WHERE indexrelid = existing AND indisunique AND indrelid = to_regclass(quote_ident(current_schema()) || '."jobs"');
              DROP TABLE pg_temp."jobs";
              IF found IS DISTINCT FROM expected THEN
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot prepare: jobs_legacy_id_unique already exists and is not the unique index prepare() builds. Drop or rename it, then prepare again.';
              END IF;
              IF EXISTS (SELECT FROM pg_index WHERE indexrelid = existing AND NOT indisvalid) THEN
                EXECUTE format('DROP INDEX %s', existing);
              END IF;
            END
            $compatibility$
            SQL,
            'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "jobs_legacy_id_unique" ON "jobs" ("legacy_id")',
            <<<'SQL'
            DO $compatibility$
            BEGIN
              IF NOT EXISTS (SELECT FROM pg_constraint WHERE conrelid = to_regclass(quote_ident(current_schema()) || '."jobs"') AND conname = 'jobs_legacy_id_unique') THEN
                ALTER TABLE "jobs" ADD CONSTRAINT "jobs_legacy_id_unique" UNIQUE USING INDEX "jobs_legacy_id_unique";
              END IF;
            END
            $compatibility$
            SQL,
            <<<'SQL'
            DO $compatibility$
            DECLARE
              existing regclass := to_regclass(quote_ident(current_schema()) || '."jobs_legacy_uuid_unique"');
              expected text;
              found text;
            BEGIN
              IF existing IS NULL THEN
                RETURN;
              END IF;
              EXECUTE format('CREATE TEMPORARY TABLE %I (LIKE %I.%I)', 'jobs', current_schema(), 'jobs');
              CREATE UNIQUE INDEX "jobs_legacy_uuid_unique" ON "jobs" ("legacy_uuid");
              SELECT substr(pg_get_indexdef(indexrelid), strpos(pg_get_indexdef(indexrelid), ' USING ')) INTO expected FROM pg_index WHERE indrelid = 'pg_temp."jobs"'::regclass;
              SELECT substr(pg_get_indexdef(indexrelid), strpos(pg_get_indexdef(indexrelid), ' USING ')) INTO found FROM pg_index WHERE indexrelid = existing AND indisunique AND indrelid = to_regclass(quote_ident(current_schema()) || '."jobs"');
              DROP TABLE pg_temp."jobs";
              IF found IS DISTINCT FROM expected THEN
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot prepare: jobs_legacy_uuid_unique already exists and is not the unique index prepare() builds. Drop or rename it, then prepare again.';
              END IF;
              IF EXISTS (SELECT FROM pg_index WHERE indexrelid = existing AND NOT indisvalid) THEN
                EXECUTE format('DROP INDEX %s', existing);
              END IF;
            END
            $compatibility$
            SQL,
            'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "jobs_legacy_uuid_unique" ON "jobs" ("legacy_uuid")',
            <<<'SQL'
            DO $compatibility$
            BEGIN
              IF NOT EXISTS (SELECT FROM pg_constraint WHERE conrelid = to_regclass(quote_ident(current_schema()) || '."jobs"') AND conname = 'jobs_legacy_uuid_unique') THEN
                ALTER TABLE "jobs" ADD CONSTRAINT "jobs_legacy_uuid_unique" UNIQUE USING INDEX "jobs_legacy_uuid_unique";
              END IF;
            END
            $compatibility$
            SQL,
            <<<'SQL'
            DO $compatibility$
            DECLARE
              existing regclass := to_regclass(quote_ident(current_schema()) || '."places_listings_at_most_one"');
              expected text;
              found text;
            BEGIN
              IF existing IS NULL THEN
                RETURN;
              END IF;
              EXECUTE format('CREATE TEMPORARY TABLE %I (LIKE %I.%I)', 'places', current_schema(), 'places');
              CREATE UNIQUE INDEX "places_listings_at_most_one" ON "places" ("job_id") WHERE ("places"."parent_id" is null) AND "places"."deleted_at" IS NULL;
              SELECT substr(pg_get_indexdef(indexrelid), strpos(pg_get_indexdef(indexrelid), ' USING ')) INTO expected FROM pg_index WHERE indrelid = 'pg_temp."places"'::regclass;
              SELECT substr(pg_get_indexdef(indexrelid), strpos(pg_get_indexdef(indexrelid), ' USING ')) INTO found FROM pg_index WHERE indexrelid = existing AND indisunique AND indrelid = to_regclass(quote_ident(current_schema()) || '."places"');
              DROP TABLE pg_temp."places";
              IF found IS DISTINCT FROM expected THEN
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot prepare: places_listings_at_most_one already exists and is not the unique index prepare() builds. Drop or rename it, then prepare again.';
              END IF;
              IF EXISTS (SELECT FROM pg_index WHERE indexrelid = existing AND NOT indisvalid) THEN
                EXECUTE format('DROP INDEX %s', existing);
              END IF;
            END
            $compatibility$
            SQL,
            'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "places_listings_at_most_one" ON "places" ("job_id") WHERE ("places"."parent_id" is null) AND "places"."deleted_at" IS NULL',
        ], $statements->afterwards);
        $this->assertSame([...$statements->transaction, ...$statements->afterwards], $statements->all());
    }

    #[Test]
    public function it_lists_the_statements_that_install_the_view_in_place_of_the_legacy_table(): void
    {
        Compatibility::prepare(Listing::class);
        $view = (new ViewCompiler(new ColumnCatalog(DB::connection()), DB::connection()))->create($this->map());

        $statements = Statements::install($this->map(), DB::connection());

        $this->assertSame([
            'LOCK TABLE "listings", "jobs" IN SHARE ROW EXCLUSIVE MODE',
            <<<'SQL'
            DO $compatibility$
            BEGIN
              IF NOT EXISTS (
                SELECT FROM pg_attribute attribute
                JOIN pg_index table_index ON table_index.indrelid = attribute.attrelid AND table_index.indisunique AND table_index.indisvalid AND table_index.indimmediate
                  AND table_index.indpred IS NULL AND table_index.indexprs IS NULL AND table_index.indnkeyatts = 1 AND table_index.indkey[0] = attribute.attnum
                WHERE attribute.attrelid = to_regclass(quote_ident(current_schema()) || '."jobs"') AND attribute.attname = 'legacy_id' AND attribute.attnotnull
              ) THEN
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot install: jobs.legacy_id is not yet NOT NULL with a unique index of its own, so prepare() has not finished. Run Compatibility::prepare() until it completes, then install again.';
              END IF;
              IF NOT EXISTS (
                SELECT FROM pg_attribute attribute
                JOIN pg_index table_index ON table_index.indrelid = attribute.attrelid AND table_index.indisunique AND table_index.indisvalid AND table_index.indimmediate
                  AND table_index.indpred IS NULL AND table_index.indexprs IS NULL AND table_index.indnkeyatts = 1 AND table_index.indkey[0] = attribute.attnum
                WHERE attribute.attrelid = to_regclass(quote_ident(current_schema()) || '."jobs"') AND attribute.attname = 'legacy_uuid' AND attribute.attnotnull
              ) THEN
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot install: jobs.legacy_uuid is not yet NOT NULL with a unique index of its own, so prepare() has not finished. Run Compatibility::prepare() until it completes, then install again.';
              END IF;
            END
            $compatibility$
            SQL,
            <<<'SQL'
            DO $compatibility$
            DECLARE
              existing regclass := to_regclass(quote_ident(current_schema()) || '."places_listings_at_most_one"');
              expected text;
              found text;
            BEGIN
              EXECUTE format('CREATE TEMPORARY TABLE %I (LIKE %I.%I)', 'places', current_schema(), 'places');
              CREATE UNIQUE INDEX "places_listings_at_most_one" ON "places" ("job_id") WHERE ("places"."parent_id" is null) AND "places"."deleted_at" IS NULL;
              SELECT substr(pg_get_indexdef(indexrelid), strpos(pg_get_indexdef(indexrelid), ' USING ')) INTO expected FROM pg_index WHERE indrelid = 'pg_temp."places"'::regclass;
              SELECT substr(pg_get_indexdef(indexrelid), strpos(pg_get_indexdef(indexrelid), ' USING ')) INTO found FROM pg_index WHERE indexrelid = existing AND indisunique AND indisvalid AND indrelid = to_regclass(quote_ident(current_schema()) || '."places"');
              DROP TABLE pg_temp."places";
              IF found IS DISTINCT FROM expected THEN
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot install: places_listings_at_most_one is missing, not valid, or not the unique index prepare() builds on places, so prepare() has not finished. Run Compatibility::prepare() until it completes, then install again.';
              END IF;
            END
            $compatibility$
            SQL,
            <<<'SQL'
            DO $compatibility$
            DECLARE
              missing bigint;
              first_keys text;
            BEGIN
              SELECT count(*) INTO missing FROM "listings" WHERE NOT EXISTS (SELECT FROM "jobs" WHERE "jobs"."legacy_id" = "listings"."id" AND "jobs"."legacy_uuid" IS NOT DISTINCT FROM "listings"."uuid");
              IF missing > 0 THEN
                SELECT string_agg(CAST(missing_key AS text), ', ' ORDER BY missing_key) INTO first_keys FROM (SELECT "listings"."id" FROM "listings" WHERE NOT EXISTS (SELECT FROM "jobs" WHERE "jobs"."legacy_id" = "listings"."id" AND "jobs"."legacy_uuid" IS NOT DISTINCT FROM "listings"."uuid") ORDER BY "listings"."id" LIMIT 5) AS first_missing (missing_key);
                RAISE EXCEPTION '%', 'Compatibility map ''listings'' cannot install: ' || missing || ' listings rows have no jobs row with the same id and uuid; the first are id ' || first_keys || '. Backfill them before install().';
              END IF;
            END
            $compatibility$
            SQL,
            <<<'SQL'
            DO $compatibility$
            DECLARE
              hidden_rows bigint;
              first_keys text;
            BEGIN
              SELECT count(*) INTO hidden_rows FROM "listings" WHERE EXISTS (SELECT FROM "jobs" WHERE "jobs"."legacy_id" = "listings"."id" AND (COALESCE(("merged_into_job_id" is not null), false)));
              IF hidden_rows > 0 THEN
                SELECT string_agg(CAST(hidden_key AS text), ', ' ORDER BY hidden_key) INTO first_keys FROM (SELECT "listings"."id" FROM "listings" WHERE EXISTS (SELECT FROM "jobs" WHERE "jobs"."legacy_id" = "listings"."id" AND (COALESCE(("merged_into_job_id" is not null), false))) ORDER BY "listings"."id" LIMIT 5) AS first_hidden (hidden_key);
                RAISE NOTICE '%', 'Compatibility map ''listings'' install hides ' || hidden_rows || ' listings rows, because a hide() rule matches their jobs row; the first are id ' || first_keys || '.';
              END IF;
            END
            $compatibility$
            SQL,
            'ALTER TABLE "listings" ADD COLUMN "job_id" uuid',
            'UPDATE "listings" SET "job_id" = "jobs"."id" FROM "jobs" WHERE "jobs"."legacy_id" = "listings"."id"',
            <<<'SQL'
            ALTER TABLE "listing_photos"
              DROP CONSTRAINT "listing_photos_listing_id_foreign",
              ADD CONSTRAINT "listing_photos_listing_id_foreign" FOREIGN KEY ("listing_id") REFERENCES "jobs" ("legacy_id") ON DELETE CASCADE NOT VALID
            SQL,
            <<<'SQL'
            ALTER TABLE "listing_shares"
              DROP CONSTRAINT "listing_shares_listing_uuid_foreign",
              ADD CONSTRAINT "listing_shares_listing_uuid_foreign" FOREIGN KEY ("listing_uuid") REFERENCES "jobs" ("legacy_uuid") ON UPDATE CASCADE ON DELETE SET NULL NOT VALID
            SQL,
            'ALTER SEQUENCE public.listings_id_seq OWNED BY "jobs"."legacy_id"',
            <<<'SQL'
            SELECT set_config('compatibility.grants', coalesce(json_agg(grants.statement ORDER BY grants.position, grants.statement)::text, '[]'), true)
            FROM (
              SELECT 1 AS position, 'GRANT ' || string_agg(granted.privilege_type, ', ' ORDER BY granted.privilege_type) || ' ON "listings" TO ' || CASE WHEN granted.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(granted.grantee)) END || CASE WHEN granted.is_grantable OR granted.grantee = relation.relowner THEN ' WITH GRANT OPTION' ELSE '' END AS statement
              FROM pg_class relation
              CROSS JOIN LATERAL aclexplode(coalesce(relation.relacl, acldefault('r', relation.relowner))) AS granted
              WHERE relation.oid = to_regclass(quote_ident(current_schema()) || '."listings"') AND granted.grantee <> (SELECT oid FROM pg_roles WHERE rolname = current_user)
              GROUP BY granted.grantee, granted.is_grantable, relation.relowner
              UNION ALL
              SELECT 2, 'GRANT ' || string_agg(granted.privilege_type || ' (' || quote_ident(attribute.attname) || ')', ', ' ORDER BY attribute.attnum, granted.privilege_type) || ' ON "listings" TO ' || CASE WHEN granted.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(granted.grantee)) END || CASE WHEN granted.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END
              FROM pg_attribute attribute
              CROSS JOIN LATERAL aclexplode(attribute.attacl) AS granted
              WHERE attribute.attrelid = to_regclass(quote_ident(current_schema()) || '."listings"') AND attribute.attnum > 0 AND NOT attribute.attisdropped AND granted.grantee <> (SELECT oid FROM pg_roles WHERE rolname = current_user)
                AND attribute.attname IN (SELECT jsonb_array_elements_text(CAST('["id","uuid","title","company_id","price","status","street","city","latitude","mls_number","featured","created_at","updated_at","deleted_at"]' AS jsonb)))
              GROUP BY granted.grantee, granted.is_grantable
            ) AS grants
            SQL,
            <<<'SQL'
            ALTER TABLE "listings"
              DROP COLUMN "id",
              DROP COLUMN "uuid",
              DROP COLUMN "title",
              DROP COLUMN "company_id",
              DROP COLUMN "price",
              DROP COLUMN "status",
              DROP COLUMN "street",
              DROP COLUMN "city",
              DROP COLUMN "latitude",
              DROP COLUMN "created_at",
              DROP COLUMN "updated_at",
              DROP COLUMN "deleted_at"
            SQL,
            'ALTER TABLE "listings" RENAME TO "listings_retained"',
            <<<'SQL'
            ALTER TABLE "listings_retained"
              ADD CONSTRAINT "listings_retained_pkey" PRIMARY KEY ("job_id"),
              ADD CONSTRAINT "listings_retained_job_id_foreign" FOREIGN KEY ("job_id") REFERENCES "jobs" ("id") ON DELETE CASCADE NOT VALID
            SQL,
            'COMMENT ON TABLE "listings_retained" IS E\'{"captured":"compatibility install","comment":null,"notNull":["id","uuid","title","company_id","status"],"generated":[],"constraints":[],"indexes":[],"moved":[["\\\\"listing_photos\\\\"","listing_photos_listing_id_foreign"],["\\\\"listing_shares\\\\"","listing_shares_listing_uuid_foreign"]],"notValid":[]}\'',
            $view,
            'ALTER VIEW "listings" ALTER COLUMN "id" SET DEFAULT nextval(\'listings_id_seq\'::regclass)',
            'ALTER VIEW "listings" ALTER COLUMN "uuid" SET DEFAULT gen_random_uuid()',
            'ALTER VIEW "listings" ALTER COLUMN "status" SET DEFAULT \'Draft\'::character varying',
            'ALTER VIEW "listings" ALTER COLUMN "featured" SET DEFAULT false',
            <<<'SQL'
            DO $compatibility$
            DECLARE
              granted text;
            BEGIN
              FOR granted IN SELECT json_array_elements_text(CAST(current_setting('compatibility.grants') AS json)) LOOP
                EXECUTE granted;
              END LOOP;
            END
            $compatibility$
            SQL,
        ], $statements->transaction);
        $this->assertSame([
            'ALTER TABLE "listing_photos" VALIDATE CONSTRAINT "listing_photos_listing_id_foreign"',
            'ALTER TABLE "listing_shares" VALIDATE CONSTRAINT "listing_shares_listing_uuid_foreign"',
            'ALTER TABLE "listings_retained" VALIDATE CONSTRAINT "listings_retained_job_id_foreign"',
        ], $statements->afterwards);
    }

    #[Test]
    public function it_matches_the_legacy_key_by_equality_in_the_install_parity_check(): void
    {
        Compatibility::prepare(Listing::class);

        $parity = Statements::install($this->map(), DB::connection())->transaction[3];

        $this->assertStringContainsString('WHERE NOT EXISTS (SELECT FROM "jobs" WHERE "jobs"."legacy_id" = "listings"."id" AND "jobs"."legacy_uuid" IS NOT DISTINCT FROM "listings"."uuid")', $parity);
    }

    #[Test]
    public function it_locks_the_legacy_table_first_and_repoints_inbound_keys_only_after_it_fills_the_retained_key(): void
    {
        Compatibility::prepare(Listing::class);

        $transaction = Statements::install($this->map(), DB::connection())->transaction;
        $fill = array_search('UPDATE "listings" SET "job_id" = "jobs"."id" FROM "jobs" WHERE "jobs"."legacy_id" = "listings"."id"', $transaction, true);
        $repoints = array_keys(array_filter($transaction, fn (string $statement) => str_contains($statement, 'DROP CONSTRAINT "listing_')));

        $this->assertSame('LOCK TABLE "listings", "jobs" IN SHARE ROW EXCLUSIVE MODE', $transaction[0]);
        $this->assertIsInt($fill);
        $this->assertCount(2, $repoints);
        $this->assertGreaterThan($fill, min($repoints));
    }

    #[Test]
    public function it_locks_the_primary_and_related_successors_against_writes_before_uninstall_copies_them(): void
    {
        Compatibility::prepare(Listing::class);
        Compatibility::install(Listing::class);

        $this->assertSame('LOCK TABLE "jobs", "places", "job_sites" IN SHARE ROW EXCLUSIVE MODE', Statements::uninstall($this->map(), DB::connection())->transaction[0]);
    }

    #[Test]
    public function it_lists_the_one_statement_that_recompiles_the_view(): void
    {
        Compatibility::prepare(Listing::class);
        Compatibility::install(Listing::class);

        $this->assertEquals(new Statements('recompile', [(new ViewCompiler(new ColumnCatalog(DB::connection()), DB::connection()))->replace($this->map())]), Statements::recompile($this->map(), DB::connection()));
    }

    #[Test]
    public function it_lists_the_statements_that_restore_the_legacy_table_from_the_successors(): void
    {
        Compatibility::prepare(Listing::class);
        Compatibility::install(Listing::class);

        $statements = Statements::uninstall($this->map(), DB::connection());

        $this->assertSame([
            'LOCK TABLE "jobs", "places", "job_sites" IN SHARE ROW EXCLUSIVE MODE',
            'DROP VIEW "listings"',
            'ALTER TABLE "listings_retained" RENAME TO "listings"',
            'INSERT INTO "listings" ("job_id") SELECT "jobs"."id" FROM "jobs" WHERE NOT EXISTS (SELECT FROM "listings" WHERE "listings"."job_id" = "jobs"."id")',
            <<<'SQL'
            ALTER TABLE "listings"
              ADD COLUMN "id" integer,
              ADD COLUMN "uuid" uuid,
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
            UPDATE "listings" SET
              "id" = CAST("jobs"."legacy_id" AS integer),
              "uuid" = CAST("jobs"."legacy_uuid" AS uuid),
              "title" = CAST("jobs"."name" AS character varying(255)),
              "company_id" = CAST("jobs"."company_id" AS uuid),
              "price" = CAST((CAST("jobs"."price_cents" AS numeric) / 100) AS numeric(10,2)),
              "status" = CAST((CASE CAST("jobs"."lifecycle_status" AS text) WHEN 'drafting' THEN 'Draft' WHEN 'live' THEN 'Active' WHEN 'closed' THEN 'Sold' END) AS character varying(255)),
              "street" = CAST("places"."street" AS character varying(255)),
              "city" = CAST("places"."city" AS character varying(255)),
              "latitude" = CAST("job_sites"."latitude" AS double precision),
              "created_at" = CAST(("jobs"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone),
              "updated_at" = CAST(("jobs"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone),
              "deleted_at" = CAST(("jobs"."deleted_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone)
            FROM "jobs"
            LEFT JOIN LATERAL (SELECT * FROM "places" WHERE "places"."job_id" = "jobs"."id" AND ("places"."parent_id" is null) AND "places"."deleted_at" IS NULL ORDER BY "id" asc LIMIT 1) AS "places" ON true
            LEFT JOIN LATERAL (SELECT * FROM "job_sites" WHERE "job_sites"."job_id" = "jobs"."id" LIMIT 1) AS "job_sites" ON true
            WHERE "jobs"."id" = "listings"."job_id"
            SQL,
            <<<'SQL'
            ALTER TABLE "listings"
              ALTER COLUMN "id" SET DEFAULT nextval('listings_id_seq'::regclass),
              ALTER COLUMN "uuid" SET DEFAULT gen_random_uuid(),
              ALTER COLUMN "status" SET DEFAULT 'Draft'::character varying
            SQL,
            'ALTER SEQUENCE public.listings_id_seq OWNED BY "listings"."id"',
            'ALTER TABLE "listings" DROP COLUMN "job_id"',
            <<<'SQL'
            ALTER TABLE "listings"
              ADD CONSTRAINT "listings_pkey" PRIMARY KEY ("id"),
              ADD CONSTRAINT "listings_uuid_unique" UNIQUE ("uuid")
            SQL,
            <<<'SQL'
            ALTER TABLE "listings"
              ALTER COLUMN "id" SET NOT NULL,
              ALTER COLUMN "uuid" SET NOT NULL,
              ALTER COLUMN "title" SET NOT NULL,
              ALTER COLUMN "company_id" SET NOT NULL,
              ALTER COLUMN "status" SET NOT NULL
            SQL,
            'COMMENT ON TABLE "listings" IS NULL',
            <<<'SQL'
            ALTER TABLE "listing_photos"
              DROP CONSTRAINT "listing_photos_listing_id_foreign",
              ADD CONSTRAINT "listing_photos_listing_id_foreign" FOREIGN KEY ("listing_id") REFERENCES "listings" ("id") ON DELETE CASCADE NOT VALID
            SQL,
            <<<'SQL'
            ALTER TABLE "listing_shares"
              DROP CONSTRAINT "listing_shares_listing_uuid_foreign",
              ADD CONSTRAINT "listing_shares_listing_uuid_foreign" FOREIGN KEY ("listing_uuid") REFERENCES "listings" ("uuid") ON UPDATE CASCADE ON DELETE SET NULL NOT VALID
            SQL,
        ], $statements->transaction);
        $this->assertSame([
            'ALTER TABLE "listing_photos" VALIDATE CONSTRAINT "listing_photos_listing_id_foreign"',
            'ALTER TABLE "listing_shares" VALIDATE CONSTRAINT "listing_shares_listing_uuid_foreign"',
        ], $statements->afterwards);
    }

    /**
     * @return iterable<string, array{Closure(): mixed, Closure(ResolvedMap): Statements, string}>
     */
    public static function refusals(): iterable
    {
        $prepared = fn () => Compatibility::prepare(Listing::class);
        $installed = function (): void {
            Compatibility::prepare(Listing::class);
            Compatibility::install(Listing::class);
        };
        $install = fn (ResolvedMap $map) => Statements::install($map, DB::connection());

        yield 'install over a view' => [$installed, $install, 'listings is not a plain table, so install() cannot serve it through a view; recompile() replaces an installed view'];
        yield 'recompile without a view' => [$prepared, fn (ResolvedMap $map) => Statements::recompile($map, DB::connection()), 'listings is not a view, so recompile() has nothing to replace; install() creates it'];
        yield 'uninstall without a view' => [$prepared, fn (ResolvedMap $map) => Statements::uninstall($map, DB::connection()), 'listings is not a view, so uninstall() has nothing to reverse'];
        yield 'a retained key the legacy table has' => [
            function (): void {
                Compatibility::prepare(Listing::class);
                DB::statement('alter table listings add column job_id uuid');
            },
            $install,
            'listings already has a column job_id, which install() adds as the key of the retained table',
        ];
        yield 'a foreign key to a mapped attribute' => [
            function (): void {
                Compatibility::prepare(Listing::class);
                DB::statement('alter table listings add constraint listings_title_unique unique (title)');
                DB::statement('create table listing_titles (title varchar(255) references listings (title))');
            },
            $install,
            'the foreign key listing_titles_title_fkey on listing_titles references listings.title, which install() moves to a successor; reference an identifier instead',
        ];
        yield 'a legacy table under row-level security' => [
            function (): void {
                Compatibility::prepare(Listing::class);
                DB::statement('alter table listings enable row level security');
            },
            $install,
            "listings enables row-level security, and the view would read its successors with its owner's privileges and serve rows a policy hides",
        ];
        yield 'a foreign key over two columns' => [
            function (): void {
                Compatibility::prepare(Listing::class);
                DB::statement('alter table listings add constraint listings_id_uuid_unique unique (id, uuid)');
                DB::statement('create table listing_pairs (listing_id integer, listing_uuid uuid, foreign key (listing_id, listing_uuid) references listings (id, uuid))');
            },
            $install,
            'the foreign key listing_pairs_listing_id_listing_uuid_fkey on listing_pairs spans 2 columns, and the package repoints a foreign key on one identifier',
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
            DB::statement('drop table if exists listing_titles, listing_pairs');
        }
    }

    private function map(): ResolvedMap
    {
        return Compatibility::of(Listing::class)->resolve();
    }
}
