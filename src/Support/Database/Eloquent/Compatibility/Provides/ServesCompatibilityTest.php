<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Exceptions\UndiscoveredServedModel;
use Tests\Fixtures\Support\Discovery\InheritedServed;
use Tests\Fixtures\Support\Discovery\TraitServed;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;
use Throwable;

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
    public function it_refuses_every_instance_of_a_named_served_model_that_discovery_did_not_find(): void
    {
        $refusals = [];

        foreach ([1, 2] as $attempt) {
            try {
                new Unmapped;
            } catch (Throwable $refusal) {
                $refusals[] = [$refusal::class, $refusal->getMessage()];
            }
        }

        $expected = UndiscoveredServedModel::named(Unmapped::class);
        $this->assertSame([[$expected::class, $expected->getMessage()], [$expected::class, $expected->getMessage()]], $refusals);
    }

    #[Test]
    public function it_boots_a_model_that_inherits_serving_compatibility_from_a_parent_or_a_trait(): void
    {
        $this->assertSame([true, true], [(new InheritedServed)->newQuery() instanceof LegacyBuilder, (new TraitServed)->newQuery() instanceof LegacyBuilder]);
    }

    #[Test]
    public function it_reads_identical_values_through_eloquent_and_through_raw_sql_once_installed(): void
    {
        $eloquent = fn () => Listing::withTrashed()->orderBy('id')->get()->map(fn (Listing $listing) => $listing->getAttributes())->all();
        $raw = fn () => array_map(fn (object $row) => (array) $row, DB::select('select * from listings order by id'));
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        $before = [$eloquent(), $raw()];

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $listing = Listing::query()->findOrFail(1);

        $this->assertSame($before, [$eloquent(), $raw()]);
        $this->assertSame($raw(), $eloquent());
        $this->assertSame(['1250.50', ListingStatus::Active, 47.6062, '1 Oak St', true], [$listing->price, $listing->status, $listing->getAttribute('latitude'), $listing->getAttribute('street'), $listing->getAttribute('featured')]);
        $this->assertSame([1, 3], Listing::query()->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function it_writes_the_instance_s_own_insert_update_and_delete_natively_before_install_and_through_the_successor_once_installed(): void
    {
        $rows = fn (string $table, string $id, string $title) => DB::table($table)->where($id, '>', 3)->orderBy($id)->get([$id, $title, 'deleted_at'])->map(fn (object $row) => [$row->{$id}, $row->{$title}, $row->deleted_at !== null])->all();
        $writes = function (string $first, string $second): int {
            $listing = Listing::query()->create(['title' => $first, 'company_id' => ListingTables::COMPANY]);
            $listing->update(['title' => "{$first} Hall"]);
            Listing::query()->create(['title' => $second, 'company_id' => ListingTables::COMPANY])->delete();

            return $listing->id;
        };

        $native = $writes('Birch', 'Ash');
        $tableRows = $rows('listings', 'id', 'title');
        $nativeJobs = DB::table('jobs')->count();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $translated = $writes('Cedar', 'Fir');

        $this->assertSame([4, [[4, 'Birch Hall', false], [5, 'Ash', true]], 0], [$native, $tableRows, $nativeJobs]);
        $this->assertSame([6, [[4, 'Birch Hall', false], [5, 'Ash', true], [6, 'Cedar Hall', false], [7, 'Fir', true]]], [$translated, $rows('listings', 'id', 'title')]);
    }
}

final class Unmapped extends Model implements Compatible
{
    use ServesCompatibility;

    public static function compatibility(Compatibility $compatibility): Compatibility
    {
        return $compatibility;
    }
}
