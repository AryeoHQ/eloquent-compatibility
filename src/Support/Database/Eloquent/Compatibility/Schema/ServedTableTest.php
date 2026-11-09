<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(ServedTable::class)]
final class ServedTableTest extends TestCase
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
    public function it_relies_on_postgresql_to_refuse_every_native_write_to_the_installed_view(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $jobs = DB::table('jobs')->orderBy('id')->get()->all();

        $states = array_map(fn (Closure $write) => $this->stateOf($write), [
            fn () => DB::table('listings')->insert(['id' => 9, 'title' => 'Birch House']),
            fn () => DB::table('listings')->insertGetId(['title' => 'Birch House']),
            fn () => DB::table('listings')->insertOrIgnore(['id' => 9, 'title' => 'Birch House']),
            fn () => DB::table('listings')->upsert(['id' => 1, 'title' => 'Oak Manor'], ['id']),
            fn () => DB::table('listings')->where('id', 1)->update(['title' => 'Oak Manor']),
            fn () => DB::table('listings')->join(
                table: 'jobs',
                first: 'jobs.id',
                operator: '=',
                second: 'listings.job_id',
            )->where(
                column: 'listings.id',
                operator: '=',
                value: 1,
            )->updateFrom(['title' => 'Oak Manor']),
            fn () => DB::table('listings')->where('id', 1)->delete(),
            fn () => DB::table('listings')->truncate(),
        ]);

        $this->assertSame(['55000', '55000', '55000', '55000', '55000', '55000', '55000', '42809'], $states);
        $this->assertEquals($jobs, DB::table('jobs')->orderBy('id')->get()->all());
    }

    #[Test]
    public function it_asks_the_catalog_on_every_call_outside_a_transaction(): void
    {
        $listing = new Listing;
        $asked = $this->catalogQueries();

        $answers = [ServedTable::serves($listing), ServedTable::serves($listing)];
        $before = $asked();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $installed = $asked();
        $answers[] = ServedTable::serves($listing);

        $this->assertSame([[false, false, true], 2, 1], [$answers, $before, $asked() - $installed]);
    }

    #[Test]
    public function it_asks_the_catalog_once_per_transaction_and_again_in_the_next_one(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $listing = new Listing;
        $asked = $this->catalogQueries();

        $first = DB::transaction(function () use ($listing, $asked): array {
            $answers = [ServedTable::serves($listing), ServedTable::view(DB::connection(), 'listings') !== null, ServedTable::reading($listing, ['jobs'])];
            $answers[] = DB::transaction(fn () => [ServedTable::serves($listing), ServedTable::reading($listing, ['jobs'])]);

            return [$answers, $asked()];
        });
        $second = DB::transaction(fn () => [ServedTable::serves($listing), ServedTable::reading($listing, ['jobs']), $asked()]);

        $relation = ['path' => (string) DB::scalar("select array_to_string(current_schemas(true), ',')"), 'view' => true, 'oid' => DB::scalar("select cast(cast(to_regclass('listings') as oid) as bigint)"), 'unbound' => [], 'readable' => true];
        $this->assertSame([[true, true, $relation, [true, $relation]], 2], $first);
        $this->assertSame([true, $relation, 4], $second);
    }

    #[Test]
    public function it_answers_an_install_or_an_rollback_another_process_commits_in_the_next_transaction(): void
    {
        $listing = new Listing;
        $read = fn () => DB::transaction(fn () => [ServedTable::serves($listing), ServedTable::reading($listing, ['jobs'])['view']]);

        $answers = [$read()];
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class, statementTimeout: \\Carbon\\CarbonInterval::seconds(30))');
        $answers[] = $read();
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class)');
        $answers[] = $read();

        $this->assertSame([[false, false], [true, true], [false, false]], $answers);
    }

    #[Test]
    public function it_asks_the_catalog_again_once_the_transaction_that_holds_an_answer_ends(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $listing = new Listing;

        $answers = [DB::transaction(fn () => ServedTable::serves($listing))];
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class)');
        $answers[] = ServedTable::serves($listing);
        $answers[] = ServedTable::view(DB::connection(), 'listings');

        $this->assertSame([true, false, null], $answers);
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function stateOf(Closure $write): null|string
    {
        try {
            $write();
        } catch (QueryException $exception) {
            return $exception->errorInfo[0] ?? null;
        }

        return null;
    }

    /**
     * @return Closure(): int
     */
    private function catalogQueries(): Closure
    {
        $asked = 0;
        DB::listen(function (QueryExecuted $query) use (&$asked): void {
            $asked += str_contains($query->sql, 'pg_class') ? 1 : 0;
        });

        return function () use (&$asked): int {
            return $asked;
        };
    }

    private function inAnotherProcess(string $statement): void
    {
        $child = 'require '.var_export(dirname(__DIR__, 6).'/vendor/autoload.php', true).';'
            .' Tests\\TestCase::optimizeClassmap();'
            .' Orchestra\Testbench\Foundation\Application::create();'
            .' '.$statement.';'
            .' Illuminate\Support\Facades\DB::disconnect();';
        $process = proc_open([PHP_BINARY, '-r', $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['APP_ENV' => 'testing', 'DB_DATABASE' => DB::connection()->getDatabaseName()] + getenv());

        if ($process === false) {
            $this->fail('The other process did not start.');
        }

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);

        $this->assertSame([0, ''], [proc_close($process), $output]);
    }
}
