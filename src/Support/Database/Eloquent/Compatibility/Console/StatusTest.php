<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\TestCase;

#[CoversClass(Status::class)]
final class StatusTest extends TestCase
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
        PlaceAssetTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_prints_each_stage_of_a_listing_from_the_catalog_and_the_ledger(): void
    {
        $stages = [$this->stage()];
        Compatibility::prepare(Listing::class);
        $stages[] = $this->stage();
        $this->artisan(
            command: 'compatibility:backfill',
            parameters: ['model' => Listing::class],
        )->assertSuccessful();
        $stages[] = $this->stage();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $stages[] = $this->stage();
        Compatibility::rollback(Listing::class);
        $stages[] = $this->stage();
        Compatibility::prepare(Listing::class);
        $stages[] = $this->stage();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        DB::statement('drop table listing_photos, listing_shares');
        Compatibility::retire(Listing::class);
        $stages[] = $this->stage();

        $this->assertSame(['discovered', 'prepared', 'backfilling', 'installed', 'rolled back', 'prepared', 'retired'], array_column(
            array: $stages,
            column_key: 0,
        ));
        $this->assertMatchesRegularExpression('/^pass 1, watermark \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ, 0 refused, 0 failed$/', $stages[2][1]);
    }

    #[Test]
    public function it_prints_every_discovered_model_when_no_model_is_named(): void
    {
        PlaceAssetTables::create();
        Compatibility::prepare(FloorPlan::class);

        Artisan::call('compatibility:status');
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/'.preg_quote(
            str: Listing::class,
            delimiter: '/',
        ).'\s*\|\s*listings\s*\|\s*discovered/', $output);
        $this->assertMatchesRegularExpression('/'.preg_quote(
            str: FloorPlan::class,
            delimiter: '/',
        ).'\s*\|\s*floorplans\s*\|\s*prepared/', $output);
    }

    #[Test]
    public function it_refuses_a_name_that_matches_no_served_model(): void
    {
        $this->artisan(
            command: 'compatibility:status',
            parameters: ['--model' => ['Nothing']],
        )
            ->expectsOutput('Nothing names no served model. Name the legacy model by its class, or by the class name of one served model.')
            ->assertFailed();
    }

    /**
     * @return array{string, string}
     */
    private function stage(): array
    {
        Artisan::call(
            command: 'compatibility:status',
            parameters: ['--model' => [Listing::class]],
        );

        foreach (explode(
            separator: "\n",
            string: Artisan::output(),
        ) as $line) {
            $cells = array_map(
                callback: trim(...),
                array: explode(
                    separator: '|',
                    string: $line,
                ),
            );

            if (($cells[1] ?? null) === Listing::class) {
                return [$cells[3], $cells[4]];
            }
        }

        $this->fail('compatibility:status printed no row for '.Listing::class.":\n".Artisan::output());
    }
}
