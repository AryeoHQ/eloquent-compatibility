<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityQueryRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(LegacyBuilder::class)]
final class LegacyBuilderReadTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
        DB::table('listings')->insert([
            ['title' => 'Ash Loft', 'company_id' => ListingTables::COMPANY, 'price' => null, 'status' => 'Active', 'street' => '4 Ash St'],
            ['title' => 'Fir Hall', 'company_id' => ListingTables::COMPANY, 'price' => '500.00', 'status' => 'Draft', 'street' => null],
        ]);
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_queries_the_legacy_table_as_it_is_before_install(): void
    {
        $this->assertSame('select * from "listings" where "price" > 100 and "listings"."deleted_at" is null', Listing::query()->where('price', '>', 100)->toBase()->toRawSql());
        $this->assertSame([1 => 'Active', 4 => 'Active', 2 => 'Draft', 5 => 'Draft', 3 => 'Sold'], Listing::withTrashed()->orderBy('status')->orderBy('id')->pluck('status', 'id')->map(fn (ListingStatus $status) => $status->value)->all());
    }

    #[Test]
    public function it_filters_a_converted_attribute_on_its_stored_column_once_installed(): void
    {
        $this->install();

        $this->assertSame(
            'select * from "listings" where "listings"."id" in (select "jobs"."legacy_id" from "jobs" where ("jobs"."price_cents" > 10000)) and not ("listings"."id" not in (select "jobs"."legacy_id" from "jobs" where ("jobs"."lifecycle_status" = \'closed\'))) and "listings"."deleted_at" is null',
            Listing::query()->where('price', '>', 100)->whereNot(fn (Builder $listings) => $listings->where('status', '!=', ListingStatus::Sold))->toBase()->toRawSql(),
        );
    }

    #[Test]
    public function it_returns_the_rows_and_order_the_legacy_table_returned(): void
    {
        $queries = [
            'price above' => fn (Builder $listings) => $listings->where('price', '>', 100),
            'price equal' => fn (Builder $listings) => $listings->where('price', 99.99),
            'price not above' => fn (Builder $listings) => $listings->whereNot('price', '>', 100),
            'or not price above' => fn (Builder $listings) => $listings->where('title', 'Pine Flat')->orWhereNot('price', '>', 100),
            'nested not' => fn (Builder $listings) => $listings->whereNot(fn (Builder $nested) => $nested->where('title', 'Oak House')->orWhere('price', '>', 100)),
            'doubly nested not' => fn (Builder $listings) => $listings->whereNot(fn (Builder $nested) => $nested->whereNot(fn (Builder $inner) => $inner->where('price', '<', 1000))),
            'status' => fn (Builder $listings) => $listings->where('status', ListingStatus::Active),
            'status not sold' => fn (Builder $listings) => $listings->where('status', '!=', 'Sold'),
            'status in' => fn (Builder $listings) => $listings->whereIn('status', ['Draft', 'Sold']),
            'status not in' => fn (Builder $listings) => $listings->whereNotIn('status', [ListingStatus::Active]),
            'or status in' => fn (Builder $listings) => $listings->where('title', 'Oak House')->orWhereIn('status', ['Sold']),
            'price between' => fn (Builder $listings) => $listings->whereBetween('price', [50, 1000]),
            'price not between' => fn (Builder $listings) => $listings->whereNotBetween('price', [50, 1000]),
            'price null' => fn (Builder $listings) => $listings->whereNull('price'),
            'price not null' => fn (Builder $listings) => $listings->whereNotNull('price'),
            'or price null' => fn (Builder $listings) => $listings->where('title', 'Elm Cottage')->orWhereNull('price'),
            'array' => fn (Builder $listings) => $listings->where(['status' => 'Active', 'title' => 'Oak House']),
            'array of conditions' => fn (Builder $listings) => $listings->where([['price', '<', 600], ['status', '=', 'Draft']]),
            'or array of conditions' => fn (Builder $listings) => $listings->where('title', 'Elm Cottage')->orWhere([['status', '=', 'Active'], ['title', '=', 'Pine Flat']]),
            'qualified' => fn (Builder $listings) => $listings->where('listings.price', '>=', 99.99),
            'unconverted' => fn (Builder $listings) => $listings->where('street', 'like', '%Elm%'),
            'with trashed' => fn (Builder $listings) => $listings->withoutGlobalScope(SoftDeletingScope::class)->whereNull('price'),
            'ordered by price' => fn (Builder $listings) => $listings->reorder()->orderByDesc('price')->orderBy('id'),
            'latest' => fn (Builder $listings) => $listings->reorder()->latest()->orderBy('id'),
        ];

        $before = $this->idsFor($queries);
        $this->install();

        $this->assertSame($before, $this->idsFor($queries));
        $this->assertSame([1, 5], $before['price above']);
        $this->assertSame(
            ['unconverted', 'ordered by price', 'latest'],
            array_keys(array_filter($queries, fn (Closure $query) => ! str_contains($query(Listing::query())->toBase()->toRawSql(), 'select "jobs"."legacy_id"'))),
        );
    }

    #[Test]
    public function it_aggregates_a_converted_attribute_as_the_legacy_table_did(): void
    {
        $aggregates = fn () => [Listing::query()->sum('price'), Listing::query()->max('price'), Listing::query()->min('status'), Listing::query()->avg('price'), Listing::withTrashed()->toBase()->count('price')];
        $before = $aggregates();

        $this->install();

        $this->assertSame($before, $aggregates());
        $this->assertSame('1850.49', $before[0]);
    }

    #[Test]
    public function it_filters_a_converted_attribute_of_a_related_successor_through_its_relations(): void
    {
        $this->install();
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->related(fn (Job $job) => $job->site(), fn (Successor $site) => $site
                            ->attribute('latitude', fn (Attribute $attribute) => $attribute->from('latitude')->multipliedBy(2))));
            }
        };

        $query = $model->newQuery()->where('latitude', '>', 40);

        $this->assertInstanceOf(LegacyBuilder::class, $query);
        $this->assertSame(
            'select * from "listings" where "listings"."id" in (select "jobs"."legacy_id" from "jobs" LEFT JOIN LATERAL (SELECT * FROM "job_sites" WHERE "job_sites"."job_id" = "jobs"."id" LIMIT 1) AS "job_sites" ON true where ("job_sites"."latitude" > 20))',
            $query->toBase()->toRawSql(),
        );
        $this->assertSame([1], $query->pluck('id')->all());
    }

    #[Test]
    public function it_refuses_a_query_no_conversion_can_translate_once_installed(): void
    {
        $this->install();
        $refusals = [];

        foreach ([
            fn () => Listing::query()->orderBy('status'),
            fn () => Listing::query()->where('status', 'like', 'Act%'),
            fn () => Listing::query()->where('status', 'Pending'),
            fn () => Listing::query()->where('price', DB::raw('100')),
            fn () => Listing::query()->whereIn('price', DB::table('listings')->select('price')),
            fn () => Listing::query()->whereNull('status'),
        ] as $query) {
            try {
                $query();
            } catch (CompatibilityQueryRefused $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $instead = ' Instead: filter or sort on the stored column through the successor model.';
        $this->assertSame([
            'Tests\Fixtures\Support\Listings\Listing::$status cannot take orderBy asc. Why: its conversions do not keep the order of the stored values.'.$instead,
            "Tests\\Fixtures\\Support\\Listings\\Listing::\$status cannot take where like. Why: its conversions give no stored value to compare with 'Act%'.".$instead,
            "Tests\\Fixtures\\Support\\Listings\\Listing::\$status cannot take where =. Why: its conversions give no stored value to compare with 'Pending'.".$instead,
            'Tests\Fixtures\Support\Listings\Listing::$price cannot take where =. Why: a raw expression or a subquery does not pass through its conversions.'.$instead,
            'Tests\Fixtures\Support\Listings\Listing::$price cannot take whereIn. Why: a subquery does not pass through its conversions.'.$instead,
            'Tests\Fixtures\Support\Listings\Listing::$status cannot take whereNull. Why: its conversions give no stored value to compare with NULL.'.$instead,
        ], $refusals);
    }

    #[Test]
    public function it_filters_a_fractional_price_as_the_legacy_table_did(): void
    {
        $queries = [
            'above' => fn (Builder $listings) => $listings->where('price', '>', 99.995),
            'at least' => fn (Builder $listings) => $listings->where('price', '>=', 99.995),
            'below' => fn (Builder $listings) => $listings->where('price', '<', 99.995),
            'at most' => fn (Builder $listings) => $listings->where('price', '<=', 99.995),
            'equal' => fn (Builder $listings) => $listings->where('price', 99.995),
            'not equal' => fn (Builder $listings) => $listings->where('price', '!=', 99.995),
            'not' => fn (Builder $listings) => $listings->whereNot('price', 99.995),
            'nested not' => fn (Builder $listings) => $listings->whereNot(fn (Builder $nested) => $nested->where('price', '>', 99.995)),
            'in' => fn (Builder $listings) => $listings->whereIn('price', [99.995, 99.99]),
            'between' => fn (Builder $listings) => $listings->whereBetween('price', [99.985, 500.005]),
        ];

        $before = $this->idsFor($queries);
        $this->install();

        $this->assertSame($before, $this->idsFor($queries));
        $this->assertSame([[1, 5], [], [1, 3, 5]], [$before['above'], $before['equal'], $before['not']]);
    }

    #[Test]
    public function it_negates_a_mapping_filter_without_matching_a_stored_value_the_mapping_leaves_out(): void
    {
        $this->install();
        DB::table('jobs')->insert(['name' => 'Archived Annex', 'company_id' => ListingTables::COMPANY, 'lifecycle_status' => 'archived']);

        $this->assertSameRows(fn () => Listing::query(), fn () => DB::table('listings')->whereNull('deleted_at'), [
            'not equal' => fn (Builder|QueryBuilder $listings) => $listings->where('status', '!=', 'Sold'),
            'not' => fn (Builder|QueryBuilder $listings) => $listings->whereNot('status', ListingStatus::Sold),
            'not in' => fn (Builder|QueryBuilder $listings) => $listings->whereNotIn('status', ['Sold', 'Draft']),
            'nested not' => fn (Builder|QueryBuilder $listings) => $listings->whereNot(fn (Builder|QueryBuilder $nested) => $nested->where('status', 'Sold')->orWhere('status', 'Draft')),
            'or not equal' => fn (Builder|QueryBuilder $listings) => $listings->where('title', 'Pine Flat')->orWhere('status', '<>', 'Active'),
            'not a status no row has' => fn (Builder|QueryBuilder $listings) => $listings->where('status', '!=', 'Pending'),
        ]);
    }

    #[Test]
    public function it_filters_an_is_not_null_attribute_as_the_view_serves_it(): void
    {
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return LegacyBuilderReadTest::identified($listing)
                    ->servedFrom(Job::class, fn (Successor $job) => LegacyBuilderReadTest::required($job)
                        ->attribute('featured', fn (Attribute $attribute) => $attribute->from('price_cents')->isNotNull(fn () => 0)));
            }
        };
        $this->installMap($model::class);

        $this->assertSameRows(fn () => $model->newQuery(), fn () => DB::table('listings'), [
            'null' => fn (Builder|QueryBuilder $listings) => $listings->whereNull('featured'),
            'not null' => fn (Builder|QueryBuilder $listings) => $listings->whereNotNull('featured'),
            'false as text' => fn (Builder|QueryBuilder $listings) => $listings->where('featured', 'false'),
            'true as text' => fn (Builder|QueryBuilder $listings) => $listings->where('featured', 'true'),
            'false' => fn (Builder|QueryBuilder $listings) => $listings->where('featured', false),
            'not true' => fn (Builder|QueryBuilder $listings) => $listings->whereNot('featured', true),
            'not equal to false' => fn (Builder|QueryBuilder $listings) => $listings->where('featured', '!=', '0'),
        ]);

        $this->expectExceptionObject(CompatibilityQueryRefused::untranslatable($model::class, 'featured', 'where =', "its conversions give no stored value to compare with 'maybe'."));

        $model->newQuery()->where('featured', 'maybe');
    }

    #[Test]
    public function it_filters_a_cast_or_a_narrowed_attribute_on_the_expression_the_view_serves(): void
    {
        DB::statement('alter table listings add column listed_on date, add column floor integer');
        DB::statement('alter table jobs add column listed_at timestamptz, add column floor_code text, add column price_mills integer');
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return LegacyBuilderReadTest::identified($listing)
                    ->servedFrom(Job::class, fn (Successor $job) => LegacyBuilderReadTest::required($job)
                        ->attribute('listed_on', fn (Attribute $attribute) => $attribute->from('listed_at')->asType('date'))
                        ->attribute('floor', fn (Attribute $attribute) => $attribute->from('floor_code')->asType('integer'))
                        ->attribute('price', fn (Attribute $attribute) => $attribute->from('price_mills')->dividedBy(1000)));
            }
        };
        Compatibility::prepare($model::class);
        ListingTables::backfill();
        DB::update("update jobs set listed_at = '2026-01-01 15:30:00+00', floor_code = '01', price_mills = 1005 where legacy_id = 1");
        DB::update("update jobs set listed_at = '2026-01-02 00:30:00+00', floor_code = '2', price_mills = 1010 where legacy_id = 3");
        Compatibility::install($model::class);

        $this->assertSameRows(fn () => $model->newQuery(), fn () => DB::table('listings'), [
            'a date' => fn (Builder|QueryBuilder $listings) => $listings->where('listed_on', '2026-01-01'),
            'after a date' => fn (Builder|QueryBuilder $listings) => $listings->where('listed_on', '>', '2026-01-01'),
            'an integer read from text' => fn (Builder|QueryBuilder $listings) => $listings->where('floor', 1),
            'integers read from text' => fn (Builder|QueryBuilder $listings) => $listings->whereIn('floor', [1, 2]),
            'a rounded price' => fn (Builder|QueryBuilder $listings) => $listings->where('price', 1.01),
            'above a rounded price' => fn (Builder|QueryBuilder $listings) => $listings->where('price', '>', 1.005),
            'not a rounded price' => fn (Builder|QueryBuilder $listings) => $listings->whereNot('price', 1.01),
            'no price' => fn (Builder|QueryBuilder $listings) => $listings->whereNull('price'),
        ]);
        $this->assertStringContainsString('where (CAST(CAST(("jobs"."listed_at" AT TIME ZONE \'UTC\') AS date) AS date) = \'2026-01-01\')', $model->newQuery()->where('listed_on', '2026-01-01')->toBase()->toRawSql());
    }

    #[Test]
    public function it_filters_an_or_default_attribute_by_a_value_normalised_to_the_default_type(): void
    {
        DB::statement('alter table jobs add column is_featured boolean');
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return LegacyBuilderReadTest::identified($listing)
                    ->servedFrom(Job::class, fn (Successor $job) => LegacyBuilderReadTest::required($job)
                        ->attribute('featured', fn (Attribute $attribute) => $attribute->from('is_featured')->orDefault(false)));
            }
        };
        Compatibility::prepare($model::class);
        ListingTables::backfill();
        DB::update('update jobs set is_featured = true where legacy_id = 1');
        DB::update('update jobs set is_featured = false where legacy_id = 3');
        Compatibility::install($model::class);

        $this->assertSameRows(fn () => $model->newQuery(), fn () => DB::table('listings'), [
            'true' => fn (Builder|QueryBuilder $listings) => $listings->where('featured', true),
            'one' => fn (Builder|QueryBuilder $listings) => $listings->where('featured', 1),
            'true as text' => fn (Builder|QueryBuilder $listings) => $listings->where('featured', 'true'),
            'null' => fn (Builder|QueryBuilder $listings) => $listings->whereNull('featured'),
            'not true' => fn (Builder|QueryBuilder $listings) => $listings->whereNot('featured', true),
            'not equal to true' => fn (Builder|QueryBuilder $listings) => $listings->where('featured', '!=', true),
            'not false' => fn (Builder|QueryBuilder $listings) => $listings->whereNot('featured', false),
            'nested not true' => fn (Builder|QueryBuilder $listings) => $listings->whereNot(fn (Builder|QueryBuilder $nested) => $nested->where('featured', true)),
        ]);
        $refusals = [];

        foreach ([false, 0, '0', 'false', 'maybe'] as $value) {
            try {
                $model->newQuery()->where('featured', $value);
            } catch (CompatibilityQueryRefused) {
                $refusals[] = $value;
            }
        }

        $this->assertSame([false, 0, '0', 'false', 'maybe'], $refusals);
    }

    #[Test]
    public function it_serves_and_filters_a_mapping_whose_stored_values_are_digit_strings(): void
    {
        DB::statement("alter table jobs add column code text not null default '1'");
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return LegacyBuilderReadTest::identified($listing)
                    ->servedFrom(Job::class, fn (Successor $job) => LegacyBuilderReadTest::required($job)
                        ->attribute('status', fn (Attribute $attribute) => $attribute->from('code')->mapping(['1' => 'Draft', '2' => 'Active', '3' => 'Sold'])));
            }
        };
        Compatibility::prepare($model::class);
        ListingTables::backfill();
        DB::update("update jobs set code = case lifecycle_status when 'live' then '2' when 'closed' then '3' else '1' end");
        Compatibility::install($model::class);

        $this->assertSameRows(fn () => $model->newQuery(), fn () => DB::table('listings'), [
            'active' => fn (Builder|QueryBuilder $listings) => $listings->where('status', 'Active'),
            'not draft' => fn (Builder|QueryBuilder $listings) => $listings->where('status', '!=', 'Draft'),
            'sold or draft' => fn (Builder|QueryBuilder $listings) => $listings->whereIn('status', ['Sold', 'Draft']),
        ]);
        $this->assertSame([1, 4], array_map(intval(...), DB::table('listings')->where('status', 'Active')->orderBy('id')->pluck('id')->all()));
    }

    #[Test]
    public function it_reads_and_filters_a_cast_or_raw_timestamp_with_time_zone_in_the_application_zone_whatever_the_session_zone(): void
    {
        DB::statement('alter table listings add column listed_on date, add column listed_wall timestamp(0)');
        DB::statement('alter table jobs add column listed_at timestamptz');
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return LegacyBuilderReadTest::identified($listing)
                    ->servedFrom(Job::class, fn (Successor $job) => LegacyBuilderReadTest::required($job)
                        ->attribute('listed_on', fn (Attribute $attribute) => $attribute->from('listed_at')->asType('date'))
                        ->attribute('listed_wall', fn (Attribute $attribute) => $attribute->readAs('"jobs"."listed_at"')->readOnly(because: 'The successor records the instant.'))
                        ->attribute('created_at', fn (Attribute $attribute) => $attribute->from('created_at')->inTimeZone('America/Los_Angeles')));
            }
        };
        Compatibility::prepare($model::class);
        ListingTables::backfill();
        DB::update("update jobs set listed_at = '2026-01-01 23:30:00+00' where legacy_id = 1");
        Compatibility::install($model::class);
        $inZone = fn (string $zone, Closure $read) => DB::transaction(function () use ($zone, $read): mixed {
            DB::statement("set local time zone '{$zone}'");

            return $read();
        });
        $served = fn () => (array) DB::selectOne('select cast(listed_on as text) as listed_on, cast(listed_wall as text) as listed_wall from listings where id = 1');
        $filtered = fn () => [
            array_map(intval(...), $model->newQuery()->where('listed_on', '2026-01-01')->pluck('id')->all()),
            array_map(intval(...), $model->newQuery()->where('created_at', '2026-01-01 02:00:00')->pluck('id')->all()),
        ];

        $this->assertSame([
            ['listed_on' => '2026-01-01', 'listed_wall' => '2026-01-01 23:30:00'],
            ['listed_on' => '2026-01-01', 'listed_wall' => '2026-01-01 23:30:00'],
            [[1], [1]],
            [[1], [1]],
        ], [$inZone('UTC', $served), $inZone('Asia/Tokyo', $served), $inZone('UTC', $filtered), $inZone('Asia/Tokyo', $filtered)]);
    }

    public static function identified(Compatibility $listing): Compatibility
    {
        return $listing
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
            ->retainedIn('listings_retained', key: 'job_id');
    }

    public static function required(Successor $job): Successor
    {
        return $job
            ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
            ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.'));
    }

    private function install(): void
    {
        $this->installMap(Listing::class);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function installMap(string $model): void
    {
        Compatibility::prepare($model);
        ListingTables::backfill();
        Compatibility::install($model);
    }

    /**
     * @param  Closure(): Builder<covariant Model>  $eloquent
     * @param  Closure(): QueryBuilder  $raw
     * @param  array<string, Closure(Builder<covariant Model>|QueryBuilder): mixed>  $queries
     */
    private function assertSameRows(Closure $eloquent, Closure $raw, array $queries): void
    {
        $ids = fn (Builder|QueryBuilder $query) => array_map(intval(...), $query->orderBy('id')->pluck('id')->all());
        $served = [];
        $translated = [];

        foreach ($queries as $name => $query) {
            $rawQuery = $raw()->where(function (QueryBuilder $nested) use ($query): void {
                $query($nested);
            });
            $served[$name] = $ids($rawQuery);
            $eloquentQuery = $eloquent();
            $query($eloquentQuery);
            $translated[$name] = $ids($eloquentQuery);
        }

        $this->assertSame($served, $translated);
    }

    /**
     * @param  array<string, Closure(Builder<Listing>): mixed>  $queries
     * @return array<string, list<int>>
     */
    private function idsFor(array $queries): array
    {
        return array_map(function (Closure $query): array {
            $listings = Listing::query()->orderBy('id');
            $query($listings);

            return array_map(intval(...), $listings->pluck('id')->all());
        }, $queries);
    }
}
