<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversTrait(ServesCompatibility::class)]
final class ServesCompatibilityTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_gives_a_model_that_serves_compatibility_the_legacy_builder_and_leaves_its_successor_alone(): void
    {
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public static function compatibility(Compatibility $compatibility): Compatibility
            {
                return $compatibility;
            }
        };

        $this->assertInstanceOf(LegacyBuilder::class, Listing::query());
        $this->assertInstanceOf(LegacyBuilder::class, $model->newQuery());
        $this->assertSame(Builder::class, Job::query()::class);
    }

    #[Test]
    public function it_reads_identical_values_through_eloquent_and_through_raw_sql_once_installed(): void
    {
        $eloquent = fn () => Listing::withTrashed()->orderBy('id')->get()->map(fn (Listing $listing) => $listing->getAttributes())->all();
        $raw = fn () => array_map(fn (object $row) => (array) $row, DB::select('select * from listings order by id'));
        $before = [$eloquent(), $raw()];

        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(Listing::class);
        $listing = Listing::query()->findOrFail(1);

        $this->assertSame($before, [$eloquent(), $raw()]);
        $this->assertSame($raw(), $eloquent());
        $this->assertSame(['1250.50', ListingStatus::Active, 47.6062, '1 Oak St', true], [$listing->price, $listing->status, $listing->getAttribute('latitude'), $listing->getAttribute('street'), $listing->getAttribute('featured')]);
        $this->assertSame([1, 3], Listing::query()->orderBy('id')->pluck('id')->all());
    }
}
