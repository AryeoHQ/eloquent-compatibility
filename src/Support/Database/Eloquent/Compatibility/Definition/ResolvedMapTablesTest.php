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
    }

    protected function tearDown(): void
    {
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_names_the_legacy_retained_and_successor_tables_of_a_map(): void
    {
        $this->assertSame(['videos', 'videos_retained', 'files'], Compatibility::of(Video::class)->resolve()->tables());
    }

    #[Test]
    public function it_refuses_a_printed_prepare_after_a_successor_table_changed(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'compatibility');
        $refusal = null;

        try {
            Artisan::call(
                command: 'compatibility:sql',
                parameters: ['operation' => 'prepare', 'model' => Video::class, '--output' => $file],
            );
            $printed = (string) file_get_contents($file);
            DB::statement('alter table files alter column size_bytes type numeric');

            try {
                DB::unprepared(Str::before($printed, '-- After the transaction commits'));
            } catch (QueryException $exception) {
                DB::unprepared('ROLLBACK');
                $refusal = $exception->getMessage();
            }
        } finally {
            unlink($file);
        }

        $this->assertStringContainsString("Compatibility map 'videos' cannot prepare: videos, videos_retained or files changed after compatibility:sql read them. Print the statements again.", (string) $refusal);
        $this->assertStringContainsString('LOCK TABLE videos, files IN SHARE UPDATE EXCLUSIVE MODE', $printed);
        $this->assertSame(0, DB::table('information_schema.columns')->where(
            column: 'table_name',
            operator: '=',
            value: 'videos',
        )->where(
            column: 'column_name',
            operator: '=',
            value: 'file_id',
        )->count());
    }
}
