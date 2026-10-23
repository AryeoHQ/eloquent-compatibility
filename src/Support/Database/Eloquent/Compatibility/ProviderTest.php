<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Bridge\EventBridge;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(Provider::class)]
class ProviderTest extends TestCase
{
    #[Test]
    public function it_boots_the_provider(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(Provider::class));
    }

    #[Test]
    public function it_connects_to_the_postgresql_major_version_the_host_names(): void
    {
        $connection = DB::connection();

        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertMatchesRegularExpression('/^postgres\d+$/', $connection->getConfig('host'));

        $selected = (int) substr($connection->getConfig('host'), strlen('postgres'));
        $server = intdiv((int) $connection->scalar('show server_version_num'), 10000);

        $this->assertSame($selected, $server);
    }

    #[Test]
    public function it_shares_one_set_of_compatibility_maps_and_one_event_bridge_across_the_application(): void
    {
        $this->assertSame(resolve(CompatibilityMaps::class), resolve(CompatibilityMaps::class));
        $this->assertSame(resolve(EventBridge::class), resolve(EventBridge::class));
    }

    #[Test]
    public function it_registers_the_divergence_check(): void
    {
        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(CompatibilityMaps::class)->register(new WidgetCompatibilityMap);

        $this->artisan('compatibility:check', ['--map' => ['widgets']])
            ->expectsOutputToContain('No divergence in 1 compatibility map.')
            ->assertSuccessful();
    }
}
