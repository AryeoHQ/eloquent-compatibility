<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(CompileSql::class)]
final class CompileSqlOutsideTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Tables::drop();
        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(CompatibilityMaps::class)->register(new WidgetCompatibilityMap);
    }

    protected function tearDown(): void
    {
        DB::purge();
        Tables::drop();

        parent::tearDown();
    }

    /** @return list<string> */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function it_reads_the_catalog_from_the_write_connection_when_the_connection_has_a_read_host(): void
    {
        $database = DB::connection()->getDatabaseName();
        config()->set('database.connections.'.DB::getDefaultConnection().'.read', ['database' => 'postgres']);
        config()->set('database.connections.'.DB::getDefaultConnection().'.write', []);
        DB::purge();

        $this->assertSame(0, Artisan::call('compatibility:sql', ['map' => 'widgets', '--for' => 'recompile']));
        $output = Artisan::output();

        $executed = [];
        DB::listen(function (QueryExecuted $query) use (&$executed): void {
            if (preg_match('/^(?:select|with recursive) /', $query->sql) !== 1) {
                $executed[] = $query->sql;
            }
        });
        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);

        [$header, $body] = explode("\n\n", $output, 2);

        $this->assertStringContainsString("-- Read from database {$database}, ", $header);
        $this->assertContains('DROP VIEW widgets', $executed);
        $this->assertSame($executed, explode("\n;\n\n", substr($body, 0, -3)));
    }
}
