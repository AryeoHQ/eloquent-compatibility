<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Carbon\CarbonInterval;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\Fixtures\Support\PlaceAssets\Video as PlaceVideo;
use Tests\TestCase;

#[CoversClass(ViewCompiler::class)]
final class ViewCompilerTest extends TestCase
{
    private const string LISTING_SELECT = <<<'SQL'
        SELECT
          CAST("listings_retained"."id" AS integer) AS "id",
          CAST("listings_retained"."uuid" AS uuid) AS "uuid",
          CAST("listings_retained"."job_id" AS uuid) AS "job_id",
          CAST("jobs"."name" AS character varying(255)) COLLATE pg_catalog."default" AS "title",
          CAST("jobs"."company_id" AS uuid) AS "company_id",
          CAST((CAST("jobs"."price_cents" AS numeric) / 100) AS numeric(10,2)) AS "price",
          CAST((CASE CAST("jobs"."lifecycle_status" AS text) WHEN 'drafting' THEN 'Draft' WHEN 'live' THEN 'Active' WHEN 'closed' THEN 'Sold' END) AS character varying(255)) COLLATE pg_catalog."default" AS "status",
          CAST("places"."street" AS character varying(255)) COLLATE pg_catalog."default" AS "street",
          CAST("places"."city" AS character varying(255)) COLLATE pg_catalog."default" AS "city",
          CAST("job_sites"."latitude" AS double precision) AS "latitude",
          CAST("listings_retained"."mls_number" AS character varying(255)) AS "mls_number",
          CAST("listings_retained"."featured" AS boolean) AS "featured",
          CAST(("jobs"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "created_at",
          CAST(("jobs"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "updated_at",
          CAST(("jobs"."deleted_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "deleted_at"
        FROM "listings_retained"
        JOIN (SELECT * FROM "jobs" WHERE NOT COALESCE(("merged_into_job_id" is not null), false)) AS "jobs" ON "jobs"."id" = "listings_retained"."job_id"
        LEFT JOIN LATERAL (SELECT * FROM "places" WHERE "places"."job_id" = "jobs"."id" AND ("places"."parent_id" is null) AND "places"."deleted_at" IS NULL ORDER BY "id" asc LIMIT 1) AS "places" ON true
        LEFT JOIN LATERAL (SELECT * FROM "job_sites" WHERE "job_sites"."job_id" = "jobs"."id" LIMIT 1) AS "job_sites" ON true
        SQL;

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
        FileTables::drop();
        PlaceAssetTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_compiles_one_view_under_the_legacy_name_from_the_successors_and_the_retained_table(): void
    {
        Compatibility::prepare(Listing::class);

        $this->assertSame("CREATE VIEW \"listings\" AS\n".self::LISTING_SELECT, $this->viewCompiler()->create(Compatibility::of(Listing::class)->resolve()));
    }

    #[Test]
    public function it_replaces_an_installed_view_with_the_same_query(): void
    {
        Compatibility::prepare(Listing::class);
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame("CREATE OR REPLACE VIEW \"listings\" AS\n".self::LISTING_SELECT, $this->viewCompiler()->replace(Compatibility::of(Listing::class)->resolve()));
    }

    #[Test]
    public function it_refuses_to_replace_the_view_with_a_map_that_no_longer_serves_one_of_its_columns(): void
    {
        Compatibility::prepare(Listing::class);
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $map = $this->listings(fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
            name: 'title',
            definition: fn (Attribute $attribute) => $attribute->from('name'),
        )));

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'listings_retained has no column company_id, which listings keeps as a legacy-only column'));

        $this->viewCompiler()->replace($map);
    }

    #[Test]
    public function it_writes_the_bindings_of_a_relation_and_a_hide_rule_into_the_view_as_literals(): void
    {
        Compatibility::prepare(Listing::class);
        $map = $this->listings(fn (Compatibility $listing) => $listing
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->attribute(
                    name: 'title',
                    definition: fn (Attribute $attribute) => $attribute->from('name'),
                )
                ->attribute(
                    name: 'company_id',
                    definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                )
                ->related(
                    relation: fn (Job $job) => $job->hasOne(Place::class)->where('city', "Coeur d'Alene")->whereIn('street', ['1 Oak St', '2 Oak St']),
                    definition: fn (Successor $place) => $place
                        ->attribute('street', fn (Attribute $attribute) => $attribute->from('street')),
                ))
            ->hide(fn (Builder $jobs) => $jobs->where('price_cents', '>', 1000000)->orWhere('name', 'like', '%?%'), because: 'Too dear.'));

        $view = $this->viewCompiler()->create($map);

        $this->assertStringContainsString(<<<'SQL'
            JOIN (SELECT * FROM "jobs" WHERE NOT COALESCE(("price_cents" > '1000000' or "name"::text like '%?%'), false)) AS "jobs" ON "jobs"."id" = "listings_retained"."job_id"
            SQL, $view);
        $this->assertStringContainsString(<<<'SQL'
            LEFT JOIN LATERAL (SELECT * FROM "places" WHERE "places"."job_id" = "jobs"."id" AND ("city" = 'Coeur d''Alene' and "street" in ('1 Oak St', '2 Oak St')) AND "places"."deleted_at" IS NULL LIMIT 1) AS "places" ON true
            SQL, $view);
    }

    #[Test]
    public function it_joins_each_retained_row_to_its_root_through_the_successor_key_with_no_membership_filter(): void
    {
        FileTables::create();
        Compatibility::prepare(Photo::class);

        $this->assertSame(<<<'SQL'
            CREATE VIEW "photos" AS
            SELECT
              CAST("photos_retained"."id" AS integer) AS "id",
              CAST("photos_retained"."uuid" AS uuid) AS "uuid",
              CAST("files"."filename" AS character varying(255)) COLLATE pg_catalog."default" AS "file_name",
              CAST("photos_retained"."caption" AS character varying(255)) AS "caption",
              CAST("photos_retained"."views" AS integer) AS "views",
              CAST((CAST("files"."size_bytes" AS numeric) / 1000) AS numeric(12,3)) AS "size_kb",
              CAST(("files"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "created_at",
              CAST(("files"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "updated_at",
              CAST("photos_retained"."file_id" AS uuid) AS "file_id"
            FROM "photos_retained"
            JOIN "files" ON "files"."id" = "photos_retained"."file_id"
            SQL, $this->viewCompiler()->create(Compatibility::of(Photo::class)->resolve()));
    }

    #[Test]
    public function it_serves_identity_and_legacy_only_columns_from_the_retained_row_of_a_map_over_one_root(): void
    {
        FileTables::create();
        Compatibility::prepare(Video::class);

        $this->assertSame(<<<'SQL'
            CREATE VIEW "videos" AS
            SELECT
              CAST("videos_retained"."id" AS integer) AS "id",
              CAST("videos_retained"."uuid" AS uuid) AS "uuid",
              CAST("files"."filename" AS character varying(255)) COLLATE pg_catalog."default" AS "original_file_name",
              CAST((CAST("files"."size_bytes" AS numeric) / 1000) AS numeric(12,3)) AS "size_kb",
              CAST("files"."embed_url" AS character varying(255)) COLLATE pg_catalog."default" AS "youtube_embed_url",
              CAST("videos_retained"."title" AS character varying(255)) AS "title",
              CAST("videos_retained"."views" AS integer) AS "views",
              CAST(("files"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "created_at",
              CAST(("files"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "updated_at",
              CAST("videos_retained"."file_id" AS uuid) AS "file_id"
            FROM "videos_retained"
            JOIN "files" ON "files"."id" = "videos_retained"."file_id"
            SQL, $this->viewCompiler()->create(Compatibility::of(Video::class)->resolve()));
    }

    #[Test]
    public function it_joins_each_morph_target_on_its_alias_and_reads_each_target_attribute_through_the_morph_type(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::prepare();

        try {
            $view = $this->viewCompiler()->create(Compatibility::of(PlaceVideo::class)->resolve());
        } finally {
            PlaceAssetTables::drop();
        }

        $this->assertSame(<<<'SQL'
            CREATE VIEW "place_videos" AS
            SELECT
              CAST("place_videos_retained"."id" AS integer) AS "id",
              CAST("place_videos_retained"."uuid" AS uuid) AS "uuid",
              CAST("place_assets"."title" AS character varying(255)) COLLATE pg_catalog."default" AS "title",
              CAST(CASE "place_assets"."asset_type" WHEN 'place_asset_file' THEN CAST("asset_files"."filename" AS character varying(255)) COLLATE pg_catalog."default" WHEN 'place_asset_link' THEN CAST(NULL AS character varying(255)) COLLATE pg_catalog."default" END AS character varying(255)) COLLATE pg_catalog."default" AS "original_file_name",
              CAST(CASE "place_assets"."asset_type" WHEN 'place_asset_file' THEN CAST((CAST("asset_files"."size_bytes" AS numeric) / 1000) AS numeric(12,3)) WHEN 'place_asset_link' THEN CAST(NULL AS numeric(12,3)) END AS numeric(12,3)) AS "size_kb",
              CAST(CASE "place_assets"."asset_type" WHEN 'place_asset_file' THEN CAST(NULL AS character varying(255)) COLLATE pg_catalog."default" WHEN 'place_asset_link' THEN CAST("asset_links"."url" AS character varying(255)) COLLATE pg_catalog."default" END AS character varying(255)) COLLATE pg_catalog."default" AS "youtube_embed_url",
              CAST("place_videos_retained"."views" AS integer) AS "views",
              CAST(("place_assets"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "created_at",
              CAST(("place_assets"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "updated_at",
              CAST("place_videos_retained"."place_asset_id" AS uuid) AS "place_asset_id"
            FROM "place_videos_retained"
            JOIN "place_assets" ON "place_assets"."id" = "place_videos_retained"."place_asset_id"
            LEFT JOIN "asset_files" ON "place_assets"."asset_type" = 'place_asset_file' AND "place_assets"."asset_id" = "asset_files"."id"
            LEFT JOIN "asset_links" ON "place_assets"."asset_type" = 'place_asset_link' AND "place_assets"."asset_id" = "asset_links"."id"
            SQL, $view);
    }

    #[Test]
    public function it_refuses_a_map_before_prepare_adds_its_successor_key(): void
    {
        FileTables::create();

        $this->expectExceptionObject(InvalidCompatibilityMap::because(
            map: 'photos',
            problem: 'photos has no column file_id, the successor key servedFrom() names; Compatibility::prepare() adds it before install()',
        ));

        $this->viewCompiler()->create(Compatibility::of(Photo::class)->resolve());
    }

    #[Test]
    public function it_leaves_an_external_attribute_out_of_the_view(): void
    {
        Compatibility::prepare(Listing::class);

        $view = $this->viewCompiler()->create($this->listings(fn (Compatibility $listing) => $listing
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->attribute(
                    name: 'title',
                    definition: fn (Attribute $attribute) => $attribute->external(read: fn (array $jobs) => $jobs, write: fn (Job $job, mixed $title) => $title),
                )
                ->attribute(
                    name: 'company_id',
                    definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                ))));

        $this->assertStringNotContainsString('"title"', $view);
        $this->assertStringContainsString('CAST("listings_retained"."featured" AS boolean) AS "featured"', $view);
    }

    #[Test]
    public function it_reads_a_timestamp_with_time_zone_in_a_named_time_zone(): void
    {
        Compatibility::prepare(Listing::class);

        $view = $this->viewCompiler()->create($this->listings(fn (Compatibility $listing) => $listing
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
                    name: 'created_at',
                    definition: fn (Attribute $attribute) => $attribute->from('created_at')->inTimeZone('America/Los_Angeles'),
                ))));

        $this->assertStringContainsString('CAST(("jobs"."created_at" AT TIME ZONE \'America/Los_Angeles\') AS timestamp(0) without time zone) AS "created_at"', $view);
    }

    #[Test]
    public function it_reads_a_timestamp_with_time_zone_domain_in_a_named_time_zone(): void
    {
        Compatibility::prepare(Listing::class);
        DB::statement('drop domain if exists compatibility_moment cascade');
        DB::statement('create domain compatibility_moment as timestamptz');
        DB::statement('alter table jobs add column published_at compatibility_moment');

        $view = $this->viewCompiler()->create($this->listings(fn (Compatibility $listing) => $listing
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
                    name: 'created_at',
                    definition: fn (Attribute $attribute) => $attribute->from('published_at')->inTimeZone('America/Los_Angeles'),
                ))));

        $this->assertStringContainsString('CAST(("jobs"."published_at" AT TIME ZONE \'America/Los_Angeles\') AS timestamp(0) without time zone) AS "created_at"', $view);
    }

    #[Test]
    public function it_writes_a_date_binding_of_a_relation_and_a_hide_rule_as_the_connection_formats_it(): void
    {
        Compatibility::prepare(Listing::class);
        $map = $this->listings(fn (Compatibility $listing) => $listing
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->attribute(
                    name: 'title',
                    definition: fn (Attribute $attribute) => $attribute->from('name'),
                )
                ->attribute(
                    name: 'company_id',
                    definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                )
                ->related(
                    relation: fn (Job $job) => $job->hasOne(Place::class)->where('created_at', '>=', new DateTimeImmutable('2026-01-01 08:30:00', new DateTimeZone('Asia/Tokyo'))),
                    definition: fn (Successor $place) => $place
                        ->attribute('street', fn (Attribute $attribute) => $attribute->from('street')),
                ))
            ->hide(fn (Builder $jobs) => $jobs->where('created_at', '<', new DateTimeImmutable('2020-01-01')), because: 'Too old.'));

        $view = $this->viewCompiler()->create($map);

        $this->assertStringContainsString('JOIN (SELECT * FROM "jobs" WHERE NOT COALESCE(("created_at" < \'2020-01-01 00:00:00\'), false)) AS "jobs" ON "jobs"."id" = "listings_retained"."job_id"', $view);
        $this->assertStringContainsString('AND ("created_at" >= \'2026-01-01 08:30:00\') AND "places"."deleted_at" IS NULL', $view);
    }

    /**
     * @return iterable<string, array{Closure(): mixed, Closure(Compatibility): Compatibility, string}>
     */
    public static function invalidMaps(): iterable
    {
        $prepare = fn () => Compatibility::prepare(Listing::class);
        $title = fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
            name: 'title',
            definition: fn (Attribute $attribute) => $attribute->from('name'),
        ));

        yield 'no retained table' => [$prepare, fn (Compatibility $listing) => (new Compatibility(Listing::class))->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job), 'it declares no retainedIn(), and install() keeps the legacy table as the retained table'];
        yield 'no successor key' => [fn () => null, fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'bridge_id', successor: fn (Successor $job) => $job->attribute(
            name: 'title',
            definition: fn (Attribute $attribute) => $attribute->from('name'),
        )), 'listings has no column bridge_id, the successor key servedFrom() names; Compatibility::prepare() adds it before install()'];
        yield 'no legacy table' => [fn () => DB::statement('drop table listings cascade'), $title, 'the legacy table listings does not exist'];
        yield 'no successor table' => [function () use ($prepare): void {
            $prepare();
            DB::statement('drop table job_sites');
        }, fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->related(
            relation: fn (Job $job) => $job->site(),
            definition: fn (Successor $site) => $site,
        )), 'the successor table job_sites does not exist'];
        yield 'no successor column' => [$prepare, fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
            name: 'title',
            definition: fn (Attribute $attribute) => $attribute->from('headline'),
        )), "jobs has no column headline, and attribute 'title' reads it"];
        yield 'no legacy column' => [$prepare, fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
            name: 'headline',
            definition: fn (Attribute $attribute) => $attribute->from('name'),
        )), 'listings has no column headline, so the view cannot serve it'];
        yield 'a wall clock in a time zone' => [
            function () use ($prepare): void {
                $prepare();
                DB::statement('alter table jobs add column published_on timestamp');
            },
            fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
                name: 'created_at',
                definition: fn (Attribute $attribute) => $attribute->from('published_on')->inTimeZone('America/Los_Angeles'),
            )),
            "attribute 'created_at' reads jobs.published_on in the time zone America/Los_Angeles, and AT TIME ZONE reads a wall clock only from a timestamp with time zone, not from timestamp without time zone",
        ];
        yield 'a converted value in a time zone' => [
            $prepare,
            fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
                name: 'created_at',
                definition: fn (Attribute $attribute) => $attribute->from('created_at')->orDefault('2026-01-01')->inTimeZone('America/Los_Angeles'),
            )),
            "attribute 'created_at' reads jobs.created_at in the time zone America/Los_Angeles, and AT TIME ZONE reads a wall clock only from a timestamp with time zone, not from the type the conversions before inTimeZone() give",
        ];
    }

    #[Test]
    public function it_serves_a_legacy_only_column_from_the_retained_row_whatever_its_default_or_generation(): void
    {
        Compatibility::prepare(Listing::class);
        DB::statement("alter table listings add column source text not null default 'import'");
        DB::statement('alter table listings alter column source drop default');
        DB::statement('alter table listings add column listed_at timestamp default now()');
        DB::statement("alter table listings add column label text generated always as ('new'::text) stored not null");
        DB::statement('alter table listings add column ticket integer generated by default as identity');

        $view = $this->viewCompiler()->create(Compatibility::of(Listing::class)->resolve());

        foreach (['CAST("listings_retained"."source" AS text) AS "source"', 'CAST("listings_retained"."listed_at" AS timestamp without time zone) AS "listed_at"', 'CAST("listings_retained"."label" AS text) AS "label"', 'CAST("listings_retained"."ticket" AS integer) AS "ticket"'] as $read) {
            $this->assertStringContainsString($read, $view);
        }
    }

    /**
     * @param  Closure(): mixed  $arrange
     * @param  Closure(Compatibility): Compatibility  $declare
     */
    #[DataProvider('invalidMaps')]
    #[Test]
    public function it_refuses_a_map_the_catalog_cannot_serve(Closure $arrange, Closure $declare, string $problem): void
    {
        $arrange();
        $map = $this->listings($declare);

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', $problem));

        $this->viewCompiler()->create($map);
    }

    /**
     * @param  Closure(Compatibility): Compatibility  $declare
     */
    private function listings(Closure $declare): ResolvedMap
    {
        return $declare((new Compatibility(Listing::class))
            ->retainedIn('listings_retained'))
            ->resolve();
    }

    private function viewCompiler(): ViewCompiler
    {
        return new ViewCompiler(new ColumnCatalog(DB::connection()), DB::connection());
    }
}
