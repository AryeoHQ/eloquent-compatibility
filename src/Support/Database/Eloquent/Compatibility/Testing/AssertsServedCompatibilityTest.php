<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Exception as PHPUnitException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversTrait(AssertsServedCompatibility::class)]
final class AssertsServedCompatibilityTest extends TestCase
{
    use AssertsServedCompatibility;

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
    public function it_passes_when_the_installed_view_is_the_view_the_map_compiles(): void
    {
        $this->install();

        $this->assertServesCompatibility(Listing::class);
    }

    #[Test]
    public function it_fails_and_names_the_recompile_when_the_installed_view_differs_from_the_view_the_map_compiles(): void
    {
        $this->install();
        $definition = rtrim(trim((string) DB::scalar("select pg_get_viewdef('listings'::regclass)")), ';');
        DB::unprepared("create or replace view listings as select * from ({$definition}) as edited");

        $this->assertStringStartsWith(
            'The installed view listings differs from the view the map of '.Listing::class.' compiles. Run Compatibility::recompile('.Listing::class.'::class) in a migration.',
            $this->failureOf(Listing::class),
        );
    }

    #[Test]
    public function it_passes_before_install_when_the_map_compiles_for_install(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();

        $this->assertServesCompatibility(Listing::class);
    }

    #[Test]
    public function it_fails_before_install_when_the_map_does_not_compile(): void
    {
        $this->assertSame(
            'The map of '.Listing::class." does not compile: Compatibility map 'listings' is declared wrongly: jobs has no column legacy_id, and Compatibility::prepare() adds it before install().",
            $this->failureOf(Listing::class),
        );
    }

    #[Test]
    public function it_fails_when_discovery_does_not_find_the_model(): void
    {
        $model = (new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public static function compatibility(Compatibility $compatibility): Compatibility
            {
                return $compatibility;
            }
        })::class;

        $this->assertStringStartsWith(
            "Discovery does not find {$model}, so no successor event reaches it. Keep the class in the composer classmap, and run php artisan optimize after adding it.",
            $this->failureOf($model),
        );
    }

    /**
     * @param  class-string<Model&Compatible>  $model
     */
    private function failureOf(string $model): string
    {
        try {
            $this->assertServesCompatibility($model);
        } catch (PHPUnitException $exception) {
            return $exception->getMessage();
        }

        $this->fail("assertServesCompatibility({$model}) passed.");
    }

    private function install(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(Listing::class);
    }
}
