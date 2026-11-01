<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityQueryRefused;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(RoutesCompatibleWrites::class)]
#[CoversClass(CompatibilityQueryRefused::class)]
final class QueryTranslationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        resolve(Installer::class)->install(Listing::class);
        resolve(WriteRouter::class)->register(Listing::class);

        Listing::query()->create(['title' => 'Cheap', 'price' => '350000', 'city' => 'Tacoma', 'street' => '1 A St']);
        Listing::query()->create(['title' => 'Middle', 'price' => '450000', 'city' => 'Seattle', 'street' => '2 B St', 'is_published' => true]);
        Listing::query()->create(['title' => 'Dear', 'price' => '550000', 'city' => 'Seattle', 'street' => '3 C St', 'is_published' => true]);
    }

    #[Test]
    public function it_translates_each_filter_value_through_the_cast(): void
    {
        $this->assertSame(['Middle', 'Dear'], $this->titles(Listing::query()->where('price', '>', 400000)));
        $this->assertSame(['Middle'], $this->titles(Listing::query()->where('price', '450000.00')));
        $this->assertSame(['Cheap'], $this->titles(Listing::query()->where(['price' => '350000'])));
        $this->assertSame(['Cheap', 'Dear'], $this->titles(Listing::query()->whereIn('price', [350000, '550000.00'])));
        $this->assertSame(['Middle'], $this->titles(Listing::query()->whereNotIn('price', [350000, 550000])));
        $this->assertSame(['Middle', 'Dear'], $this->titles(Listing::query()->whereBetween('price', ['400000', '600000'])));
        $this->assertSame(['Cheap'], $this->titles(Listing::query()->whereNotBetween('price', ['400000', '600000'])));
        $this->assertSame(['Cheap', 'Dear'], $this->titles(Listing::query()->where('price', '<', 400000)->orWhere('price', '>', 500000)));
        $this->assertSame(['Cheap', 'Middle'], $this->titles(Listing::query()->whereNot('price', 550000)));
        $this->assertSame(['Middle'], $this->titles(Listing::query()->where(fn ($query) => $query->where('price', '>=', 450000)->where('price', '<', 500000))));
        $this->assertSame(['Middle', 'Dear'], $this->titles(Listing::query()->where('listings.price', '>', 400000)));
    }

    #[Test]
    public function it_sends_the_stored_value_to_sql(): void
    {
        $this->assertSame(
            'select * from "listings" where "price" > 40000000 and "listings"."deleted_at" is null',
            Listing::query()->where('price', '>', 400000)->toRawSql(),
        );
    }

    #[Test]
    public function it_sorts_aggregates_and_increments_in_the_stored_form(): void
    {
        $this->assertSame('550000.00', Listing::query()->orderByDesc('price')->value('price'));
        $this->assertSame('350000.00', Listing::query()->orderBy('price')->value('price'));
        $this->assertSame(['550000.00', '350000.00', '1350000.00', '450000.00'], [Listing::query()->max('price'), Listing::query()->min('price'), Listing::query()->sum('price'), Listing::query()->avg('price')]);

        Listing::query()->where('title', 'Cheap')->sole()->increment('price', 1000);
        Listing::query()->where('city', 'Seattle')->increment('price', 500);

        $this->assertSame([35100000, 45050000, 55050000], Job::query()->orderBy('price_cents')->pluck('price_cents')->all());
    }

    #[Test]
    public function it_applies_a_filter_translation_and_named_filters(): void
    {
        $this->assertSame(['Middle', 'Dear'], $this->titles(Listing::query()->where('is_published', true)));
        $this->assertSame(['Cheap'], $this->titles(Listing::query()->where('is_published', false)));
        $this->assertSame(['Middle', 'Dear'], $this->titles(Listing::query()->published()->inCity('Seattle')->pricedBetween('400000', '600000')));
        $this->assertSame(['Cheap', 'Middle', 'Dear'], $this->titles(Listing::query()->whereIn('is_published', [true, false])));
        $this->assertStringContainsString('exists (select * from "jobs" where "listings"."job_id" = "jobs"."id" and "published_at" is not null', Listing::query()->published()->toRawSql());
    }

    #[Test]
    public function it_refuses_a_query_it_cannot_translate(): void
    {
        $this->assertThrows(fn () => Listing::query()->orderBy('is_published')->get(), CompatibilityQueryRefused::class, Listing::class.'::$is_published cannot take orderBy');
        $this->assertThrows(fn () => Listing::query()->sum('is_published'), CompatibilityQueryRefused::class, 'its filter translation covers filters only');
        $this->assertThrows(fn () => Listing::query()->where('is_published', '>', 0)->get(), LogicException::class, 'is_published cannot be filtered with >.');
    }

    #[Test]
    public function it_leaves_raw_sql_comparing_the_stored_value(): void
    {
        // Documented limit: raw SQL bypasses the builder, so it compares cents, and returns every row.
        $this->assertSame(['Cheap', 'Middle', 'Dear'], $this->titles(Listing::query()->whereRaw('price > 400000')));
        $this->assertSame(3, DB::table('listings')->where('price', '>', 400000)->count());
    }

    /**
     * @return list<string>
     */
    private function titles(mixed $query): array
    {
        return $query->orderBy('id')->pluck('title')->all();
    }
}
