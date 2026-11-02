<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Schema\Statements;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(CompileSql::class)]
final class CompileSqlTest extends TestCase
{
    /** @var class-string<Model> */
    public static string $successor = Model::class;

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
        $statements = Statements::install(Compatibility::of(Listing::class)->resolve(), DB::connection());

        $this->assertSame(0, Artisan::call('compatibility:sql', ['model' => Listing::class]));
        $printed = explode("\n", Artisan::output(), 4);

        $this->assertSame('-- compatibility:sql Tests\Fixtures\Support\Listings\Listing --for=install: the statements Compatibility::install() runs for listings', $printed[0]);
        $this->assertMatchesRegularExpression('/^-- Read from database testing\w*, schema public \(search path .+\), on PostgreSQL \d+\.\d+/', $printed[1]);
        $this->assertMatchesRegularExpression('/^-- Compiled at \d{4}-\d{2}-\d{2}T/', $printed[2]);
        $this->assertSame(
            "\nBEGIN;\n\n".implode("\n;\n\n", $statements->transaction)."\n;\n\nCOMMIT;\n\n-- After the transaction commits, outside any transaction\n\n".implode("\n;\n\n", $statements->afterwards)."\n;\n",
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
        $this->assertSame("\nBEGIN;\n\n".Statements::recompile(Compatibility::of(Listing::class)->resolve(), DB::connection())->transaction[0]."\n;\n\nCOMMIT;\n", $printed[3]);
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

    #[Test]
    public function it_writes_the_statements_to_a_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'compatibility');

        try {
            $this->assertSame(0, Artisan::call('compatibility:sql', ['model' => Listing::class, '--for' => 'prepare', '--output' => $file]));
            $this->assertStringContainsString("Wrote 17 statements to {$file}.", Artisan::output());
            $this->assertStringStartsWith('-- compatibility:sql Tests\Fixtures\Support\Listings\Listing --for=prepare:', (string) file_get_contents((string) $file));
        } finally {
            unlink((string) $file);
        }
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

        $this->artisan('compatibility:sql', ['model' => Photo::class])
            ->expectsOutputToContain('Compatibility::install() reads the external() attribute mime_type of photos through PHP between its statements, and no printed SQL runs that step. Run Compatibility::install() instead.')
            ->doesntExpectOutputToContain('BEGIN;')
            ->assertFailed();

        $file = sys_get_temp_dir().'/compatibility-photos-'.getmypid().'.sql';

        $this->artisan('compatibility:sql', ['model' => Photo::class, '--output' => $file])
            ->expectsOutputToContain('Compatibility::install() reads the external() attribute mime_type of photos through PHP between its statements, and no printed SQL runs that step. Run Compatibility::install() instead.')
            ->assertFailed();
        $this->assertFileDoesNotExist($file);

        Compatibility::install(Photo::class);

        $this->artisan('compatibility:sql', ['model' => Photo::class, '--for' => 'uninstall'])
            ->expectsOutputToContain('Compatibility::uninstall() reads the external() attribute mime_type of photos through PHP between its statements, and no printed SQL runs that step. Run Compatibility::uninstall() instead.')
            ->doesntExpectOutputToContain('BEGIN;')
            ->assertFailed();
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
