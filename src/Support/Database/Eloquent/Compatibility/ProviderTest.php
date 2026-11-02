<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(Provider::class)]
class ProviderTest extends TestCase
{
    #[Test]
    public function it_boots_the_provider_and_registers_the_sql_command(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(Provider::class));
        $this->assertArrayHasKey('compatibility:sql', Artisan::all());
    }

    #[Test]
    public function it_connects_to_the_postgresql_major_version_postgres_version_names(): void
    {
        $connection = DB::connection();

        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertMatchesRegularExpression('/^\d+$/', (string) Env::get('POSTGRES_VERSION'));

        $selected = (int) Env::get('POSTGRES_VERSION');
        $server = intdiv((int) $connection->scalar('show server_version_num'), 10000);

        $this->assertSame($selected, $server);
    }
}
