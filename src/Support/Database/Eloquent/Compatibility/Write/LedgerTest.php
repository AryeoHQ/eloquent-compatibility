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

#[CoversClass(Ledger::class)]
final class LedgerTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    private const string FIRST = '019a5e3c-8f00-7000-8000-000000000031';

    private const string SECOND = '019a5e3c-8f00-7000-8000-000000000032';

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
    public function it_types_the_ledger_by_the_legacy_key_and_the_root_key_and_indexes_only_its_refused_and_failed_rows(): void
    {
        $this->assertSame(
            [['column_name' => 'legacy_id', 'data_type' => 'integer'], ['column_name' => 'successor_id', 'data_type' => 'uuid'], ['column_name' => 'status', 'data_type' => 'text'], ['column_name' => 'reason', 'data_type' => 'text'], ['column_name' => 'attempts', 'data_type' => 'integer'], ['column_name' => 'attempted_at', 'data_type' => 'timestamp with time zone']],
            array_map(fn (object $row) => (array) $row, DB::select("select column_name, data_type from information_schema.columns where table_name = 'listings_backfill' order by ordinal_position")),
        );
        $this->assertSame(
            ['CREATE INDEX listings_backfill_status_index ON public.listings_backfill USING btree (status) WHERE (status = ANY (ARRAY[\'refused\'::text, \'failed\'::text]))'],
            array_column(DB::select("select indexdef from pg_indexes where tablename = 'listings_backfill' and indexname <> 'listings_backfill_pkey'"), 'indexdef'),
        );
        $this->assertSame([], DB::select("select conname from pg_constraint where conrelid = 'listings_backfill'::regclass and contype = 'f'"));
    }

    #[Test]
    public function it_upserts_one_outcome_per_legacy_row_counting_attempts_and_keeping_the_last_successor(): void
    {
        $ledger = $this->ledger();

        $ledger->record(key: 1, successor: self::FIRST, outcome: Outcome::Created, reason: null);
        $ledger->record(key: 1, successor: null, outcome: Outcome::Failed, reason: "lost\0 connection");
        $ledger->record(key: 2, successor: null, outcome: Outcome::Refused, reason: 'blank title');

        $this->assertSame(
            [['legacy_id' => 1, 'successor_id' => self::FIRST, 'status' => 'failed', 'reason' => 'lost connection', 'attempts' => 2], ['legacy_id' => 2, 'successor_id' => null, 'status' => 'refused', 'reason' => 'blank title', 'attempts' => 1]],
            array_map(fn (object $row) => (array) $row, DB::select('select legacy_id, successor_id, status, reason, attempts from listings_backfill order by legacy_id')),
        );
        $this->assertSame(['1' => Outcome::Failed, '2' => Outcome::Refused], $ledger->statuses([1, 2, 3]));
        $this->assertSame(['refused' => 1, 'failed' => 1], $ledger->counts());
    }

    #[Test]
    public function it_lists_each_ledger_row_whose_legacy_row_is_gone_unless_it_is_already_deleted(): void
    {
        $ledger = $this->ledger();
        $ledger->record(key: 1, successor: self::FIRST, outcome: Outcome::Created, reason: null);
        $ledger->record(key: 3, successor: self::SECOND, outcome: Outcome::Updated, reason: null);
        $ledger->record(key: 7, successor: null, outcome: Outcome::Deleted, reason: null);
        $ledger->record(key: 8, successor: null, outcome: Outcome::Refused, reason: 'blank title');
        DB::delete('delete from listings where id = 3');

        $this->assertSame([[3, self::SECOND, Outcome::Updated], [8, null, Outcome::Refused]], $ledger->gone());
    }

    #[Test]
    public function it_refuses_while_a_row_is_refused_or_failed_naming_each_reason_with_its_first_keys(): void
    {
        $ledger = $this->ledger();
        $ledger->refuseBlocked('install');

        foreach ([1, 2, 3, 4, 5, 6] as $key) {
            $ledger->record(key: $key, successor: null, outcome: Outcome::Refused, reason: 'blank title');
        }

        $ledger->record(key: 9, successor: null, outcome: Outcome::Failed, reason: 'lost connection');

        $this->expectExceptionObject(InvalidCompatibilityMap::cannot(
            map: 'listings',
            operation: 'install',
            because: '7 listings rows are refused or failed in listings_backfill, and no row installs before every row can be served: 6 refused because blank title (id 1, 2, 3, 4, 5); 1 failed because lost connection (id 9). Fix each row, run php artisan compatibility:backfill '.Listing::class.', then install again',
        ));

        $ledger->refuseBlocked('install');
    }

    private function ledger(): Ledger
    {
        return new Ledger(Compatibility::of(Listing::class)->resolve(), DB::connection());
    }
}
