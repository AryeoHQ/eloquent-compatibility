<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Schema\Statements;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(CompileSql::class)]
final class CompileSqlTest extends TestCase
{
    /** @var class-string<Model> */
    public static string $successor = Model::class;

    public static string $read = '';

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
    public function it_prints_the_statements_install_runs_and_runs_none_of_them(): void
    {
        $map = Compatibility::of(Listing::class)->resolve();
        $statements = Statements::install($map, DB::connection());

        $this->assertSame(0, Artisan::call('compatibility:sql', ['model' => Listing::class]));
        $printed = explode("\n", Artisan::output(), 4);

        $this->assertSame('-- compatibility:sql Tests\Fixtures\Support\Listings\Listing --for=install: the statements Compatibility::install() runs for listings', $printed[0]);
        $this->assertMatchesRegularExpression('/^-- Read from database testing\w*, schema public \(search path .+\), on PostgreSQL \d+\.\d+/', $printed[1]);
        $this->assertMatchesRegularExpression('/^-- Compiled at \d{4}-\d{2}-\d{2}T/', $printed[2]);
        $this->assertSame(
            "\nBEGIN;\n\n".implode("\n;\n\n", [...Statements::guard($map, DB::connection(), 'install'), ...$statements->transaction])."\n;\n\nCOMMIT;\n\n-- After the transaction commits, outside any transaction\n\n".implode("\n;\n\n", $statements->afterwards)."\n;\n",
            $printed[3],
        );
        $this->assertStringContainsString("ALTER TABLE \"listing_photos\"\n  DROP CONSTRAINT \"listing_photos_listing_id_foreign\",\n  ADD CONSTRAINT \"listing_photos_listing_id_foreign\" FOREIGN KEY (\"listing_id\") REFERENCES \"jobs\" (\"legacy_id\") ON DELETE CASCADE NOT VALID\n;", $printed[3]);
        $this->assertSame('r', DB::scalar("select relkind::text from pg_class where oid = 'listings'::regclass"));
    }

    #[Test]
    public function it_prints_a_statement_list_without_an_afterwards_section_when_nothing_runs_after_the_transaction(): void
    {
        Compatibility::install(Listing::class);

        Artisan::call('compatibility:sql', ['model' => Listing::class, '--for' => 'recompile']);
        $printed = explode("\n", Artisan::output(), 4);

        $this->assertSame('-- compatibility:sql Tests\Fixtures\Support\Listings\Listing --for=recompile: the statements Compatibility::recompile() runs for listings', $printed[0]);
        $map = Compatibility::of(Listing::class)->resolve();
        $this->assertSame("\nBEGIN;\n\n".implode("\n;\n\n", [...Statements::guard($map, DB::connection(), 'recompile'), Statements::recompile($map, DB::connection())->transaction[0]])."\n;\n\nCOMMIT;\n", $printed[3]);
    }

    #[Test]
    public function it_prints_the_query_that_counts_the_legacy_rows_a_hide_rule_hides_at_install(): void
    {
        Artisan::call('compatibility:sql', ['model' => Listing::class]);

        $this->assertStringContainsString(
            '  SELECT count(*) INTO hidden_rows FROM "listings" WHERE EXISTS (SELECT FROM "jobs" WHERE "jobs"."legacy_id" = "listings"."id" AND (COALESCE(("merged_into_job_id" is not null), false)));',
            Artisan::output(),
        );
    }

    /**
     * @return array<string, array{null|string, string}>
     */
    public static function schemaChanges(): array
    {
        return [
            'no change' => [null, 'v'],
            'a column added to the legacy table' => ['alter table listings add column notes text', 'r'],
            'an index added on a mapped column' => ['create index listings_city_index on listings (city)', 'r'],
            'a foreign key added to the legacy table' => ['create table listing_notes (listing_id integer references listings (id))', 'r'],
        ];
    }

    #[DataProvider('schemaChanges')]
    #[Test]
    public function it_runs_a_printed_install_only_while_the_schema_it_read_is_unchanged(null|string $change, string $kind): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'compatibility');
        $refusal = null;

        try {
            Artisan::call('compatibility:sql', ['model' => Listing::class, '--output' => $file]);

            if ($change !== null) {
                DB::statement($change);
            }

            try {
                DB::unprepared((string) file_get_contents($file));
            } catch (QueryException $exception) {
                DB::unprepared('ROLLBACK');
                $refusal = $exception->getMessage();
            }

            $outcome = [DB::scalar("select relkind::text from pg_class where oid = 'listings'::regclass"), $refusal === null ? null : str_contains($refusal, "Compatibility map 'listings' cannot install: listings, listings_retained, jobs, places or job_sites changed after compatibility:sql read them. Print the statements again.")];
        } finally {
            unlink($file);
            DB::statement('drop table if exists listing_notes');
        }

        $this->assertSame([$kind, $change === null ? null : true], $outcome);
    }

    #[Test]
    public function it_writes_the_statements_to_a_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'compatibility');

        try {
            $this->assertSame(0, Artisan::call('compatibility:sql', ['model' => Listing::class, '--for' => 'prepare', '--output' => $file]));
            $this->assertStringContainsString("Wrote 19 statements to {$file}.", Artisan::output());
            $this->assertStringStartsWith('-- compatibility:sql Tests\Fixtures\Support\Listings\Listing --for=prepare:', (string) file_get_contents((string) $file));
        } finally {
            unlink((string) $file);
        }
    }

    #[Test]
    public function it_leaves_the_connection_reading_from_its_replica_once_it_prints_or_refuses(): void
    {
        DB::statement('create schema compatibility_replica');
        config(['database.connections.compatibility_replicated' => [...(array) config('database.connections.pgsql'), 'read' => ['search_path' => 'compatibility_replica'], 'write' => []]]);
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $connection = 'compatibility_replicated';

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return Listing::compatibility($listing);
            }
        };

        try {
            $exits = [Artisan::call('compatibility:sql', ['model' => $model::class]), Artisan::call('compatibility:sql', ['model' => $model::class, '--for' => 'uninstall'])];
            $schema = DB::connection('compatibility_replicated')->scalar('select current_schema()');
        } finally {
            DB::purge('compatibility_replicated');
            DB::statement('drop schema compatibility_replica cascade');
        }

        $this->assertSame([[0, 1], 'compatibility_replica'], [$exits, $schema]);
    }

    #[Test]
    public function it_prints_a_map_whose_successor_names_another_connection_to_the_same_database_and_refuses_one_to_another(): void
    {
        config([
            'database.connections.compatibility_alias' => config('database.connections.pgsql'),
            'database.connections.compatibility_reporting' => [...(array) config('database.connections.pgsql'), 'database' => 'compatibility_reporting'],
        ]);
        $successors = [
            new class extends Model
            {
                protected $connection = 'compatibility_alias';

                protected $table = 'jobs';
            },
            new class extends Model
            {
                protected $connection = 'compatibility_reporting';

                protected $table = 'jobs';
            },
        ];
        $legacy = new class extends Model implements Compatible
        {
            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(CompileSqlTest::$successor, fn (Successor $job) => $job->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id')));
            }
        };
        $exits = [];

        foreach ($successors as $successor) {
            self::$successor = $successor::class;
            $exits[] = Artisan::call('compatibility:sql', ['model' => $legacy::class]);
        }

        $this->assertSame([0, 1], $exits);
        $this->assertSame("Compatibility map 'listings' is declared wrongly: the successor ".class_basename($successors[1]).' reads the connection compatibility_reporting, which reaches another database than the connection pgsql of '.class_basename($legacy).'; the view and its filters read every successor through the connection of '.class_basename($legacy).", so a map reads one database.\n", Artisan::output());
    }

    #[Test]
    #[TestWith(['"jobs"."listed_at" + make_interval(secs => 0 * nextval(\'compatibility_probe\'))', 'a sequence it draws from'])]
    #[TestWith(['CAST(coalesce(CAST("jobs"."listed_at" AS text), \'never\') AS timestamptz)', 'a value no row holds'])]
    public function it_prints_a_raw_wall_clock_read_without_evaluating_it(string $read, string $case): void
    {
        DB::statement('create sequence compatibility_probe');
        DB::statement('alter table listings add column listed_wall timestamp(0)');
        DB::statement('alter table jobs add column listed_at timestamptz');
        DB::update("update jobs set listed_at = '2026-01-01 10:00:00+00'");
        self::$read = $read;
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.'))
                        ->attribute('listed_wall', fn (Attribute $attribute) => $attribute->readAs(CompileSqlTest::$read)->readOnly(because: 'The successor records the instant.')));
            }
        };

        try {
            $exit = Artisan::call('compatibility:sql', ['model' => $model::class]);
            $sequence = (array) DB::selectOne('select last_value, is_called from compatibility_probe');
        } finally {
            DB::statement('drop sequence compatibility_probe');
        }

        $this->assertSame([0, ['last_value' => 1, 'is_called' => false]], [$exit, $sequence], $case);
        $this->assertStringContainsString('CAST((('.$read.') AT TIME ZONE \'UTC\') AS timestamp(0) without time zone) AS "listed_wall"', Artisan::output());
    }

    /**
     * @param  array<string, string>  $arguments
     */
    #[Test]
    #[TestWith([['model' => Listing::class, '--for' => 'drop'], '--for must be prepare, install, recompile or uninstall, not drop.'])]
    #[TestWith([['model' => 'Listing'], 'Listing is not a class. Name the legacy model by its full class name.'])]
    #[TestWith([['model' => Job::class], "Compatibility map 'jobs' is declared wrongly: Job does not implement Support\\Database\\Eloquent\\Compatibility\\Contracts\\Compatible, so it declares no map."])]
    #[TestWith([['model' => Listing::class, '--for' => 'uninstall'], "Compatibility map 'listings' is declared wrongly: listings is not a view, so uninstall() has nothing to reverse."])]
    public function it_exits_non_zero_with_the_reason_it_cannot_print_the_statements(array $arguments, string $refusal): void
    {
        $this->artisan('compatibility:sql', $arguments)
            ->expectsOutputToContain($refusal)
            ->doesntExpectOutputToContain('BEGIN;')
            ->assertFailed();
    }
}
