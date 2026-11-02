<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\Video;
use Tests\TestCase;

#[CoversClass(ResolvedMap::class)]
final class ResolvedMapTablesTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        FileTables::create();
        FileTables::seed();
        FileTables::seedMedia();
        Compatibility::prepare(InteractiveContent::class);
    }

    protected function tearDown(): void
    {
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_names_the_tables_of_every_branch_of_a_two_successor_map(): void
    {
        $this->assertSame(['videos', 'videos_retained', 'files', 'links'], Compatibility::of(Video::class)->resolve()->tables());
    }

    #[Test]
    public function it_refuses_a_printed_prepare_of_a_two_successor_map_after_a_table_of_its_second_branch_changed(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'compatibility');
        $refusal = null;

        try {
            Artisan::call('compatibility:sql', ['model' => Video::class, '--for' => 'prepare', '--output' => $file]);
            $printed = (string) file_get_contents($file);
            DB::statement('alter table links alter column legacy_id type bigint');

            try {
                DB::unprepared(Str::before($printed, '-- After the transaction commits'));
            } catch (QueryException $exception) {
                DB::unprepared('ROLLBACK');
                $refusal = $exception->getMessage();
            }
        } finally {
            unlink($file);
        }

        $this->assertStringContainsString("Compatibility map 'videos' cannot prepare: videos, videos_retained, files or links changed after compatibility:sql read them. Print the statements again.", (string) $refusal);
        $this->assertStringContainsString('LOCK TABLE videos, files, links IN SHARE UPDATE EXCLUSIVE MODE', $printed);
        $this->assertSame('bigint', DB::scalar("select data_type from information_schema.columns where table_name = 'links' and column_name = 'legacy_id'"));
        $this->assertSame(0, DB::table('information_schema.columns')->where('table_name', 'files')->where('column_name', 'legacy_id')->count());
    }
}
