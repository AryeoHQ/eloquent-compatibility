<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Discovery\InheritedServed;
use Tests\Fixtures\Support\Discovery\ResolvableServedModels;
use Tests\Fixtures\Support\Discovery\TraitServed;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
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
        $this->app->instance(ServedModels::class, ResolvableServedModels::of($this->app->make(ServedModels::class)));
    }

    protected function tearDown(): void
    {
        DB::statement('set session characteristics as transaction read write');
        FileTables::drop();
        ListingTables::drop();
        PlaceAssetTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_says_so_and_exits_zero_when_the_served_model_has_not_drifted(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

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
                [Listing::class, 'no primary', 'listings#4', 'its job_id names no jobs row, so install() refuses it'],
                [Listing::class, 'values differ', 'listings#1', 'listings holds other values for title than the view would serve from jobs'],
            ])
            ->expectsOutputToContain('2 drift findings in 1 served model.')
            ->doesntExpectOutputToContain('No drift')
            ->assertFailed();
    }

    #[Test]
    public function it_prints_the_findings_of_trashed_records_in_a_table_of_their_own(): void
    {
        DB::unprepared("update listings set title = 'Oak Home' where id = 1");
        DB::unprepared("update listings set title = 'Pine Loft' where id = 2");

        $this->artisan('compatibility:check', ['--model' => [Listing::class]])
            ->expectsTable(['Model', 'Check', 'Record', 'Detail'], [
                [Listing::class, 'values differ', 'listings#1', 'listings holds other values for title than the view would serve from jobs'],
            ])
            ->expectsOutput('Trashed records:')
            ->expectsTable(['Model', 'Check', 'Record', 'Detail'], [
                [Listing::class, 'values differ', 'listings#2', 'listings holds other values for title than the view would serve from jobs'],
            ])
            ->expectsOutputToContain('2 drift findings in 1 served model.')
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
                [Listing::class, 'no primary', 'listings#4', 'its job_id names no jobs row, so install() refuses it'],
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

    #[Test]
    public function it_prints_the_findings_of_each_model_it_checked_and_names_each_model_whose_check_failed(): void
    {
        DB::unprepared("insert into listings (title, company_id) values ('Ash Barn', '".ListingTables::COMPANY."')");
        $refusing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->guard('unreadable', fn () => throw new RuntimeException('The guard reads a service that is down.'), because: 'It reads a service.', instead: 'Wait for the service.')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        ));
            }
        };

        $this->artisan('compatibility:check', ['--model' => [$refusing::class, Listing::class]])
            ->expectsTable(['Model', 'Check', 'Record', 'Detail'], [
                [Listing::class, 'no primary', 'listings#4', 'its job_id names no jobs row, so install() refuses it'],
            ])
            ->expectsOutputToContain('1 drift finding in 2 served models.')
            ->expectsOutputToContain($refusing::class.': The guard reads a service that is down.')
            ->doesntExpectOutputToContain('No drift')
            ->assertFailed();
    }

    #[Test]
    public function it_names_the_writable_connection_with_the_temp_privilege_that_the_stale_view_check_needs(): void
    {
        DB::unprepared("insert into listings (title, company_id) values ('Ash Barn', '".ListingTables::COMPANY."')");
        $this->installPhotos();
        DB::statement('set session characteristics as transaction read only');

        $this->artisan('compatibility:check', ['--model' => [Listing::class, Photo::class]])
            ->expectsTable(['Model', 'Check', 'Record', 'Detail'], [
                [Listing::class, 'no primary', 'listings#4', 'its job_id names no jobs row, so install() refuses it'],
            ])
            ->expectsOutputToContain('1 drift finding in 2 served models.')
            ->expectsOutputToContain(Photo::class.": Compatibility map 'photos' cannot check: the stale-view check creates a temporary view, so it needs a writable connection with the TEMP privilege, and the connection refused it with SQLSTATE 25006.")
            ->doesntExpectOutputToContain('No drift')
            ->assertFailed();
    }

    #[Test]
    public function it_names_a_map_that_fails_to_resolve_and_still_checks_the_other_models(): void
    {
        DB::unprepared("insert into listings (title, company_id) values ('Ash Barn', '".ListingTables::COMPANY."')");
        $unresolved = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing;
            }
        };

        $this->artisan('compatibility:check', ['--model' => [$unresolved::class, Listing::class]])
            ->expectsTable(['Model', 'Check', 'Record', 'Detail'], [
                [Listing::class, 'no primary', 'listings#4', 'its job_id names no jobs row, so install() refuses it'],
            ])
            ->expectsOutputToContain($unresolved::class.": Compatibility map 'listings' is declared wrongly: it says no servedFrom(), so it names no successor.")
            ->doesntExpectOutputToContain('No drift')
            ->assertFailed();
    }

    #[Test]
    public function it_names_each_sibling_map_that_fails_to_resolve_and_exits_non_zero_although_the_model_it_checks_has_not_drifted(): void
    {
        $this->app->forgetInstance(ServedModels::class);

        $this->artisan(
            command: 'compatibility:check',
            parameters: ['--model' => [Listing::class]],
        )
            ->expectsOutputToContain(InheritedServed::class.": Compatibility map 'inherited_serveds' is declared wrongly: it says no servedFrom(), so it names no successor. The check of every map that shares its successors is incomplete.")
            ->expectsOutputToContain(TraitServed::class.": Compatibility map 'trait_serveds' is declared wrongly: it says no servedFrom(), so it names no successor. The check of every map that shares its successors is incomplete.")
            ->doesntExpectOutputToContain('No drift')
            ->assertFailed();
    }

    #[Test]
    public function it_exits_non_zero_on_a_record_whose_root_left_the_when_of_its_map(): void
    {
        DB::unprepared("insert into listings (title, company_id) values ('Ash Barn', '".ListingTables::COMPANY."')");
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        PlaceAssetTables::install();
        DB::update("update place_assets set legacy_type = 'place_video' where id = (select place_asset_id from place_images where id = 2)");

        $this->artisan(
            command: 'compatibility:check',
            parameters: ['--model' => [Listing::class, Image::class]],
        )
            ->expectsTable(['Model', 'Check', 'Record', 'Detail'], [
                [Listing::class, 'no primary', 'listings#4', 'its job_id names no jobs row, so install() refuses it'],
                [Image::class, 'outside when()', 'place_images#2', 'its place_assets row no longer matches the when() of Image'],
            ])
            ->expectsOutputToContain('2 drift findings in 2 served models.')
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

    private function installPhotos(): void
    {
        FileTables::create();
        FileTables::seed();

        foreach (FileTables::MODELS as $model) {
            Compatibility::prepare($model);
        }

        FileTables::backfill();
        Compatibility::install(
            model: Photo::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
    }
}
