<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityQueryRefused;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Schema\ServedTable;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Photo;
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
        yield 'a record increment' => [fn () => Listing::query()->findOrFail(1)->increment('price'), 'update listings', fn () => DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: ListingTables::jobOf(1),
        )->value('price_cents'), 125150];
        yield 'a mass update' => [fn () => Listing::query()->whereKey(1)->update(['title' => 'Oak Manor']), 'update listings', $title(1), 'Oak Manor'];
        yield 'a query builder insert' => [fn () => Listing::query()->toBase()->insert(['title' => 'Oak Manor', 'company_id' => ListingTables::COMPANY]), 'insert listings', $title(4), 'Oak Manor'];
        yield 'a query builder delete' => [fn () => Listing::query()->toBase()->where('id', 3)->delete(), 'delete listings', fn () => DB::table('listings')->where('id', 3)->count(), 0];
        yield 'a query builder increment' => [fn () => Listing::query()->toBase()->where(
            column: 'id',
            operator: '=',
            value: 1,
        )->increment('price'), 'update listings', fn () => DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: ListingTables::jobOf(1),
        )->value('price_cents'), 125150];
        yield 'a query builder decrement' => [fn () => Listing::query()->toBase()->where(
            column: 'id',
            operator: '=',
            value: 1,
        )->decrement('price'), 'update listings', fn () => DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: ListingTables::jobOf(1),
        )->value('price_cents'), 124950];
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
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class, statementTimeout: \\Carbon\\CarbonInterval::seconds(30))');
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
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class, statementTimeout: \\Carbon\\CarbonInterval::seconds(30))');
        $this->statements = [];

        Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);
        $created = Listing::query()->create(['title' => 'Cedar House', 'company_id' => ListingTables::COMPANY]);
        $translated = $this->statements();

        $this->assertSame([self::CATALOG, 'update listings', 'update listings'], array_values(array_filter($native, fn (string $statement) => ! str_starts_with($statement, 'select listings'))));
        $this->assertSame([1, 0, 2], [count(array_keys($translated, 'update listings', true)), count(array_keys($translated, 'insert listings', true)), count(array_keys($translated, self::CATALOG, true))]);
        $this->assertSame(['Oak Manor', 'Cedar House'], [$this->job(1)->name, $this->job((int) $created->getKey())->name]);
        $this->assertSame(['Oak Manor', 'Cedar House'], [$this->served(1), $this->served((int) $created->getKey())]);
    }

    /**
     * @return iterable<string, array{Closure(): string, string, string}>
     */
    public static function externalWritesAcrossTheInstall(): iterable
    {
        yield 'a record create' => [fn (): string => (string) Photo::query()->findOrFail(Photo::query()->create(['file_name' => 'oak-side.jpg', 'mime_type' => 'image/webp', 'size_kb' => 256, 'caption' => 'Side'])->getKey())->mime_type, 'insert photos', 'image/webp'];
        yield 'a mass update' => [function (): string {
            try {
                Photo::query()->whereKey(2)->update(['mime_type' => 'image/webp']);

                return 'written';
            } catch (CompatibilityWriteRefused $refusal) {
                return $refusal->getMessage();
            }
        }, 'update photos', Photo::class.'::$mime_type cannot take a mass write. Why: It is declared external(), so its value lives outside the database, and no set-based statement can reach an external system. Instead: Write the records one at a time through Photo.'];
        yield 'a query builder insert' => [function (): string {
            Photo::query()->toBase()->insert(['file_name' => 'oak-side.jpg', 'mime_type' => 'image/webp', 'size_kb' => 256, 'caption' => 'Side']);

            return (string) Photo::query()->where('file_name', 'oak-side.jpg')->firstOrFail()->mime_type;
        }, 'insert photos', 'image/webp'];
    }

    /**
     * @param  Closure(): string  $write
     */
    #[DataProvider('externalWritesAcrossTheInstall')]
    #[Test]
    public function it_retries_a_native_write_of_an_external_attribute_the_view_omits_once_translated_after_another_process_installs_the_view(Closure $write, string $native, string $expected): void
    {
        FileTables::create();
        FileTables::seed();

        try {
            Compatibility::prepare(Photo::class);
            DB::statement('update photos set file_id = gen_random_uuid() where file_id is null');
            DB::statement('insert into files (id, filename, mime_type, size_bytes, created_at, updated_at) select file_id, file_name, mime_type, round(size_kb * 1000), created_at, updated_at from photos');
            Photo::query()->findOrFail(1)->update(['caption' => 'Front door']);
            $this->inAnotherProcess("Tests\\Fixtures\\Support\\Files\\FileTables::catalog('photos'); Support\\Database\\Eloquent\\Compatibility\\Compatibility::install(Tests\\Fixtures\\Support\\Files\\Photo::class, statementTimeout: \\Carbon\\CarbonInterval::seconds(30))");
            $this->statements = [];

            $outcome = $write();
            $statements = $this->statements();
            $attempt = array_search($native, $statements, true);
        } finally {
            FileTables::drop();
        }

        $this->assertIsInt($attempt);
        $this->assertSame([$native, self::CATALOG], array_slice($statements, $attempt, 2));
        $this->assertCount(1, array_keys($statements, $native, true));
        $this->assertSame($expected, $outcome);
    }

    #[Test]
    public function it_asks_the_catalog_before_a_native_write_inside_a_transaction_instead_of_trusting_an_earlier_answer(): void
    {
        $this->prepare();
        Listing::query()->findOrFail(3)->update(['title' => 'Elm Lodge']);
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class, statementTimeout: \\Carbon\\CarbonInterval::seconds(30))');
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
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
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
    public function it_writes_natively_again_after_another_process_rolls_back_the_view(): void
    {
        $this->install();
        Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class)');

        Listing::query()->findOrFail(1)->update(['title' => 'Oak Lodge']);
        Listing::query()->whereKey(3)->update(['price' => '150.00']);

        $this->assertSame(['r', 'Oak Lodge', '150.00'], [DB::scalar("select relkind::text from pg_class where oid = 'listings'::regclass"), DB::table('listings')->where('id', 1)->value('title'), DB::table('listings')->where('id', 3)->value('price')]);
        $this->assertSame(['Oak Manor', 9999], [$this->job(1)->name, $this->job(3)->price_cents]);
    }

    #[Test]
    public function it_translates_a_filter_once_another_process_installs_the_view_and_stops_once_another_rolls_back_it(): void
    {
        $this->prepare();
        $before = Listing::query()->where('price', '>', 100)->toBase()->toSql();
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class, statementTimeout: \\Carbon\\CarbonInterval::seconds(30))');
        $installed = DB::transaction(fn () => [Listing::query()->where('price', '>', 100)->toBase()->toSql(), array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all())]);
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class)');
        DB::table('listings')->where('id', 3)->update(['price' => '150.00']);
        $rolledBack = DB::transaction(fn () => [Listing::query()->where('price', '>', 100)->toBase()->toSql(), array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all())]);

        $this->assertStringNotContainsString('"jobs"', $before);
        $this->assertStringContainsString('"jobs"."price_cents" > ?', $installed[0]);
        $this->assertStringNotContainsString('"jobs"', $rolledBack[0]);
        $this->assertSame([[1], [1, 3]], [$installed[1], $rolledBack[1]]);
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

    #[Test]
    public function it_filters_a_narrowed_attribute_on_the_expression_the_view_serves_when_the_search_path_reaches_the_view_through_a_later_schema(): void
    {
        DB::statement('alter table jobs add column price_mills integer');
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return LegacyBuilderReadTest::identified($listing)
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => LegacyBuilderReadTest::required($job)
                        ->attribute(
                            name: 'price',
                            definition: fn (Attribute $attribute) => $attribute->from('price_mills')->dividedBy(1000),
                        ));
            }
        };
        Compatibility::prepare($model::class);
        ListingTables::backfill();
        DB::update('update jobs set price_mills = 1005 where id = (select job_id from listings where id = 1)');
        DB::update('update jobs set price_mills = 1010 where id = (select job_id from listings where id = 3)');
        Compatibility::install(
            model: $model::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        DB::statement('create schema compatibility_empty');
        $searchPath = DB::scalar("select current_setting('search_path')");
        $read = fn () => array_map(intval(...), $model->newQuery()->where('price', 1.01)->orderBy('id')->pluck('id')->all());

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_empty, '.$searchPath]);
            $later = $read();
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_empty');
        }

        $this->assertSame([[1, 3], [1, 3]], [$later, $read()]);
    }

    #[Test]
    public function it_filters_on_the_view_when_the_search_path_resolves_a_successor_name_to_another_table_than_the_view_reads(): void
    {
        $this->install();
        DB::statement('create schema compatibility_tenant');
        DB::statement('create table compatibility_tenant.jobs as select * from public.jobs');
        DB::update('update compatibility_tenant.jobs set price_cents = case id when (select job_id from public.listings_retained where id = 1) then 100 when (select job_id from public.listings_retained where id = 3) then 15000 else price_cents end');
        $searchPath = DB::scalar("select current_setting('search_path')");
        $read = fn () => array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all());

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_tenant, '.$searchPath]);
            $tenant = [$read(), DB::scalar('select count(*) from listings where price > 100')];
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_tenant cascade');
        }

        $this->assertSame([[1], 1], $tenant);
        $this->assertSame([1], $read());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function searchPathSettings(): iterable
    {
        yield 'set local search_path' => ['set local search_path to compatibility_tenant'];
        yield 'set schema' => ["set schema 'compatibility_tenant'"];
        yield 'set local with a quoted name' => ['set local "search_path" to compatibility_tenant'];
    }

    #[DataProvider('searchPathSettings')]
    #[Test]
    public function it_filters_the_native_table_once_the_search_path_leaves_the_installed_view_within_one_transaction(string $setting): void
    {
        DB::statement('create schema compatibility_tenant');
        $searchPath = DB::scalar("select current_setting('search_path')");

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_tenant']);
            ListingTables::create();
            ListingTables::seed();
            DB::table('listings')->where('id', 3)->update(['price' => '150.00']);
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            $this->install();
            $read = fn () => array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all());
            $answers = DB::transaction(function () use ($read, $setting): array {
                $installed = $read();
                DB::statement($setting);

                return [$installed, $read()];
            });
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_tenant cascade');
        }

        $this->assertSame([[1], [1, 3]], $answers);
    }

    #[Test]
    public function it_filters_the_native_table_once_a_savepoint_that_left_the_installed_view_rolls_back(): void
    {
        DB::statement('create schema compatibility_tenant');
        $searchPath = DB::scalar("select current_setting('search_path')");

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_tenant']);
            ListingTables::create();
            ListingTables::seed();
            DB::table('listings')->where('id', 3)->update(['price' => '150.00']);
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            $this->install();
            $read = fn () => array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all());
            $answers = DB::transaction(function () use ($read): array {
                DB::statement('set local search_path to compatibility_tenant');
                $tenant = [];

                try {
                    DB::transaction(function () use ($read, &$tenant): never {
                        DB::statement('set local search_path to public');
                        $tenant = $read();

                        throw new RuntimeException('roll back the savepoint');
                    });
                } catch (RuntimeException) {
                }

                return [$tenant, $read()];
            });
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_tenant cascade');
        }

        $this->assertSame([[1], [1, 3]], $answers);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roleSettings(): iterable
    {
        yield 'set local role' => ['set local role %s'];
        yield 'set local with a quoted name' => ['set local "role" to %s'];
    }

    #[DataProvider('roleSettings')]
    #[Test]
    public function it_filters_on_the_view_once_the_transaction_switches_to_a_role_that_cannot_read_the_successors(string $setting): void
    {
        $this->install();
        $role = 'compatibility_rollout_role_'.getmypid();
        DB::statement("create role {$role}");
        $read = fn () => array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all());

        try {
            DB::statement("grant select on listings to {$role}");
            $answers = DB::transaction(function () use ($read, $role, $setting): array {
                $owner = $read();
                DB::statement(sprintf($setting, $role));

                return [$owner, $read()];
            });
        } finally {
            DB::statement('reset role');
            DB::statement("drop owned by {$role}");
            DB::statement("drop role {$role}");
        }

        $this->assertSame([[1], [1]], $answers);
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
            .' Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class, statementTimeout: \\Carbon\\CarbonInterval::seconds(30))');
        $this->awaitLock('ShareRowExclusiveLock');

        DB::transaction(fn () => $listing->update(['title' => 'Oak Manor']));

        $this->assertSame([0, ''], $this->finish($install));
        $this->assertSame(['v', 'Oak Manor', 'Oak Manor'], [DB::scalar("select relkind::text from pg_class where oid = 'listings'::regclass"), $this->job(1)->name, $this->served(1)]);
    }

    #[Test]
    public function it_asks_the_catalog_again_for_a_filter_a_builder_adds_after_another_process_rolls_back_the_view(): void
    {
        $this->install();
        $query = Listing::query()->where('price', '>', 100);
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class)');

        $query->where('price', '<', 5000);

        $this->assertSame(1, substr_count($query->toBase()->toSql(), '"jobs"."price_cents"'));
    }

    #[Test]
    public function it_refuses_to_run_a_filter_it_translated_once_another_process_rolls_back_the_view_and_a_new_query_reads_the_native_rows(): void
    {
        $this->install();
        $query = Listing::query()->where('price', '>', 100)->orderBy('id');
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class)');
        DB::table('listings')->where('id', 3)->update(['price' => '150.00']);

        try {
            $stale = array_map(intval(...), $query->pluck('id')->all());
        } catch (CompatibilityQueryRefused $refusal) {
            $stale = $refusal->getMessage();
        }

        $this->assertSame(Listing::class.' cannot run a query whose filters it translated for the view listings. Why: Another process rolled back or replaced that view after the filters were translated, so they read successor rows that listings no longer serves. Instead: build the query again.', $stale);
        $this->assertSame([1, 3], array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all()));
    }

    #[Test]
    public function it_filters_on_the_types_the_view_serves_once_another_process_reinstalls_it_over_a_legacy_column_whose_type_changed(): void
    {
        $this->install();
        $warmed = array_map(intval(...), Listing::query()->where('price', 1)->pluck('id')->all());
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class);'
            .' Illuminate\Support\Facades\DB::statement(\'alter table listings alter column price type numeric(10,0)\');'
            .' Support\Database\Eloquent\Compatibility\Compatibility::prepare(Tests\Fixtures\Support\Listings\Listing::class); Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class, statementTimeout: \\Carbon\\CarbonInterval::seconds(30))');
        DB::update('update jobs set price_cents = 149 where id = (select job_id from listings_retained where id = 3)');

        $this->assertSame([[], [3], 1], [$warmed, array_map(intval(...), Listing::query()->where('price', 1)->pluck('id')->all()), DB::scalar('select count(*) from listings where price = 1')]);
    }

    #[Test]
    public function it_reads_the_served_relation_once_for_each_filter_it_translates_outside_a_transaction(): void
    {
        $this->install();
        Listing::query()->where('price', '>', 100)->toBase()->toSql();
        $reads = [];

        foreach ([fn () => Listing::query()->where('price', '>', 100), fn () => Listing::query()->where(['price' => '1250.50', 'title' => 'Oak House'])] as $filter) {
            $this->statements = [];
            $filter()->toBase()->toSql();
            $reads[] = count(array_filter($this->statements(), fn (string $statement) => str_starts_with($statement, 'select array_to_string(current_schemas(true)')));
        }

        $this->assertSame([1, 1], $reads);
    }

    /**
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function writesUnderAnotherSuccessor(): iterable
    {
        yield 'a record update' => [fn () => Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor'])];
        yield 'a mass update' => [fn () => Listing::query()->whereKey(1)->update(['title' => 'Oak Manor'])];
        yield 'a record update retried inside a transaction' => [fn () => DB::transaction(fn () => Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']))];
    }

    #[DataProvider('writesUnderAnotherSuccessor')]
    #[Test]
    public function it_refuses_a_write_through_the_view_when_the_search_path_resolves_a_successor_name_to_another_table_than_the_view_reads(Closure $write): void
    {
        $this->install();
        DB::statement('create schema compatibility_tenant');
        DB::statement('create table compatibility_tenant.jobs as select * from public.jobs');
        $searchPath = DB::scalar("select current_setting('search_path')");

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_tenant, '.$searchPath]);

            try {
                $write();
                $refusal = 'no refusal';
            } catch (CompatibilityWriteRefused $exception) {
                $refusal = $exception->getMessage();
            }

            $tenant = DB::scalar('select name from compatibility_tenant.jobs where id = (select job_id from public.listings_retained where id = 1)');
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_tenant cascade');
        }

        $this->assertSame([Listing::class.' records cannot be written through the view listings. Why: The search path resolves jobs to another table than the view reads, so the write would change rows that listings does not serve. Instead: Set a search path that resolves each successor to the table the view reads.', 'Oak House', 'Oak House'], [$refusal, $tenant, $this->job(1)->name]);
    }

    #[Test]
    public function it_refuses_a_query_that_adds_a_filter_after_another_process_reinstalls_the_view_to_a_filter_it_translated_for_the_view_before(): void
    {
        $this->install();
        $query = Listing::query()->where('price', 1)->orderBy('id');
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class);'
            .' Illuminate\Support\Facades\DB::statement(\'alter table listings alter column price type numeric(10,0)\');'
            .' Support\Database\Eloquent\Compatibility\Compatibility::prepare(Tests\Fixtures\Support\Listings\Listing::class); Support\Database\Eloquent\Compatibility\Compatibility::install(Tests\Fixtures\Support\Listings\Listing::class, statementTimeout: \\Carbon\\CarbonInterval::seconds(30))');
        DB::update('update jobs set price_cents = 149 where id = (select job_id from listings_retained where id = 3)');
        $query->where('price', '>=', 0);

        try {
            $mixed = array_map(intval(...), $query->pluck('id')->all());
        } catch (CompatibilityQueryRefused $refusal) {
            $mixed = $refusal->getMessage();
        }

        $this->assertSame(Listing::class.' cannot run a query whose filters it translated for the view listings. Why: Another process rolled back or replaced that view after the filters were translated, so they read successor rows that listings no longer serves. Instead: build the query again.', $mixed);
        $this->assertSame([3], array_map(intval(...), Listing::query()->where('price', 1)->where('price', '>=', 0)->orderBy('id')->pluck('id')->all()));
    }

    #[Test]
    public function it_refuses_to_run_a_filter_it_translated_once_the_search_path_resolves_a_successor_name_to_another_table_than_the_view_reads(): void
    {
        $this->install();
        DB::statement('create schema compatibility_tenant');
        DB::statement('create table compatibility_tenant.jobs as select * from public.jobs');
        DB::update('update compatibility_tenant.jobs set price_cents = case id when (select job_id from public.listings_retained where id = 1) then 100 when (select job_id from public.listings_retained where id = 3) then 15000 else price_cents end');
        $searchPath = DB::scalar("select current_setting('search_path')");
        $query = Listing::query()->where('price', '>', 100)->orderBy('id');

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_tenant, '.$searchPath]);

            try {
                $shadowed = array_map(intval(...), $query->pluck('id')->all());
            } catch (CompatibilityQueryRefused $refusal) {
                $shadowed = $refusal->getMessage();
            }

            $rebuilt = array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all());
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_tenant cascade');
        }

        $this->assertSame([Listing::class.' cannot run a query whose filters it translated for the view listings. Why: The search path now resolves jobs to another table than the view reads, so the filters would read rows that listings does not serve. Instead: build the query again.', [1]], [$shadowed, $rebuilt]);
    }

    #[Test]
    public function it_refuses_to_run_a_filter_it_translated_once_the_transaction_switches_to_a_role_that_cannot_read_the_successors(): void
    {
        $this->install();
        $role = 'compatibility_rollout_role_'.getmypid();
        DB::statement("create role {$role}");

        try {
            DB::statement("grant select on listings to {$role}");
            $answers = DB::transaction(function () use ($role): array {
                $query = Listing::query()->where('price', '>', 100)->orderBy('id');
                DB::statement("set local role {$role}");

                try {
                    $translated = array_map(intval(...), $query->pluck('id')->all());
                } catch (CompatibilityQueryRefused $refusal) {
                    $translated = $refusal->getMessage();
                }

                return [$translated, array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all())];
            });
        } finally {
            DB::statement('reset role');
            DB::statement("drop owned by {$role}");
            DB::statement("drop role {$role}");
        }

        $this->assertSame([Listing::class.' cannot run a query whose filters it translated for the view listings. Why: The current role cannot read every successor table the view reads, so the filters would not read the rows that listings serves. Instead: build the query again.', [1]], $answers);
    }

    #[Test]
    public function it_refuses_to_iterate_a_cursor_whose_filter_it_translated_once_another_process_rolls_back_the_view_after_the_cursor_was_created(): void
    {
        $this->install();
        $cursor = Listing::query()->where('price', '>', 100)->orderBy('id')->cursor();
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class)');
        DB::table('listings')->where('id', 3)->update(['price' => '150.00']);

        try {
            $stale = array_map(fn (Listing $listing) => (int) $listing->id, $cursor->all());
        } catch (CompatibilityQueryRefused $refusal) {
            $stale = $refusal->getMessage();
        }

        $this->assertSame(Listing::class.' cannot run a query whose filters it translated for the view listings. Why: Another process rolled back or replaced that view after the filters were translated, so they read successor rows that listings no longer serves. Instead: build the query again.', $stale);
        $this->assertSame([1, 3], array_map(fn (Listing $listing) => (int) $listing->id, Listing::query()->where('price', '>', 100)->orderBy('id')->cursor()->all()));
    }

    #[Test]
    public function it_refuses_to_run_a_union_whose_operand_filter_it_translated_once_another_process_rolls_back_the_view(): void
    {
        $this->install();
        $union = fn () => Listing::query()->toBase()->where('id', 0)->union(Listing::query()->where('price', '>', 100));
        $ids = function (QueryBuilder $query): array {
            $ids = array_map(fn (stdClass $row) => intval($row->id), $query->get()->all());
            sort($ids);

            return $ids;
        };
        $query = $union();
        $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class)');
        DB::table('listings')->where('id', 3)->update(['price' => '150.00']);

        try {
            $stale = $ids($query);
        } catch (CompatibilityQueryRefused $refusal) {
            $stale = $refusal->getMessage();
        }

        $this->assertSame([Listing::class.' cannot run a query whose filters it translated for the view listings. Why: Another process rolled back or replaced that view after the filters were translated, so they read successor rows that listings no longer serves. Instead: build the query again.', [1, 3]], [$stale, $ids($union())]);
    }

    #[Test]
    public function it_refuses_to_run_a_filter_it_translated_inside_a_transaction_once_another_process_rolls_back_the_view_before_the_query_runs(): void
    {
        $this->install();

        $answers = DB::transaction(function (): array {
            $query = Listing::query()->where('price', '>', 100)->orderBy('id');
            $this->inAnotherProcess('Support\Database\Eloquent\Compatibility\Compatibility::rollback(Tests\Fixtures\Support\Listings\Listing::class)');
            DB::table('listings')->where('id', 3)->update(['price' => '150.00']);

            try {
                $stale = array_map(intval(...), $query->pluck('id')->all());
            } catch (CompatibilityQueryRefused $refusal) {
                $stale = $refusal->getMessage();
            }

            return [$stale, array_map(intval(...), Listing::query()->where('price', '>', 100)->orderBy('id')->pluck('id')->all())];
        });

        $this->assertSame([Listing::class.' cannot run a query whose filters it translated for the view listings. Why: Another process rolled back or replaced that view after the filters were translated, so they read successor rows that listings no longer serves. Instead: build the query again.', [1, 3]], $answers);
    }

    /**
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function retainedWritesUnderAnotherTable(): iterable
    {
        yield 'a record update' => [fn () => Listing::query()->findOrFail(1)->update(['mls_number' => 'MLS-9'])];
        yield 'a mass update' => [fn () => Listing::query()->whereKey(1)->update(['mls_number' => 'MLS-9'])];
    }

    #[DataProvider('retainedWritesUnderAnotherTable')]
    #[Test]
    public function it_refuses_a_write_through_the_view_when_the_search_path_resolves_the_retained_table_to_another_table_than_the_view_reads(Closure $write): void
    {
        $this->install();
        DB::statement('create schema compatibility_tenant');
        DB::statement('create table compatibility_tenant.listings_retained (like public.listings_retained including all)');
        DB::statement('insert into compatibility_tenant.listings_retained select * from public.listings_retained');
        $searchPath = DB::scalar("select current_setting('search_path')");

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_tenant, '.$searchPath]);

            try {
                $write();
                $refusal = 'no refusal';
            } catch (CompatibilityWriteRefused $exception) {
                $refusal = $exception->getMessage();
            }

            $tenant = DB::scalar('select mls_number from compatibility_tenant.listings_retained where job_id = ?', [$this->job(1)->getKey()]);
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_tenant cascade');
        }

        $this->assertSame([Listing::class.' records cannot be written through the view listings. Why: The search path resolves listings_retained to another table than the view reads, so the write would change rows that listings does not serve. Instead: Set a search path that resolves each successor to the table the view reads.', 'MLS-1', 'MLS-1'], [$refusal, $tenant, DB::table('listings')->where('id', 1)->value('mls_number')]);
    }

    private function prepare(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
    }

    private function install(): void
    {
        $this->prepare();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
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
        return Job::withTrashed()->whereKey(ListingTables::jobOf($legacyId))->firstOrFail();
    }

    private static function statement(string $sql): string
    {
        return (string) preg_replace('/^(select|insert|update|delete)(?: .*? from| from| into)? "(\w+)".*?( for update)?$/s', '$1 $2$3', $sql);
    }
}
