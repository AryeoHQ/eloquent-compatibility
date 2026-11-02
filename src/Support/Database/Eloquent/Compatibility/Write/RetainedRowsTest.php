<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

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
        Compatibility::install(Listing::class);
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_inserts_a_retained_row_the_first_time_a_record_writes_a_legacy_only_fact(): void
    {
        $job = Job::query()->create(['name' => 'Cedar Barn', 'company_id' => ListingTables::COMPANY]);
        $listing = Listing::query()->findOrFail($job->refresh()->legacy_id);
        $unretained = DB::table('listings_retained')->where('job_id', $job->id)->count();

        $listing->update(['mls_number' => 'MLS-4']);

        $this->assertSame(0, $unretained);
        $this->assertSame([['MLS-4', false]], $this->retained($job->id));
    }

    #[Test]
    public function it_changes_only_the_legacy_only_facts_a_write_changes(): void
    {
        $listing = Listing::query()->findOrFail(1);
        $job = Job::query()->where('legacy_id', 1)->firstOrFail();

        $listing->update(['featured' => false]);
        $unfeatured = $this->retained($job->id);
        $listing->update(['mls_number' => 'MLS-1C']);

        $this->assertSame([['MLS-1', false]], $unfeatured);
        $this->assertSame([['MLS-1C', false]], $this->retained($job->id));
    }

    #[Test]
    public function it_writes_no_retained_row_for_a_write_that_changes_no_legacy_only_fact(): void
    {
        $job = Job::query()->create(['name' => 'Cedar Barn', 'company_id' => ListingTables::COMPANY]);
        $listing = Listing::query()->findOrFail($job->refresh()->legacy_id);

        $listing->update(['title' => 'Cedar Hall']);

        $this->assertSame('Cedar Hall', $job->refresh()->name);
        $this->assertSame([], $this->retained($job->id));
    }

    /**
     * @return list<list<mixed>>
     */
    private function retained(string $jobId): array
    {
        return DB::table('listings_retained')->where('job_id', $jobId)->get(['mls_number', 'featured'])->map(fn (object $row) => array_values((array) $row))->all();
    }
}
