<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

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
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
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
          CAST("jobs"."legacy_id" AS integer) AS "id",
          CAST("jobs"."legacy_uuid" AS uuid) AS "uuid",
          CAST("jobs"."name" AS character varying(255)) AS "title",
          CAST("jobs"."company_id" AS uuid) AS "company_id",
          CAST((CAST("jobs"."price_cents" AS numeric) / 100) AS numeric(10,2)) AS "price",
          CAST((CASE CAST("jobs"."lifecycle_status" AS text) WHEN 'drafting' THEN 'Draft' WHEN 'live' THEN 'Active' WHEN 'closed' THEN 'Sold' END) AS character varying(255)) AS "status",
          CAST("places"."street" AS character varying(255)) AS "street",
          CAST("places"."city" AS character varying(255)) AS "city",
          CAST("job_sites"."latitude" AS double precision) AS "latitude",
          CAST("listings_retained"."mls_number" AS character varying(255)) AS "mls_number",
          CAST(CASE WHEN "listings_retained"."job_id" IS NULL THEN false ELSE "listings_retained"."featured" END AS boolean) AS "featured",
          CAST(("jobs"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "created_at",
          CAST(("jobs"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "updated_at",
          CAST(("jobs"."deleted_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "deleted_at"
        FROM (SELECT * FROM "jobs" WHERE NOT COALESCE(("merged_into_job_id" is not null), false)) AS "jobs"
        LEFT JOIN "listings_retained" ON "listings_retained"."job_id" = "jobs"."id"
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
        Compatibility::install(Listing::class);

        $this->assertSame("CREATE OR REPLACE VIEW \"listings\" AS\n".self::LISTING_SELECT, $this->viewCompiler()->replace(Compatibility::of(Listing::class)->resolve()));
    }

    #[Test]
    public function it_refuses_to_replace_the_view_with_a_map_that_no_longer_serves_one_of_its_columns(): void
    {
        Compatibility::prepare(Listing::class);
        Compatibility::install(Listing::class);
        $map = $this->listings(fn (Compatibility $listing) => $listing->servedFrom(Job::class, fn (Successor $job) => $job->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))));

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'listings_retained has no column company_id, which listings keeps as a legacy-only column'));

        $this->viewCompiler()->replace($map);
    }

    #[Test]
    public function it_writes_the_bindings_of_a_relation_and_a_hide_rule_into_the_view_as_literals(): void
    {
        Compatibility::prepare(Listing::class);
        $map = $this->listings(fn (Compatibility $listing) => $listing
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id'))
                ->related(fn (Job $job) => $job->hasOne(Place::class)->where('city', "Coeur d'Alene")->whereIn('street', ['1 Oak St', '2 Oak St']), fn (Successor $place) => $place
                    ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))))
            ->hide(fn (Builder $jobs) => $jobs->where('price_cents', '>', 1000000)->orWhere('name', 'like', '%?%'), because: 'Too dear.'));

        $view = $this->viewCompiler()->create($map);

        $this->assertStringContainsString(<<<'SQL'
            FROM (SELECT * FROM "jobs" WHERE NOT COALESCE(("price_cents" > '1000000' or "name"::text like '%?%'), false)) AS "jobs"
            SQL, $view);
        $this->assertStringContainsString(<<<'SQL'
            LEFT JOIN LATERAL (SELECT * FROM "places" WHERE "places"."job_id" = "jobs"."id" AND ("city" = 'Coeur d''Alene' and "street" in ('1 Oak St', '2 Oak St')) AND "places"."deleted_at" IS NULL LIMIT 1) AS "places" ON true
            SQL, $view);
    }

    #[Test]
    public function it_selects_only_the_successor_rows_whose_discriminator_names_the_legacy_model(): void
    {
        FileTables::create();
        Compatibility::prepare(Photo::class);

        $this->assertSame(<<<'SQL'
            CREATE VIEW "photos" AS
            SELECT
              CAST("files"."legacy_id" AS integer) AS "id",
              CAST("files"."legacy_uuid" AS uuid) AS "uuid",
              CAST("files"."filename" AS character varying(255)) AS "file_name",
              CAST("photos_retained"."caption" AS character varying(255)) AS "caption",
              CAST(CASE WHEN "photos_retained"."file_id" IS NULL THEN 0 ELSE "photos_retained"."views" END AS integer) AS "views",
              CAST((CAST("files"."size_bytes" AS numeric) / 1000) AS numeric(12,3)) AS "size_kb",
              CAST(("files"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "created_at",
              CAST(("files"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "updated_at"
            FROM (SELECT * FROM "files" WHERE "files"."legacy_type" = 'photo') AS "files"
            LEFT JOIN "photos_retained" ON "photos_retained"."file_id" = "files"."id"
            SQL, $this->viewCompiler()->create(Compatibility::of(Photo::class)->resolve()));
    }

    #[Test]
    public function it_unions_one_select_per_successor_and_keys_the_retained_table_by_the_legacy_identifier(): void
    {
        FileTables::create();
        Compatibility::prepare(Video::class);

        $this->assertSame(<<<'SQL'
            CREATE VIEW "videos" AS
            SELECT
              CAST("files"."legacy_id" AS integer) AS "id",
              CAST("files"."legacy_uuid" AS uuid) AS "uuid",
              CAST("files"."filename" AS character varying(255)) AS "original_file_name",
              CAST((CAST("files"."size_bytes" AS numeric) / 1000) AS numeric(12,3)) AS "size_kb",
              CAST(NULL AS character varying(255)) AS "youtube_embed_url",
              CAST("videos_retained"."title" AS character varying(255)) AS "title",
              CAST(CASE WHEN "videos_retained"."id" IS NULL THEN 0 ELSE "videos_retained"."views" END AS integer) AS "views",
              CAST(("files"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "created_at",
              CAST(("files"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "updated_at"
            FROM (SELECT * FROM "files" WHERE "files"."legacy_type" = 'video') AS "files"
            LEFT JOIN "videos_retained" ON "videos_retained"."id" = "files"."legacy_id"
            UNION ALL
            SELECT
              CAST("links"."legacy_id" AS integer) AS "id",
              CAST("links"."legacy_uuid" AS uuid) AS "uuid",
              CAST(NULL AS character varying(255)) AS "original_file_name",
              CAST(NULL AS numeric(12,3)) AS "size_kb",
              CAST("links"."url" AS character varying(255)) AS "youtube_embed_url",
              CAST("videos_retained"."title" AS character varying(255)) AS "title",
              CAST(CASE WHEN "videos_retained"."id" IS NULL THEN 0 ELSE "videos_retained"."views" END AS integer) AS "views",
              CAST(("links"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "created_at",
              CAST(("links"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "updated_at"
            FROM (SELECT * FROM "links" WHERE "links"."legacy_type" = 'video') AS "links"
            LEFT JOIN "videos_retained" ON "videos_retained"."id" = "links"."legacy_id"
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
              CAST("place_assets"."legacy_id" AS integer) AS "id",
              CAST("place_assets"."legacy_uuid" AS uuid) AS "uuid",
              CAST("place_assets"."title" AS character varying(255)) AS "title",
              CAST(CASE "place_assets"."asset_type" WHEN 'place_asset_file' THEN CAST("asset_files"."filename" AS character varying(255)) WHEN 'place_asset_link' THEN CAST(NULL AS character varying(255)) END AS character varying(255)) AS "original_file_name",
              CAST(CASE "place_assets"."asset_type" WHEN 'place_asset_file' THEN CAST((CAST("asset_files"."size_bytes" AS numeric) / 1000) AS numeric(12,3)) WHEN 'place_asset_link' THEN CAST(NULL AS numeric(12,3)) END AS numeric(12,3)) AS "size_kb",
              CAST(CASE "place_assets"."asset_type" WHEN 'place_asset_file' THEN CAST(NULL AS character varying(255)) WHEN 'place_asset_link' THEN CAST("asset_links"."url" AS character varying(255)) END AS character varying(255)) AS "youtube_embed_url",
              CAST(CASE WHEN "place_videos_retained"."place_asset_id" IS NULL THEN 0 ELSE "place_videos_retained"."views" END AS integer) AS "views",
              CAST(("place_assets"."created_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "created_at",
              CAST(("place_assets"."updated_at" AT TIME ZONE 'UTC') AS timestamp(0) without time zone) AS "updated_at"
            FROM (SELECT * FROM "place_assets" WHERE "place_assets"."legacy_type" = 'place_video') AS "place_assets"
            LEFT JOIN "place_videos_retained" ON "place_videos_retained"."place_asset_id" = "place_assets"."id"
            LEFT JOIN "asset_files" ON "place_assets"."asset_type" = 'place_asset_file' AND "place_assets"."asset_id" = "asset_files"."id"
            LEFT JOIN "asset_links" ON "place_assets"."asset_type" = 'place_asset_link' AND "place_assets"."asset_id" = "asset_links"."id"
            SQL, $view);
    }

    #[Test]
    public function it_refuses_a_discriminated_map_before_prepare_adds_the_discriminator(): void
    {
        FileTables::create();

        $this->expectExceptionObject(InvalidCompatibilityMap::because('photos', 'files has no column legacy_type, and Compatibility::prepare() adds it before install()'));

        $this->viewCompiler()->create(Compatibility::of(Photo::class)->resolve());
    }

    #[Test]
    public function it_leaves_an_external_attribute_out_of_the_view(): void
    {
        Compatibility::prepare(Listing::class);

        $view = $this->viewCompiler()->create($this->listings(fn (Compatibility $listing) => $listing
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->attribute('title', fn (Attribute $attribute) => $attribute->external(read: fn (array $jobs) => $jobs, write: fn (Job $job, mixed $title) => $title))
                ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id')))));

        $this->assertStringNotContainsString('"title"', $view);
        $this->assertStringContainsString('CAST(CASE WHEN "listings_retained"."job_id" IS NULL THEN false ELSE "listings_retained"."featured" END AS boolean) AS "featured"', $view);
    }

    #[Test]
    public function it_reads_a_timestamp_with_time_zone_in_a_named_time_zone(): void
    {
        Compatibility::prepare(Listing::class);

        $view = $this->viewCompiler()->create($this->listings(fn (Compatibility $listing) => $listing
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id'))
                ->attribute('created_at', fn (Attribute $attribute) => $attribute->from('created_at')->inTimeZone('America/Los_Angeles')))));

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
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id'))
                ->attribute('created_at', fn (Attribute $attribute) => $attribute->from('published_at')->inTimeZone('America/Los_Angeles')))));

        $this->assertStringContainsString('CAST(("jobs"."published_at" AT TIME ZONE \'America/Los_Angeles\') AS timestamp(0) without time zone) AS "created_at"', $view);
    }

    #[Test]
    public function it_writes_a_date_binding_of_a_relation_and_a_hide_rule_as_the_connection_formats_it(): void
    {
        Compatibility::prepare(Listing::class);
        $map = $this->listings(fn (Compatibility $listing) => $listing
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id'))
                ->related(fn (Job $job) => $job->hasOne(Place::class)->where('created_at', '>=', new DateTimeImmutable('2026-01-01 08:30:00', new DateTimeZone('Asia/Tokyo'))), fn (Successor $place) => $place
                    ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))))
            ->hide(fn (Builder $jobs) => $jobs->where('created_at', '<', new DateTimeImmutable('2020-01-01')), because: 'Too old.'));

        $view = $this->viewCompiler()->create($map);

        $this->assertStringContainsString('FROM (SELECT * FROM "jobs" WHERE NOT COALESCE(("created_at" < \'2020-01-01 00:00:00\'), false)) AS "jobs"', $view);
        $this->assertStringContainsString('AND ("created_at" >= \'2026-01-01 08:30:00\') AND "places"."deleted_at" IS NULL', $view);
    }

    /**
     * @return iterable<string, array{Closure(): mixed, Closure(Compatibility): Compatibility, string}>
     */
    public static function invalidMaps(): iterable
    {
        $prepare = fn () => Compatibility::prepare(Listing::class);
        $title = fn (Compatibility $listing) => $listing->servedFrom(Job::class, fn (Successor $job) => $job->attribute('title', fn (Attribute $attribute) => $attribute->from('name')));
        $required = fn (Compatibility $listing) => $listing->servedFrom(Job::class, fn (Successor $job) => $job
            ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
            ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id')));

        yield 'no retained table' => [$prepare, fn (Compatibility $listing) => (new Compatibility(Listing::class))->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))->servedFrom(Job::class, fn (Successor $job) => $job), 'it declares no retainedIn(), and install() keeps the legacy table as the retained table'];
        yield 'no prepared identifier' => [fn () => null, $title, 'jobs has no column legacy_id, and Compatibility::prepare() adds it before install()'];
        yield 'no legacy table' => [fn () => DB::statement('drop table listings cascade'), $title, 'the legacy table listings does not exist'];
        yield 'no successor table' => [function () use ($prepare): void {
            $prepare();
            DB::statement('drop table job_sites');
        }, fn (Compatibility $listing) => $listing->servedFrom(Job::class, fn (Successor $job) => $job->related(fn (Job $job) => $job->site(), fn (Successor $site) => $site)), 'the successor table job_sites does not exist'];
        yield 'no successor column' => [$prepare, fn (Compatibility $listing) => $listing->servedFrom(Job::class, fn (Successor $job) => $job->attribute('title', fn (Attribute $attribute) => $attribute->from('headline'))), "jobs has no column headline, and attribute 'title' reads it"];
        yield 'no legacy column' => [$prepare, fn (Compatibility $listing) => $listing->servedFrom(Job::class, fn (Successor $job) => $job->attribute('headline', fn (Attribute $attribute) => $attribute->from('name'))), 'listings has no column headline, so the view cannot serve it'];
        yield 'a wall clock in a time zone' => [
            function () use ($prepare): void {
                $prepare();
                DB::statement('alter table jobs add column published_on timestamp');
            },
            fn (Compatibility $listing) => $listing->servedFrom(Job::class, fn (Successor $job) => $job->attribute('created_at', fn (Attribute $attribute) => $attribute->from('published_on')->inTimeZone('America/Los_Angeles'))),
            "attribute 'created_at' reads jobs.published_on in the time zone America/Los_Angeles, and AT TIME ZONE reads a wall clock only from a timestamp with time zone, not from timestamp without time zone",
        ];
        yield 'a converted value in a time zone' => [
            $prepare,
            fn (Compatibility $listing) => $listing->servedFrom(Job::class, fn (Successor $job) => $job->attribute('created_at', fn (Attribute $attribute) => $attribute->from('created_at')->orDefault('2026-01-01')->inTimeZone('America/Los_Angeles'))),
            "attribute 'created_at' reads jobs.created_at in the time zone America/Los_Angeles, and AT TIME ZONE reads a wall clock only from a timestamp with time zone, not from the type the conversions before inTimeZone() give",
        ];

        yield 'a legacy-only column that needs a value' => [
            function () use ($prepare): void {
                $prepare();
                DB::statement("alter table listings add column source text not null default 'import'");
                DB::statement('alter table listings alter column source drop default');
            },
            $required,
            'listings.source is NOT NULL and has no default, so the view has no value to serve for a jobs row without a listings_retained row',
        ];
        yield 'a legacy-only column whose default is not immutable' => [
            function () use ($prepare): void {
                $prepare();
                DB::statement('alter table listings add column listed_at timestamp default now()');
            },
            $required,
            'listings.listed_at defaults to now(), which is not immutable, so the view would serve a new value on each read of a jobs row without a listings_retained row',
        ];
        yield 'a legacy-only column whose domain default is not immutable' => [
            function () use ($prepare): void {
                $prepare();
                DB::statement('drop domain if exists compatibility_stamp cascade');
                DB::statement('create domain compatibility_stamp as timestamp default now()');
                DB::statement('alter table listings add column listed_at compatibility_stamp');
            },
            $required,
            'listings.listed_at defaults to now(), which is not immutable, so the view would serve a new value on each read of a jobs row without a listings_retained row',
        ];
        yield 'a legacy-only generated column' => [
            function () use ($prepare): void {
                $prepare();
                DB::statement("alter table listings add column label text generated always as ('new'::text) stored not null");
            },
            $required,
            'listings.label is a generated or identity column, so the view cannot compute its value for a jobs row without a listings_retained row',
        ];
        yield 'a legacy-only identity column' => [
            function () use ($prepare): void {
                $prepare();
                DB::statement('alter table listings add column ticket integer generated by default as identity');
            },
            $required,
            'listings.ticket is a generated or identity column, so the view cannot compute its value for a jobs row without a listings_retained row',
        ];
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
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
            ->retainedIn('listings_retained', key: 'job_id'))
            ->resolve();
    }

    private function viewCompiler(): ViewCompiler
    {
        return new ViewCompiler(new ColumnCatalog(DB::connection()), DB::connection());
    }
}
