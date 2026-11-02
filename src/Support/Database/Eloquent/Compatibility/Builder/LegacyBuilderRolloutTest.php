<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Schema\ServedTable;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(LegacyBuilder::class)]
#[CoversClass(LegacyQueryBuilder::class)]
#[CoversClass(ServedTable::class)]
final class LegacyBuilderRolloutTest extends TestCase
{
    private const string CATALOG = "select cast(oid as bigint) from pg_class where oid = to_regclass(?) and relkind = 'v'";

    /** @var list<string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
        DB::connection()->beforeExecuting(function (string $query): void {
            $this->statements[] = self::statement($query);
        });
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string, Closure(): mixed, mixed}>
     */
    public static function writesAcrossTheInstall(): iterable
    {
        $title = fn (int $id) => fn () => DB::table('listings')->where('id', $id)->value('title');

        yield 'a record update' => [fn () => Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']), 'update listings', $title(1), 'Oak Manor'];
        yield 'a record create' => [fn () => Listing::query()->create(['title' => 'Oak Manor', 'company_id' => ListingTables::COMPANY]), 'insert listings', $title(4), 'Oak Manor'];
        yield 'a record delete' => [fn () => Listing::query()->findOrFail(1)->delete(), 'update listings', fn () => DB::table('listings')->where('id', 1)->whereNotNull('deleted_at')->count(), 1];
        yield 'a record increment' => [fn () => Listing::query()->findOrFail(1)->increment('price'), 'update listings', fn () => DB::table('jobs')->where('legacy_id', 1)->value('price_cents'), 125150];
        yield 'a mass update' => [fn () => Listing::query()->whereKey(1)->update(['title' => 'Oak Manor']), 'update listings', $title(1), 'Oak Manor'];
        yield 'a query builder insert' => [fn () => Listing::query()->toBase()->insert(['title' => 'Oak Manor', 'company_id' => ListingTables::COMPANY]), 'insert listings', $title(4), 'Oak Manor'];
        yield 'a query builder delete' => [fn () => Listing::query()->toBase()->where('id', 3)->delete(), 'delete listings', fn () => DB::table('listings')->where('id', 3)->count(), 0];
    }

    /**
     * @param  Closure(): mixed  $write
     * @param  Closure(): mixed  $written
     */
    #[DataProvider('writesAcrossTheInstall')]
    #[Test]
    public function it_retries_a_native_write_once_translated_after_another_process_installs_the_view(Closure $write, string $native, Closure $written, mixed $expected): void
    {
        $this->prepare();
        Listing::query()->findOrFail(3)->update(['title' => 'Elm Lodge']);
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class)');
        $this->statements = [];

        $write();
        $statements = $this->statements();
        $attempt = array_search($native, $statements, true);

        $this->assertIsInt($attempt);
        $this->assertNotContains(self::CATALOG, array_slice($statements, 0, $attempt));
        $this->assertSame([$native, self::CATALOG], array_slice($statements, $attempt, 2));
        $this->assertContains("select set_config('TimeZone', ?, true)", array_slice($statements, $attempt + 2));
        $this->assertCount(1, array_keys($statements, $native, true));
        $this->assertSame($expected, $written());
    }

    #[Test]
    public function it_writes_natively_before_the_install_and_to_the_successors_after_it_within_one_process(): void
    {
        $this->prepare();
        $this->statements = [];

        Listing::query()->findOrFail(1)->update(['title' => 'Pine Lodge']);
        Listing::query()->findOrFail(1)->update(['title' => 'Birch Lodge']);
        $native = $this->statements();
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class)');
        $this->statements = [];

        Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);
        $created = Listing::query()->create(['title' => 'Cedar House', 'company_id' => ListingTables::COMPANY]);
        $translated = $this->statements();

        $this->assertSame([self::CATALOG, 'update listings', 'update listings'], array_values(array_filter($native, fn (string $statement) => ! str_starts_with($statement, 'select listings'))));
        $this->assertSame([1, 0, 2], [count(array_keys($translated, 'update listings', true)), count(array_keys($translated, 'insert listings', true)), count(array_keys($translated, self::CATALOG, true))]);
        $this->assertSame(['Oak Manor', 'Cedar House'], [$this->job(1)->name, $this->job((int) $created->getKey())->name]);
        $this->assertSame(['Oak Manor', 'Cedar House'], [$this->served(1), $this->served((int) $created->getKey())]);
    }

    #[Test]
    public function it_asks_the_catalog_before_a_native_write_inside_a_transaction_instead_of_trusting_an_earlier_answer(): void
    {
        $this->prepare();
        Listing::query()->findOrFail(3)->update(['title' => 'Elm Lodge']);
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class)');
        $listing = Listing::query()->findOrFail(1);
        $this->statements = [];

        DB::transaction(fn () => $listing->update(['title' => 'Oak Manor']));

        $statements = $this->statements();

        $this->assertSame(self::CATALOG, $statements[0] ?? null);
        $this->assertContains("select set_config('TimeZone', ?, true)", $statements);
        $this->assertNotContains('update listings', $statements);
        $this->assertSame('Oak Manor', $this->job(1)->name);
    }

    #[Test]
    public function it_writes_through_the_successors_without_a_native_attempt_after_this_process_installs_the_view(): void
    {
        $this->prepare();
        Listing::query()->findOrFail(1)->update(['title' => 'Pine Lodge']);
        Compatibility::install(Listing::class);
        $this->statements = [];

        Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);

        $this->assertNotContains('update listings', $this->statements());
        $this->assertSame('Oak Manor', $this->job(1)->name);
    }

    #[Test]
    public function it_asks_the_catalog_once_for_a_native_record_write_inside_a_transaction(): void
    {
        $this->prepare();
        $listing = Listing::query()->findOrFail(1);
        $this->statements = [];

        DB::transaction(fn () => $listing->update(['title' => 'Pine Lodge']));
        $updated = $this->statements();
        $this->statements = [];
        $created = DB::transaction(fn () => Listing::query()->create(['title' => 'Cedar House', 'company_id' => ListingTables::COMPANY]));

        $this->assertSame([[self::CATALOG, 'update listings'], [self::CATALOG, 'insert listings']], [$updated, $this->statements()]);
        $this->assertSame(['Pine Lodge', 'Cedar House'], [DB::table('listings')->where('id', 1)->value('title'), DB::table('listings')->where('id', $created->getKey())->value('title')]);
    }

    #[Test]
    public function it_asks_the_catalog_again_only_after_a_native_write_fails_as_a_write_to_a_view_would(): void
    {
        $this->prepare();
        Listing::query()->findOrFail(3)->update(['title' => 'Elm Lodge']);
        $this->statements = [];
        $failure = null;

        try {
            Listing::query()->toBase()->insert(['id' => 1, 'title' => 'Oak Manor', 'company_id' => ListingTables::COMPANY]);
        } catch (QueryException $exception) {
            $failure = $exception;
        }

        $this->assertSame('23505', $failure?->errorInfo[0] ?? null);
        $this->assertSame(['insert listings'], $this->statements());
    }

    #[Test]
    public function it_writes_natively_again_after_another_process_uninstalls_the_view(): void
    {
        $this->install();
        Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::uninstall(Tests\Fixtures\Support\Listings\Listing::class)');

        Listing::query()->findOrFail(1)->update(['title' => 'Oak Lodge']);
        Listing::query()->whereKey(3)->update(['price' => '150.00']);

        $this->assertSame(['r', 'Oak Lodge', '150.00'], [DB::scalar("select relkind::text from pg_class where oid = 'listings'::regclass"), DB::table('listings')->where('id', 1)->value('title'), DB::table('listings')->where('id', 3)->value('price')]);
        $this->assertSame(['Oak Manor', 9999], [$this->job(1)->name, $this->job(3)->price_cents]);
    }

    #[Test]
    public function it_translates_a_filter_once_another_process_installs_the_view_and_stops_once_another_uninstalls_it(): void
    {
        $this->prepare();
        $before = Listing::query()->where('price', '>', 100)->toBase()->toSql();
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class)');
        $installed = DB::transaction(fn () => [Listing::query()->where('price', '>', 100)->toBase()->toSql(), array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all())]);
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::uninstall(Tests\Fixtures\Support\Listings\Listing::class)');
        DB::table('listings')->where('id', 3)->update(['price' => '150.00']);
        $uninstalled = DB::transaction(fn () => [Listing::query()->where('price', '>', 100)->toBase()->toSql(), array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all())]);

        $this->assertStringNotContainsString('"jobs"', $before);
        $this->assertStringContainsString('"jobs"."price_cents" > ?', $installed[0]);
        $this->assertStringNotContainsString('"jobs"', $uninstalled[0]);
        $this->assertSame([[1], [1, 3]], [$installed[1], $uninstalled[1]]);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function transactions(): iterable
    {
        yield 'outside a transaction' => [false];
        yield 'inside a transaction' => [true];
    }

    #[DataProvider('transactions')]
    #[Test]
    public function it_writes_through_the_successors_when_the_search_path_reaches_the_installed_view_through_a_later_schema(bool $transaction): void
    {
        $this->install();
        DB::statement('create schema compatibility_empty');
        $searchPath = DB::scalar("select current_setting('search_path')");

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_empty, '.$searchPath]);
            $this->statements = [];
            $write = fn () => Listing::query()->whereKey(1)->update(['title' => 'Oak Manor']);
            $transaction ? DB::transaction($write) : $write();
            $attempted = $this->statements();
            $read = fn () => [Listing::query()->where('price', '>', 100)->toBase()->toSql(), array_map(intval(...), Listing::query()->where('price', '>', 1000)->orderBy('id')->pluck('id')->all())];
            $filtered = $transaction ? DB::transaction($read) : $read();
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_empty');
        }

        $this->assertNotContains('update listings', $attempted);
        $this->assertSame('Oak Manor', $this->job(1)->name);
        $this->assertStringContainsString('"jobs"."price_cents" > ?', $filtered[0]);
        $this->assertSame([1], $filtered[1]);
    }

    /**
     * @return iterable<string, array{bool, list<string>}>
     */
    public static function failuresOutsideTheView(): iterable
    {
        yield 'outside a transaction' => [false, ['insert listings', self::CATALOG]];
        yield 'inside a transaction' => [true, [self::CATALOG, 'insert listings', self::CATALOG]];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('failuresOutsideTheView')]
    #[Test]
    public function it_rethrows_a_native_write_that_fails_as_a_view_write_would_while_the_catalog_shows_a_table(bool $transaction, array $expected): void
    {
        $this->prepare();
        Listing::query()->findOrFail(3)->update(['title' => 'Elm Lodge']);
        DB::statement('create sequence compatibility_unread');
        $this->statements = [];
        $failure = null;
        $write = fn () => Listing::query()->toBase()->insert(['title' => DB::raw("cast(currval('compatibility_unread') as text)"), 'company_id' => ListingTables::COMPANY]);

        try {
            $transaction ? DB::transaction($write) : $write();
        } catch (QueryException $exception) {
            $failure = $exception;
        } finally {
            $attempted = $this->statements();
            DB::statement('drop sequence compatibility_unread');
        }

        $this->assertSame('55000', $failure?->errorInfo[0] ?? null);
        $this->assertSame($expected, $attempted);
        $this->assertSame(3, DB::table('listings')->count());
    }

    #[Test]
    public function it_retries_a_native_write_inside_a_transaction_translated_once_an_install_it_waited_for_commits(): void
    {
        $this->prepare();
        $listing = Listing::query()->findOrFail(1);
        $install = $this->startInAnotherProcess('Illuminate\Support\Facades\DB::listen(function ($query) { if ($query->sql === \'LOCK TABLE "listings" IN SHARE ROW EXCLUSIVE MODE\') { usleep(1500000); } });'
            .' Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class)');
        $this->awaitLock('ShareRowExclusiveLock');

        DB::transaction(fn () => $listing->update(['title' => 'Oak Manor']));

        $this->assertSame([0, ''], $this->finish($install));
        $this->assertSame(['v', 'Oak Manor', 'Oak Manor'], [DB::scalar("select relkind::text from pg_class where oid = 'listings'::regclass"), $this->job(1)->name, $this->served(1)]);
    }

    #[Test]
    public function it_asks_the_catalog_again_for_a_filter_a_builder_adds_after_another_process_uninstalls_the_view(): void
    {
        $this->install();
        $query = Listing::query()->where('price', '>', 100);
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::uninstall(Tests\Fixtures\Support\Listings\Listing::class)');

        $query->where('price', '<', 5000);

        $this->assertSame(1, substr_count($query->toBase()->toSql(), '"jobs"."price_cents"'));
    }

    private function prepare(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
    }

    private function install(): void
    {
        $this->prepare();
        Compatibility::install(Listing::class);
    }

    private function inAnotherProcess(string $statement): void
    {
        $this->assertSame([0, ''], $this->finish($this->startInAnotherProcess($statement)));
    }

    /**
     * @return array{resource, array<int, resource>}
     */
    private function startInAnotherProcess(string $statement): array
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

        return [$process, $pipes];
    }

    /**
     * @param  array{resource, array<int, resource>}  $process
     * @return array{int, string}
     */
    private function finish(array $process): array
    {
        [$handle, $pipes] = $process;
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);

        return [proc_close($handle), $output];
    }

    private function awaitLock(string $mode): void
    {
        $deadline = microtime(true) + 10;

        while (DB::scalar("select count(*) from pg_locks where relation = to_regclass('listings') and mode = ? and granted and pid <> pg_backend_pid()", [$mode]) === 0) {
            if (microtime(true) > $deadline) {
                $this->fail("No other session took {$mode} on listings.");
            }

            usleep(20000);
        }
    }

    /**
     * @return list<string>
     */
    private function statements(): array
    {
        return $this->statements;
    }

    private function served(int $id): mixed
    {
        return DB::table('listings')->where('id', $id)->value('title');
    }

    private function job(int $legacyId): Job
    {
        return Job::withTrashed()->where('legacy_id', $legacyId)->firstOrFail();
    }

    private static function statement(string $sql): string
    {
        return (string) preg_replace('/^(select|insert|update|delete)(?: .*? from| from| into)? "(\w+)".*?( for update)?$/s', '$1 $2$3', $sql);
    }
}
