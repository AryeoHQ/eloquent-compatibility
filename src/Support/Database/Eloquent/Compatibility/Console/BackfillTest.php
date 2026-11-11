<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\LeaveTitleUnchanged;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\RefuseWithCutTitle;
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
    public function it_prints_the_rows_it_walked_by_status_and_the_watermark_it_records(): void
    {
        $exit = Artisan::call('compatibility:backfill', ['model' => Listing::class, '--chunk' => '2']);
        $printed = explode("\n", trim(Artisan::output()));

        $this->assertSame(0, $exit);
        $this->assertSame([
            'Wrote listings 1 to 2: 2 created, 0 updated, 0 unchanged, 0 refused, 0 failed, 0 deleted.',
            'Wrote listings 3 to 3: 1 created, 0 updated, 0 unchanged, 0 refused, 0 failed, 0 deleted.',
            'Walked 3 listings rows and 0 whose legacy row is gone: 3 created, 0 updated, 0 unchanged, 0 refused, 0 failed, 0 deleted.',
        ], array_slice(
            array: $printed,
            offset: 0,
            length: 3,
        ));
        $this->assertMatchesRegularExpression('/^Watermark: \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $printed[3]);
        $this->assertCount(4, $printed);
        $this->assertSame(3, DB::scalar('select count(*) from jobs'));
    }

    #[Test]
    public function it_walks_on_its_next_run_only_the_rows_updated_since_the_watermark(): void
    {
        Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        DB::update("update listings set title = 'Pine Loft', updated_at = localtimestamp(0) where id = 2");

        $exit = Artisan::call('compatibility:backfill', ['model' => Listing::class]);

        $this->assertSame([0, 'Walked 1 listings rows and 0 whose legacy row is gone: 0 created, 1 updated, 0 unchanged, 0 refused, 0 failed, 0 deleted.'], [$exit, explode("\n", trim(Artisan::output()))[1]]);
        $this->assertSame('Pine Loft', DB::table('jobs')->where(column: 'id', operator: '=', value: ListingTables::jobOf(2))->value('name'));
    }

    #[Test]
    public function it_counts_the_ledger_rows_whose_legacy_row_is_gone_beside_the_rows_it_walked(): void
    {
        Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        DB::delete('delete from listings where id = 3');

        $exit = Artisan::call('compatibility:backfill', ['model' => Listing::class]);

        $this->assertSame([0, 'Walked 0 listings rows and 1 whose legacy row is gone: 0 created, 0 updated, 0 unchanged, 0 refused, 0 failed, 1 deleted.'], [$exit, explode("\n", trim(Artisan::output()))[0]]);
    }

    #[Test]
    public function it_reads_the_ledger_rows_whose_legacy_row_is_gone_one_chunk_at_a_time_after_the_last_key_it_read(): void
    {
        Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        DB::delete('delete from listings');
        $reads = [];
        DB::listen(function (QueryExecuted $query) use (&$reads): void {
            if (str_starts_with($query->sql, 'SELECT ledger.legacy_id, ledger.successor_id, ledger.status FROM')) {
                $reads[] = $query->bindings;
            }
        });

        $exit = Artisan::call('compatibility:backfill', ['model' => Listing::class, '--chunk' => '2']);

        $this->assertSame([0, 'Walked 0 listings rows and 3 whose legacy row is gone: 0 created, 0 updated, 0 unchanged, 0 refused, 0 failed, 3 deleted.'], [$exit, explode("\n", trim(Artisan::output()))[0]]);
        $this->assertSame([[2], [2, 2, 2]], $reads);
    }

    #[Test]
    public function it_reads_each_row_it_creates_once_through_the_select_it_serves_and_compares_by(): void
    {
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads): void {
            if (str_contains($query->sql, "FROM (SELECT\n")) {
                $reads++;
            }
        });

        $exit = Artisan::call('compatibility:backfill', ['model' => Listing::class]);

        $this->assertSame([0, 'Walked 3 listings rows and 0 whose legacy row is gone: 3 created, 0 updated, 0 unchanged, 0 refused, 0 failed, 0 deleted.', 3], [$exit, explode("\n", trim(Artisan::output()))[1], $reads]);
    }

    #[Test]
    public function it_records_a_row_a_session_statement_timeout_cancels_as_failed_and_writes_the_rows_after_it(): void
    {
        DB::unprepared("create function slow_pine_job() returns trigger language plpgsql as \$\$ begin if new.name = 'Pine Flat' then perform pg_sleep(2); end if; return new; end \$\$; create trigger slow_pine_job before insert on jobs for each row execute function slow_pine_job()");
        DB::statement("set statement_timeout = '500ms'");

        try {
            $exit = Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        } finally {
            DB::statement('reset statement_timeout');
            DB::unprepared('drop function slow_pine_job() cascade');
        }

        $this->assertSame([1, [[1, 'created', false], [2, 'failed', true], [3, 'created', false]]], [$exit, array_map(
            callback: fn (object $row) => [$row->legacy_id, $row->status, str_starts_with(
                haystack: (string) $row->reason,
                needle: 'Illuminate\Database\QueryException: SQLSTATE[57014]: Query canceled',
            )],
            array: DB::select('select legacy_id, status, reason from listings_backfill order by legacy_id'),
        )]);
    }

    #[Test]
    public function it_prints_and_install_names_the_rows_one_package_refusal_refuses_under_one_reason_without_their_keys(): void
    {
        Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        DB::update("update listings set title = title || ' Revised', updated_at = localtimestamp(0) where id in (1, 2)");
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name')->writtenBy(LeaveTitleUnchanged::class),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        ));
            }
        };
        $reason = 'records cannot take this write of $title. Why: The write stores a value, and the view now serves title as another, so the record would not read back what was written. Instead: Make the operation of title store a value its read serves back.';
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            if ($message->level === 'info' && str_starts_with($message->message, "Compatibility map 'listings' backfill: the write of listings id ")) {
                $logged[] = $message->message;
            }
        });

        $exit = Artisan::call('compatibility:backfill', ['model' => $listing::class]);
        $printed = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertSame([[1, true], [2, true]], array_map(
            callback: fn (string $line): array => [(int) substr(
                string: $line,
                offset: strlen("Compatibility map 'listings' backfill: the write of listings id "),
                length: 1,
            ), preg_match(
                pattern: '/^Compatibility map \'listings\' backfill: the write of listings id (\d) was refused: .+ record \1 cannot take this write of \$title\. Why: The write stores \'[A-Za-z]+ [A-Za-z]+ Revised\', and the view now serves title as /',
                subject: $line,
            ) === 1],
            array: $logged,
        ));
        $this->assertStringContainsString('2 refused: Illuminate\\Database\\Eloquent\\Model@anonymous', $printed);
        $this->assertStringContainsString(" {$reason} (id 1, 2)", $printed);
        $this->assertSame([['refused', 2, true]], array_map(
            callback: fn (object $row) => [$row->status, $row->rows, str_ends_with(
                haystack: (string) $row->reason,
                needle: $reason,
            )],
            array: DB::select("select status, reason, count(*) as rows from listings_backfill where status in ('refused', 'failed') group by status, reason"),
        ));

        try {
            Compatibility::install(
                model: $listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $this->fail('install() did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertStringContainsString('2 listings rows are refused or failed in listings_backfill, and no row installs before every row can be served: 2 refused because Illuminate\\Database\\Eloquent\\Model@anonymous', $exception->getMessage());
            $this->assertStringContainsString(" {$reason} (id 1, 2). Fix each row", $exception->getMessage());
        }
    }

    #[Test]
    public function it_looks_back_from_the_watermark_by_the_margin_it_takes_in_seconds(): void
    {
        Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        DB::update("update listings set title = 'Pine Loft', updated_at = (select watermark_at at time zone 'UTC' from compatibility_backfills where legacy_type = 'listings') - interval '120 seconds' where id = 2");

        Artisan::call('compatibility:backfill', ['model' => Listing::class, '--margin' => '60']);
        $narrow = explode("\n", trim(Artisan::output()))[0];
        Artisan::call('compatibility:backfill', ['model' => Listing::class]);
        $wide = explode("\n", trim(Artisan::output()))[1];

        $this->assertSame(['Walked 0 listings rows', 'Walked 1 listings rows'], [substr($narrow, 0, 22), substr($wide, 0, 22)]);
    }

    #[Test]
    public function it_prints_each_refused_row_by_reason_and_exits_non_zero(): void
    {
        DB::update("update listings set status = 'Archived' where id = 3");

        $this->artisan('compatibility:backfill', ['model' => Listing::class])
            ->expectsOutput('Wrote listings 1 to 3: 2 created, 0 updated, 0 unchanged, 1 refused, 0 failed, 0 deleted.')
            ->expectsOutput('Walked 3 listings rows and 0 whose legacy row is gone: 2 created, 0 updated, 0 unchanged, 1 refused, 0 failed, 0 deleted.')
            ->expectsOutput("1 refused: A listing status is Draft, Active or Sold, and 'Archived' is none of them. (id 3)")
            ->expectsOutputToContain("listings has refused or failed rows, which listings_backfill records with their reasons, and install() refuses until a pass writes them. Fix each row, then run php artisan compatibility:backfill 'Tests\\Fixtures\\Support\\Listings\\Listing' again.")
            ->assertFailed();

        $this->assertSame(2, DB::scalar('select count(*) from jobs'));
    }

    #[Test]
    public function it_refuses_to_backfill_without_its_ledger_and_names_prepare_before_it_counts_a_pass(): void
    {
        DB::statement('drop table listings_backfill');

        $this->artisan('compatibility:backfill', ['model' => Listing::class])
            ->expectsOutputToContain("Compatibility map 'listings' cannot backfill: Compatibility::prepare() adds listings.job_id, creates listings_backfill and inserts the compatibility_backfills row of listings, and it has not run; run Compatibility::prepare() first.")
            ->assertFailed();

        $this->assertSame(0, DB::scalar("select pass from compatibility_backfills where legacy_type = 'listings'"));
    }

    #[Test]
    public function it_prints_the_markup_and_control_characters_of_a_reason_as_text(): void
    {
        DB::update('update listings set status = ? where id = 3', ["<href=https://example.invalid>Archived</>\e[31m\u{9B}2J\u{9D}52;c;aGk=\u{9C}"]);

        $this->artisan('compatibility:backfill', ['model' => Listing::class])
            ->expectsOutput("1 refused: A listing status is Draft, Active or Sold, and '<href=https://example.invalid>Archived</>\\x1B[31m\\x9B2J\\x9D52;c;aGk=\\x9C' is none of them. (id 3)")
            ->assertFailed();
    }

    #[Test]
    public function it_records_a_failed_row_s_data_exception_by_its_sqlstate_and_condition_without_the_rejected_value(): void
    {
        DB::statement('alter table jobs alter column name type integer using 0');
        DB::update("update listings set title = 'SECRET-' || id");

        $this->artisan('compatibility:backfill', ['model' => Listing::class])
            ->expectsOutput('3 failed: Illuminate\Database\QueryException: SQLSTATE[22P02]: Invalid text representation (id 1, 2, 3)')
            ->doesntExpectOutputToContain('SECRET')
            ->assertFailed();

        $this->assertSame([0, 3], [DB::scalar("select count(*) from listings_backfill where reason like '%SECRET%'"), DB::scalar("select count(*) from listings_backfill where status = 'failed'")]);
    }

    #[Test]
    public function it_records_a_failed_row_by_its_exception_class_and_first_line_without_its_values_and_logs_the_exception(): void
    {
        DB::statement("alter table jobs add constraint jobs_name_is_not_secret check (name not like 'SECRET%')");
        DB::update("update listings set title = 'SECRET-' || id where id in (1, 3)");
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = [$message->level, $message->message, ($message->context['exception'] ?? null) instanceof QueryException];
        });

        $this->artisan('compatibility:backfill', ['model' => Listing::class])
            ->expectsOutput('2 failed: Illuminate\Database\QueryException: SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation "jobs" violates check constraint "jobs_name_is_not_secret" (id 1, 3)')
            ->assertFailed();

        $this->assertSame([0, 1], [DB::scalar("select count(*) from listings_backfill where reason like '%SECRET%'"), DB::scalar("select count(distinct reason) from listings_backfill where status = 'failed'")]);
        $this->assertSame([
            ['warning', "Compatibility map 'listings' backfill: the write of listings id 1 failed", true],
            ['warning', "Compatibility map 'listings' backfill: the write of listings id 3 failed", true],
        ], $logged);
    }

    #[Test]
    public function it_prints_and_install_names_the_ten_largest_reasons_and_counts_the_others(): void
    {
        DB::statement("insert into listings (title, company_id, status) select 'Barn ' || n, ?, 'Archived ' || n from generate_series(1, 12) as n", [ListingTables::COMPANY]);
        $this->artisan('compatibility:backfill', ['model' => Listing::class])
            ->expectsOutput('And 2 more reasons in listings_backfill.')
            ->assertFailed();

        try {
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $this->fail('Install took its lock with refused rows.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertSame([10, true], [substr_count(
                haystack: $exception->getMessage(),
                needle: ' refused because ',
            ), str_contains(
                haystack: $exception->getMessage(),
                needle: '; and 2 more reasons. Fix each row',
            )]);
        }
    }

    #[Test]
    public function it_records_a_reason_cut_inside_a_character_as_valid_text_and_writes_the_other_rows(): void
    {
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name')->writtenBy(RefuseWithCutTitle::class),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        ));
            }
        };
        DB::update("update listings set title = 'Élan' where id = 2");

        $this->artisan('compatibility:backfill', ['model' => $listing::class])->assertFailed();

        $this->assertSame([['refused', 'The title ? is cut.'], ['refused', 'The title O is cut.']], array_map(
            callback: fn (object $row) => [$row->status, $row->reason],
            array: DB::select('select status, reason from listings_backfill where legacy_id in (1, 2) order by legacy_id desc'),
        ));
    }

    #[Test]
    public function it_finds_the_legacy_model_by_the_class_name_of_a_served_model(): void
    {
        $this->artisan('compatibility:backfill', ['model' => 'Listing'])
            ->expectsOutputToContain('Walked 3 listings rows and 0 whose legacy row is gone: 3 created')
            ->assertSuccessful();
    }

    /**
     * @param  array<string, string>  $arguments
     */
    #[Test]
    #[TestWith([['model' => 'Lisitng'], 'Lisitng names no served model. Name the legacy model by its class, or by the class name of one served model.'])]
    #[TestWith([['model' => Listing::class, '--chunk' => '0'], '--chunk takes a whole number of rows above 0, not 0.'])]
    #[TestWith([['model' => Listing::class, '--margin' => 'soon'], '--margin takes a whole number of seconds, not soon.'])]
    public function it_exits_non_zero_with_the_reason_it_cannot_backfill(array $arguments, string $refusal): void
    {
        $this->artisan('compatibility:backfill', $arguments)
            ->expectsOutputToContain($refusal)
            ->doesntExpectOutputToContain('Watermark:')
            ->assertFailed();

        $this->assertSame(0, DB::scalar('select count(*) from jobs'));
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
