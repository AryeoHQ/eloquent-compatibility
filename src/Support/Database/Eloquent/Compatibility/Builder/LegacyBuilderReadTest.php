<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
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
            'select * from "listings" where "listings"."id" in (select "jobs"."legacy_id" from "jobs" where ("jobs"."price_cents" > 10000)) and not ("listings"."id" not in (select "jobs"."legacy_id" from "jobs" where not ("jobs"."lifecycle_status" != \'closed\'))) and "listings"."deleted_at" is null',
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

    private function install(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(Listing::class);
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
