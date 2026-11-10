<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(Watermark::class)]
final class WatermarkTest extends TestCase
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
        DB::disconnect('second');
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_tracks_one_row_per_legacy_model_with_no_watermark_until_a_pass_finishes(): void
    {
        Compatibility::prepare(Listing::class);
        Compatibility::prepare(Listing::class);

        $this->assertSame([['legacy_type' => 'listings', 'watermark_at' => null, 'pass' => 0, 'pass_started_at' => null, 'pass_finished_at' => null, 'pass_rows' => null]], array_map(fn (object $row) => (array) $row, DB::select("select * from compatibility_backfills where legacy_type = 'listings'")));
        $this->assertSame([true, null, 0], [$this->watermark()->tracked(), $this->watermark()->remembered(), $this->watermark()->pass()]);
    }

    #[Test]
    public function it_counts_a_pass_when_it_starts_and_records_its_start_as_the_watermark_when_it_finishes(): void
    {
        Compatibility::prepare(Listing::class);
        $watermark = $this->watermark();

        $started = $watermark->start();
        $running = (array) DB::selectOne("select pass, pass_finished_at is null as running, watermark_at is null as unmarked from compatibility_backfills where legacy_type = 'listings'");
        $watermark->finish(
            started: $started,
            rows: 3,
        );

        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $started);
        $this->assertSame(['pass' => 1, 'running' => true, 'unmarked' => true], $running);
        $this->assertSame([$started, substr($started, 0, 19).'Z'], [$watermark->bound(), $watermark->remembered()]);
        $this->assertSame(['pass' => 1, 'pass_rows' => 3, 'finished' => true], (array) DB::selectOne("select pass, pass_rows, pass_finished_at is not null as finished from compatibility_backfills where legacy_type = 'listings'"));
    }

    #[Test]
    public function it_refuses_to_start_a_pass_for_a_legacy_model_prepare_has_not_tracked(): void
    {
        Compatibility::prepare(Listing::class);
        DB::delete("delete from compatibility_backfills where legacy_type = 'listings'");

        $this->expectExceptionObject(InvalidCompatibilityMap::cannot(
            map: 'listings',
            operation: 'backfill',
            because: 'compatibility_backfills holds no row for listings, which Compatibility::prepare() inserts; run Compatibility::prepare() first',
        ));

        $this->watermark()->start();
    }

    #[Test]
    public function it_lets_one_session_at_a_time_hold_the_advisory_lock_of_a_legacy_model(): void
    {
        config(['database.connections.second' => config('database.connections.'.DB::getDefaultConnection())]);
        $second = new Watermark('listings', DB::connection('second'));
        $this->watermark()->hold('backfill');

        try {
            $second->hold('install');
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->watermark()->release();
        $second->hold('install');
        $second->release();

        $this->assertSame("Compatibility map 'listings' cannot install: another backfill pass or install of listings holds its advisory lock, and each legacy model runs one pass at a time; wait for it to finish, then install again.", $refusal);
    }

    #[Test]
    public function it_forgets_only_its_own_legacy_model_and_tolerates_a_missing_table(): void
    {
        DB::unprepared(Watermark::forgetStatement('listings'));
        Compatibility::prepare(Listing::class);
        DB::insert("insert into compatibility_backfills (legacy_type) values ('other_listings')");

        DB::unprepared(Watermark::forgetStatement('listings'));

        $this->assertSame(['other_listings'], array_column(DB::select('select legacy_type from compatibility_backfills order by legacy_type'), 'legacy_type'));
        DB::delete("delete from compatibility_backfills where legacy_type = 'other_listings'");
    }

    private function watermark(): Watermark
    {
        return new Watermark('listings', DB::connection());
    }
}
