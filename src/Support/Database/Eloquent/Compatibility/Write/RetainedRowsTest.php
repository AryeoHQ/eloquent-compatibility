<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(RetainedRows::class)]
final class RetainedRowsTest extends TestCase
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
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_inserts_the_retained_row_that_holds_a_created_records_identity(): void
    {
        $listing = Listing::query()->create(['title' => 'Cedar Barn', 'company_id' => ListingTables::COMPANY, 'mls_number' => 'MLS-4']);
        $job = Job::query()->where(column: 'name', operator: '=', value: 'Cedar Barn')->firstOrFail();

        $this->assertSame([4, $job->id], [$listing->getKey(), ListingTables::jobOf(4)]);
        $this->assertSame([['MLS-4', false]], $this->retained($job->id));
    }

    #[Test]
    public function it_changes_only_the_legacy_only_facts_a_write_changes(): void
    {
        $listing = Listing::query()->findOrFail(1);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();

        $listing->update(['featured' => false]);
        $unfeatured = $this->retained($job->id);
        $listing->update(['mls_number' => 'MLS-1C']);

        $this->assertSame([['MLS-1', false]], $unfeatured);
        $this->assertSame([['MLS-1C', false]], $this->retained($job->id));
    }

    #[Test]
    public function it_leaves_the_retained_row_for_a_write_that_changes_no_legacy_only_fact(): void
    {
        $listing = Listing::query()->findOrFail(1);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();

        $listing->update(['title' => 'Cedar Hall']);

        $this->assertSame('Cedar Hall', $job->refresh()->name);
        $this->assertSame([['MLS-1', true]], $this->retained($job->id));
    }

    /**
     * @return list<list<mixed>>
     */
    private function retained(string $jobId): array
    {
        return DB::table('listings_retained')->where('job_id', $jobId)->get(['mls_number', 'featured'])->map(fn (object $row) => array_values((array) $row))->all();
    }
}
