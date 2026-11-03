<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(CheckDrift::class)]
final class CheckDriftTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_says_so_and_exits_zero_when_the_served_model_has_not_drifted(): void
    {
        Compatibility::install(Listing::class);

        $this->artisan('compatibility:check', ['--model' => [Listing::class]])
            ->expectsOutputToContain('No drift in 1 served model.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_prints_each_finding_by_model_check_and_record_and_exits_non_zero(): void
    {
        DB::unprepared("insert into listings (title, company_id) values ('Ash Barn', '".ListingTables::COMPANY."')");
        DB::unprepared("update listings set title = 'Oak Home' where id = 1");

        $this->artisan('compatibility:check', ['--model' => [Listing::class]])
            ->expectsTable(['Model', 'Check', 'Record', 'Detail'], [
                [Listing::class, 'no primary', 'listings#4', 'no jobs row holds its id, so install() refuses it'],
                [Listing::class, 'values differ', 'listings#1', 'listings holds other values for title than the view would serve from jobs'],
            ])
            ->expectsOutputToContain('2 drift findings in 1 served model.')
            ->doesntExpectOutputToContain('No drift')
            ->assertFailed();
    }

    #[Test]
    public function it_finds_the_legacy_model_by_the_class_name_of_one_served_model(): void
    {
        $this->artisan('compatibility:check', ['--model' => ['Listing']])
            ->expectsOutputToContain('No drift in 1 served model.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_reports_at_most_the_limit_of_records_per_check_and_model(): void
    {
        DB::unprepared("insert into listings (title, company_id) values ('Ash Barn', '".ListingTables::COMPANY."'), ('Birch Loft', '".ListingTables::COMPANY."')");

        $this->artisan('compatibility:check', ['--model' => [Listing::class], '--limit' => '1'])
            ->expectsTable(['Model', 'Check', 'Record', 'Detail'], [
                [Listing::class, 'no primary', 'listings#4', 'no jobs row holds its id, so install() refuses it'],
            ])
            ->expectsOutputToContain('1 drift finding in 1 served model.')
            ->assertFailed();
    }

    #[Test]
    public function it_checks_every_served_model_without_model_and_names_each_map_it_cannot_check(): void
    {
        $this->artisan('compatibility:check')
            ->expectsOutputToContain("Compatibility map 'photos' cannot check: photos is neither a table nor a view.")
            ->expectsOutputToContain("Compatibility map 'place_images' cannot check: place_images is neither a table nor a view.")
            ->doesntExpectOutputToContain("Compatibility map 'listings'")
            ->doesntExpectOutputToContain('No drift')
            ->assertFailed();
    }

    /**
     * @param  array<string, list<string>|string>  $options
     */
    #[Test]
    #[TestWith([['--model' => ['Lisitng']], 'Lisitng names no served model. Name the legacy model by its class, or by the class name of one served model.'])]
    #[TestWith([['--model' => ['Video']], 'Video names more than one served model: Tests\Fixtures\Support\Files\Video, Tests\Fixtures\Support\PlaceAssets\Video. Name the legacy model by its class.'])]
    #[TestWith([['--limit' => '0'], '--limit takes a whole number of records above 0, not 0.'])]
    #[TestWith([['--limit' => 'all'], '--limit takes a whole number of records above 0, not all.'])]
    public function it_exits_non_zero_with_the_reason_it_cannot_check(array $options, string $refusal): void
    {
        $this->artisan('compatibility:check', $options)
            ->expectsOutputToContain($refusal)
            ->doesntExpectOutputToContain('No drift')
            ->assertFailed();
    }
}
