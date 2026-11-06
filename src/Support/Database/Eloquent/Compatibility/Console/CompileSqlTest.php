<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Schema\InstallerTest;
use Support\Database\Eloquent\Compatibility\Schema\Statements;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\ListingTitles;
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
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_prints_the_statements_install_runs_and_runs_none_of_them(): void
    {
        $map = Compatibility::of(Listing::class)->resolve();
        $statements = Statements::install($map, DB::connection());

        $this->assertSame(0, Artisan::call(
            command: 'compatibility:sql',
            parameters: ['operation' => 'install', 'model' => Listing::class],
        ));
        [$header, $body] = explode(
            separator: "\n\nBEGIN;\n\n",
            string: Artisan::output(),
            limit: 2,
        );
        $printed = explode(
            separator: "\n",
            string: $header,
        );

        $this->assertSame('-- compatibility:sql install Tests\Fixtures\Support\Listings\Listing: the statements Compatibility::install() runs for listings', $printed[0]);
        $this->assertMatchesRegularExpression('/^-- Read from database testing\w*, schema public \(search path .+\), on PostgreSQL \d+\.\d+/', $printed[1]);
        $this->assertMatchesRegularExpression('/^-- Compiled at \d{4}-\d{2}-\d{2}T/', $printed[2]);
        $this->assertSame(array_map(
            callback: fn (array $lock) => "-- Locks {$lock[0]} in {$lock[1]}",
            array: $statements->locks,
        ), array_values(array_filter(
            array: $printed,
            callback: fn (string $line) => str_starts_with(
                haystack: $line,
                needle: '-- Locks ',
            ),
        )));
        $this->assertSame(implode(
            separator: "\n;\n\n",
            array: [...Statements::guard(
                map: $map,
                connection: DB::connection(),
                operation: 'install',
            ), ...$statements->transaction],
        )."\n;\n\nCOMMIT;\n", $body);
        $this->assertStringEndsWith("ALTER TABLE \"listings_retained\" ADD CONSTRAINT \"listings_retained_job_id_foreign\" FOREIGN KEY (\"job_id\") REFERENCES \"jobs\" (\"id\") ON DELETE CASCADE NOT VALID\n;\n\nCOMMIT;\n", $body);
        $this->assertSame('r', DB::scalar("select relkind::text from pg_class where oid = 'listings'::regclass"));
    }

    #[Test]
    public function it_prints_each_dependent_and_where_each_hook_of_dependents_runs_in_the_install(): void
    {
        DB::statement(ListingTitles::VIEW);
        $model = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return Listing::compatibility($listing)->dependents(ListingTitles::class);
            }
        };

        try {
            Artisan::call(
                command: 'compatibility:sql',
                parameters: ['operation' => 'install', 'model' => $model::class],
            );
            [$header, $body] = explode(
                separator: "\n\nBEGIN;\n\n",
                string: Artisan::output(),
                limit: 2,
            );
        } finally {
            DB::statement('drop view listing_titles');
        }

        $printed = explode(
            separator: "\n\n",
            string: $body,
        );
        $before = array_search(
            needle: '-- ListingTitles::beforeInstall() runs here, inside the transaction',
            haystack: $printed,
            strict: true,
        );
        $after = array_search(
            needle: '-- ListingTitles::afterInstall() runs here, inside the transaction',
            haystack: $printed,
            strict: true,
        );

        $this->assertContains('-- view listing_titles depends on listings', explode(
            separator: "\n",
            string: $header,
        ));
        $this->assertIsInt($before);
        $this->assertIsInt($after);
        $this->assertStringStartsWith("SELECT set_config('compatibility.grants'", $printed[$before - 1]);
        $this->assertStringContainsString('EXECUTE granted;', $printed[$after - 1]);
        $this->assertStringStartsWith('ALTER TABLE "listings" RENAME TO "listings_retained"', $printed[$before + 1]);
    }

    #[Test]
    public function it_prints_the_legacy_only_data_retire_discards(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        DB::statement('drop table listing_photos, listing_shares');

        $this->assertSame(0, Artisan::call(
            command: 'compatibility:sql',
            parameters: ['operation' => 'retire', 'model' => Listing::class],
        ));

        $this->assertSame([
            '-- retire() discards listings_retained: 3 rows, with the legacy-only columns uuid, mls_number, featured',
            '-- retire() discards listings_backfill, the backfill ledger of listings',
            '-- retire() discards the compatibility_backfills row of listings',
        ], array_slice(
            array: explode(
                separator: "\n",
                string: explode(
                    separator: "\n\nBEGIN;\n\n",
                    string: Artisan::output(),
                    limit: 2,
                )[0],
            ),
            offset: 3,
        ));
    }

    #[DataProviderExternal(InstallerTest::class, 'dependents')]
    #[Test]
    public function it_prints_each_object_that_depends_on_the_legacy_table_in_the_prepare_header(string $create, string $drop, string $dependent): void
    {
        DB::statement($create);

        try {
            Artisan::call(
                command: 'compatibility:sql',
                parameters: ['operation' => 'prepare', 'model' => Listing::class],
            );
            $printed = Artisan::output();
        } finally {
            DB::statement($drop);
        }

        $this->assertContains("-- {$dependent} depends on listings", explode(
            separator: "\n",
            string: explode(
                separator: "\n\nBEGIN;\n\n",
                string: $printed,
                limit: 2,
            )[0],
        ));
    }

    #[Test]
    public function it_prints_a_statement_list_without_an_afterwards_section_when_nothing_runs_after_the_transaction(): void
    {
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        Artisan::call(
            command: 'compatibility:sql',
            parameters: ['operation' => 'update', 'model' => Listing::class],
        );
        [$header, $body] = explode(
            separator: "\n\nBEGIN;\n\n",
            string: Artisan::output(),
            limit: 2,
        );

        $this->assertSame('-- compatibility:sql update Tests\Fixtures\Support\Listings\Listing: the statements Compatibility::update() runs for listings', explode(
            separator: "\n",
            string: $header,
        )[0]);
        $map = Compatibility::of(Listing::class)->resolve();
        $this->assertSame(implode(
            separator: "\n;\n\n",
            array: [...Statements::guard(
                map: $map,
                connection: DB::connection(),
                operation: 'update',
            ), Statements::update(
                map: $map,
                connection: DB::connection(),
            )->transaction[0]],
        )."\n;\n\nCOMMIT;\n", $body);
    }

    #[Test]
    public function it_prints_the_query_that_counts_the_legacy_rows_a_hide_rule_hides_at_install(): void
    {
        Artisan::call(
            command: 'compatibility:sql',
            parameters: ['operation' => 'install', 'model' => Listing::class],
        );

        $this->assertStringContainsString(
            '  SELECT count(*) INTO hidden_rows FROM "listings" WHERE EXISTS (SELECT FROM "jobs" WHERE "jobs"."id" = "listings"."job_id" AND (COALESCE(("merged_into_job_id" is not null), false)));',
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
            Artisan::call(
                command: 'compatibility:sql',
                parameters: ['operation' => 'install', 'model' => Listing::class, '--output' => $file],
            );

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
    public function it_runs_a_printed_update_only_while_the_root_it_read_is_unchanged(): void
    {
        FileTables::create();
        FileTables::seedMedia();
        FileTables::installMedia();
        $file = (string) tempnam(sys_get_temp_dir(), 'compatibility');
        $refusal = null;

        try {
            Artisan::call(
                command: 'compatibility:sql',
                parameters: ['operation' => 'update', 'model' => Video::class, '--output' => $file],
            );
            DB::statement('alter table files add column notes text');

            try {
                DB::unprepared((string) file_get_contents($file));
            } catch (QueryException $exception) {
                DB::unprepared('ROLLBACK');
                $refusal = $exception->getMessage();
            }
        } finally {
            unlink($file);
        }

        $this->assertStringContainsString("Compatibility map 'videos' cannot update: videos, videos_retained or files changed after compatibility:sql read them. Print the statements again.", (string) $refusal);
    }

    #[Test]
    public function it_writes_the_statements_to_a_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'compatibility');

        try {
            $this->assertSame(0, Artisan::call(
                command: 'compatibility:sql',
                parameters: ['operation' => 'prepare', 'model' => Listing::class, '--output' => $file],
            ));
            $this->assertStringContainsString("Wrote 12 statements to {$file}.", Artisan::output());
            $this->assertStringStartsWith('-- compatibility:sql prepare Tests\Fixtures\Support\Listings\Listing:', (string) file_get_contents((string) $file));
        } finally {
            unlink((string) $file);
        }
    }

    #[Test]
    public function it_prints_the_view_update_replaces_from_the_retained_table_and_its_root(): void
    {
        FileTables::create();
        FileTables::seedMedia();
        FileTables::installMedia();

        $exit = Artisan::call(
            command: 'compatibility:sql',
            parameters: ['operation' => 'update', 'model' => Video::class],
        );
        $printed = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("COMMIT;\n", $printed);
        $this->assertStringContainsString('CREATE OR REPLACE VIEW "videos" AS', $printed);
        $this->assertStringContainsString('FROM "videos_retained"'."\n".'JOIN "files" ON "files"."id" = "videos_retained"."file_id"', $printed);
        $this->assertStringNotContainsString('UNION ALL', (string) strstr(
            haystack: $printed,
            needle: 'CREATE OR REPLACE VIEW',
        ));
    }

    #[Test]
    public function it_refuses_to_print_an_operation_that_reads_an_external_attribute_between_its_statements(): void
    {
        FileTables::create();
        FileTables::seed();

        foreach (FileTables::MODELS as $model) {
            Compatibility::prepare($model);
        }

        FileTables::backfill();

        $this->artisan(
            command: 'compatibility:sql',
            parameters: ['operation' => 'install', 'model' => Photo::class],
        )
            ->expectsOutputToContain('Compatibility::install() reads the external() attribute mime_type of photos through PHP between its statements, and no printed SQL runs that step. Run Compatibility::install() instead.')
            ->doesntExpectOutputToContain('BEGIN;')
            ->assertFailed();

        $file = sys_get_temp_dir().'/compatibility-photos-'.getmypid().'.sql';

        $this->artisan(
            command: 'compatibility:sql',
            parameters: ['operation' => 'install', 'model' => Photo::class, '--output' => $file],
        )
            ->expectsOutputToContain('Compatibility::install() reads the external() attribute mime_type of photos through PHP between its statements, and no printed SQL runs that step. Run Compatibility::install() instead.')
            ->assertFailed();
        $this->assertFileDoesNotExist($file);

        Compatibility::install(
            model: Photo::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->artisan(
            command: 'compatibility:sql',
            parameters: ['operation' => 'rollback', 'model' => Photo::class],
        )
            ->expectsOutputToContain('Compatibility::rollback() reads the external() attribute mime_type of photos through PHP between its statements, and no printed SQL runs that step. Run Compatibility::rollback() instead.')
            ->doesntExpectOutputToContain('BEGIN;')
            ->assertFailed();
    }

    #[Test]
    public function it_refuses_to_print_an_install_that_catches_up_a_backfill_and_deletes_the_successor_rows_of_hard_deleted_legacy_rows(): void
    {
        $refusal = "Compatibility map 'listings' cannot install: the last compatibility:backfill of listings left a watermark, and Compatibility::install() catches up the legacy writes since it and deletes the successor rows of hard-deleted legacy rows before it takes the lock, and no printed SQL runs that catch-up. Run Compatibility::install() instead.";
        (new Backfiller(Compatibility::of(Listing::class)->resolve(), DB::connection()))->run();
        DB::delete('delete from listings where id = 3');

        $this->artisan(
            command: 'compatibility:sql',
            parameters: ['operation' => 'install', 'model' => Listing::class],
        )
            ->expectsOutputToContain($refusal)
            ->doesntExpectOutputToContain('BEGIN;')
            ->assertFailed();

        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::rollback(Listing::class);
        Compatibility::prepare(Listing::class);
        (new Backfiller(
            map: Compatibility::of(Listing::class)->resolve(),
            connection: DB::connection(),
        ))->run();

        $this->artisan(
            command: 'compatibility:sql',
            parameters: ['operation' => 'install', 'model' => Listing::class],
        )
            ->expectsOutputToContain($refusal)
            ->doesntExpectOutputToContain('BEGIN;')
            ->assertFailed();
        $this->assertSame([1, 2], array_map(
            callback: intval(...),
            array: array_column(
                array: DB::select('select listings.id from jobs join listings on listings.job_id = jobs.id order by listings.id'),
                column_key: 'id',
            ),
        ));
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
            $exits = [Artisan::call(
                command: 'compatibility:sql',
                parameters: ['operation' => 'install', 'model' => $model::class],
            ), Artisan::call(
                command: 'compatibility:sql',
                parameters: ['operation' => 'update', 'model' => $model::class],
            )];
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
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: CompileSqlTest::$successor, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
                        name: 'title',
                        definition: fn (Attribute $attribute) => $attribute->from('name'),
                    )->attribute(
                        name: 'company_id',
                        definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                    ));
            }
        };
        $exits = [];

        foreach ($successors as $successor) {
            self::$successor = $successor::class;
            $exits[] = Artisan::call(
                command: 'compatibility:sql',
                parameters: ['operation' => 'install', 'model' => $legacy::class],
            );
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
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.'),
                        )
                        ->attribute(
                            name: 'listed_wall',
                            definition: fn (Attribute $attribute) => $attribute->readAs(CompileSqlTest::$read)->readOnly(because: 'The successor records the instant.'),
                        ));
            }
        };

        try {
            $exit = Artisan::call(
                command: 'compatibility:sql',
                parameters: ['operation' => 'install', 'model' => $model::class],
            );
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
    #[TestWith([['operation' => 'drop', 'model' => Listing::class], 'The operation must be prepare, install, update, rollback or retire, not drop.'])]
    #[TestWith([['operation' => 'install', 'model' => 'Listing'], 'Listing is not a class. Name the legacy model by its full class name.'])]
    #[TestWith([['operation' => 'install', 'model' => Job::class], "Compatibility map 'jobs' is declared wrongly: Job does not implement Support\\Database\\Eloquent\\Compatibility\\Contracts\\Compatible, so it declares no map."])]
    #[TestWith([['operation' => 'update', 'model' => Listing::class], "Compatibility map 'listings' is declared wrongly: listings is not a view, so update() has nothing to replace; install() creates it."])]
    public function it_exits_non_zero_with_the_reason_it_cannot_print_the_statements(array $arguments, string $refusal): void
    {
        $this->artisan('compatibility:sql', $arguments)
            ->expectsOutputToContain($refusal)
            ->doesntExpectOutputToContain('BEGIN;')
            ->assertFailed();
    }
}
