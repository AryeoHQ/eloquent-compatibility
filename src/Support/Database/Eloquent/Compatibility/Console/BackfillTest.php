<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(Backfill::class)]
final class BackfillTest extends TestCase
{
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
        $this->assertSame('Pine Loft', DB::scalar('select name from jobs where legacy_id = 2'));
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
    public function it_refuses_to_backfill_once_the_legacy_table_is_the_view(): void
    {
        Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        Compatibility::install(Listing::class);

        $this->artisan('compatibility:backfill', ['model' => Listing::class])
            ->expectsOutputToContain("Compatibility map 'listings' cannot backfill: listings is already installed as a view, and the backfill walks the legacy table while it is still a table.")
            ->assertFailed();
    }
}
