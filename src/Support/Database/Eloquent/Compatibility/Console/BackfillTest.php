<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Carbon\CarbonInterval;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(Backfill::class)]
final class BackfillTest extends TestCase
{
    public static string $reporting = '';

    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_prints_what_each_chunk_wrote_and_the_watermark_a_catch_up_run_starts_from(): void
    {
        $exit = Artisan::call('compatibility:backfill', ['model' => Listing::class, '--chunk' => '2']);
        $printed = explode("\n", trim(Artisan::output()));

        $this->assertSame(0, $exit);
        $this->assertSame([
            'Wrote 4 successor rows for listings 1 to 2.',
            'Wrote 3 successor rows for listings 3 to 3.',
            'Backfilled 3 listings rows, which changed 7 successor rows.',
        ], array_slice($printed, 0, 3));
        $this->assertMatchesRegularExpression('/^Watermark: (\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ)$/', $printed[3]);
        $this->assertSame('Catch up with: php artisan compatibility:backfill '.Listing::class.' --since='.substr($printed[3], 11), $printed[4]);
        $this->assertSame(3, DB::scalar('select count(*) from jobs'));
    }

    #[Test]
    public function it_catches_up_from_the_watermark_it_printed(): void
    {
        Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        $since = substr(explode("\n", trim(Artisan::output()))[2], 11);
        DB::update("update listings set title = 'Pine Loft', updated_at = localtimestamp(0) where id = 2");

        $exit = Artisan::call('compatibility:backfill', ['model' => Listing::class, '--since' => $since]);

        $this->assertSame([0, 'Backfilled 1 listings rows, which changed 1 successor rows.'], [$exit, explode("\n", trim(Artisan::output()))[1]]);
        $this->assertSame('Pine Loft', DB::table('jobs')->where(column: 'id', operator: '=', value: ListingTables::jobOf(2))->value('name'));
    }

    #[Test]
    public function it_finds_the_legacy_model_by_the_class_name_of_a_served_model(): void
    {
        $this->artisan('compatibility:backfill', ['model' => 'Listing'])
            ->expectsOutputToContain('Backfilled 3 listings rows, which changed 7 successor rows.')
            ->assertSuccessful();
    }

    /**
     * @param  array<string, string>  $arguments
     */
    #[Test]
    #[TestWith([['model' => 'Lisitng'], 'Lisitng names no served model. Name the legacy model by its class, or by the class name of one served model.'])]
    #[TestWith([['model' => Listing::class, '--chunk' => '0'], '--chunk takes a whole number of rows above 0, not 0.'])]
    #[TestWith([['model' => Listing::class, '--since' => 'the last run'], '--since takes the watermark a backfill printed, such as 2026-11-02T13:00:00Z, not the last run.'])]
    public function it_exits_non_zero_with_the_reason_it_cannot_backfill(array $arguments, string $refusal): void
    {
        $this->artisan('compatibility:backfill', $arguments)
            ->expectsOutputToContain($refusal)
            ->doesntExpectOutputToContain('Watermark:')
            ->assertFailed();

        $this->assertSame(0, DB::scalar('select count(*) from jobs'));
    }

    #[Test]
    public function it_refuses_a_since_later_than_the_watermark_the_last_backfill_remembers(): void
    {
        Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        $remembered = substr(explode("\n", trim(Artisan::output()))[2], 11);
        $later = (new DateTimeImmutable($remembered))->modify('+1 hour')->format('Y-m-d\TH:i:s\Z');

        $this->artisan('compatibility:backfill', ['model' => Listing::class, '--since' => $later])
            ->expectsOutputToContain("--since takes a watermark no later than {$remembered}, which the last backfill of listings remembers, not {$later}; a later one skips the legacy rows updated in between.")
            ->doesntExpectOutputToContain('Watermark:')
            ->assertFailed();
    }

    #[Test]
    public function it_refuses_to_backfill_over_a_connection_with_a_table_prefix_and_writes_nothing(): void
    {
        config(['database.connections.compatibility_prefixed' => [...(array) config('database.connections.pgsql'), 'prefix' => 'app_'], 'database.default' => 'compatibility_prefixed']);

        try {
            $this->artisan('compatibility:backfill', ['model' => Listing::class])
                ->expectsOutputToContain("Compatibility map 'listings' is declared wrongly: Listing reads the connection compatibility_prefixed, which adds the table prefix app_, and the package reads and compiles every table by its unprefixed name; use a connection without a prefix.")
                ->doesntExpectOutputToContain('Watermark:')
                ->assertFailed();
        } finally {
            DB::purge('compatibility_prefixed');
            config(['database.default' => 'pgsql']);
        }

        $this->assertSame(0, DB::scalar('select count(*) from jobs'));
    }

    #[Test]
    public function it_refuses_to_backfill_a_successor_whose_read_configuration_reaches_another_search_path_and_writes_nothing(): void
    {
        config(['database.connections.compatibility_reporting' => [...(array) config('database.connections.pgsql'), 'read' => ['search_path' => 'reporting'], 'write' => []]]);
        $reporting = new class extends Model
        {
            protected $connection = 'compatibility_reporting';

            protected $table = 'jobs';

            protected $guarded = [];
        };
        self::$reporting = $reporting::class;
        $legacy = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: BackfillTest::$reporting, key: 'successor_id', successor: fn (Successor $job) => $job
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

        $this->artisan('compatibility:backfill', ['model' => $legacy::class])
            ->expectsOutputToContain(' reads the connection compatibility_reporting, whose read configuration reaches another database or search_path than the connection pgsql of ')
            ->doesntExpectOutputToContain('Watermark:')
            ->assertFailed();

        $this->assertSame(0, DB::scalar('select count(*) from jobs'));
    }

    #[Test]
    public function it_refuses_to_backfill_once_the_legacy_table_is_the_view(): void
    {
        Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->artisan('compatibility:backfill', ['model' => Listing::class])
            ->expectsOutputToContain("Compatibility map 'listings' cannot backfill: listings is already installed as a view, and the backfill walks the legacy table while it is still a table.")
            ->assertFailed();
    }
}
