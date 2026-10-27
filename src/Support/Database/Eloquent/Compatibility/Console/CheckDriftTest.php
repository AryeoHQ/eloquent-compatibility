<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(CheckDrift::class)]
#[CoversClass(DriftQueries::class)]
final class CheckDriftTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(CompatibilityMaps::class)->register(new WidgetCompatibilityMap);
    }

    #[Test]
    public function it_passes_when_every_record_has_its_previous_and_current_models(): void
    {
        $this->widget();
        DB::table('retained_widgets')->insert(['status' => 'Retired']);

        $this->artisan('compatibility:check', ['--map' => ['widgets']])
            ->expectsOutputToContain('No divergence in 1 compatibility map.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_names_the_record_and_the_attribute_of_each_divergence_made_by_hand(): void
    {
        $kept = $this->widget();
        $orphaned = $this->widget();
        $orphan = DB::table('retained_widgets')->where('id', $orphaned)->value('gadget_id');
        $gadget = DB::table('retained_widgets')->where('id', $kept)->value('gadget_id');
        $unlinked = DB::table('retained_widgets')->insertGetId(['status' => 'Draft']);
        $sharing = DB::table('retained_widgets')->insertGetId(['gadget_id' => $gadget]);
        DB::table('retained_widgets')->where('id', $orphaned)->delete();
        $first = DB::table('gadget_places')->where('gadget_id', $gadget)->value('id');
        DB::unprepared('ALTER TABLE gadget_places DISABLE TRIGGER USER');
        $second = DB::table('gadget_places')->insertGetId(['gadget_id' => $gadget, 'street' => 'Second root']);
        DB::unprepared('ALTER TABLE gadget_places ENABLE TRIGGER USER');

        $this->artisan('compatibility:check')
            ->expectsOutputToContain("retained_widgets#{$unlinked}")
            ->expectsOutputToContain("gadgets#{$orphan}")
            ->expectsOutputToContain("gadget_places#{$second}")
            ->expectsOutputToContain('5 divergent records found.')
            ->assertFailed();

        $this->assertSame([
            ['previous without current', "retained_widgets#{$unlinked}", 'retained_widgets.gadget_id'],
            ['orphan current', "gadgets#{$orphan}", 'retained_widgets.gadget_id'],
            ['more than one primary', "gadgets#{$gadget}", 'retained_widgets.gadget_id'],
            ['refused state', 'gadget_places#'.min($first, $second), 'second_root_place'],
            ['refused state', 'gadget_places#'.max($first, $second), 'second_root_place'],
        ], array_map(
            fn (DriftFinding $finding) => [$finding->check->value, $finding->recordId, $finding->attribute],
            resolve(DriftQueries::class)->find(resolve(CompatibilityMaps::class)->get('widgets'), 100),
        ));
        $this->assertSame($gadget, DB::table('retained_widgets')->where('id', $sharing)->value('gadget_id'));
    }

    #[Test]
    public function it_checks_only_the_compatibility_maps_it_is_given(): void
    {
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        resolve(CompatibilityMaps::class)->register(new AccountCompatibilityMap);
        DB::table('retained_widgets')->insert(['status' => 'Draft']);

        $this->artisan('compatibility:check', ['--map' => ['accounts']])
            ->expectsOutputToContain('No divergence in 1 compatibility map.')
            ->assertSuccessful();

        $this->artisan('compatibility:check')->assertFailed();
    }

    #[Test]
    public function it_fails_and_names_the_cause_when_no_compatibility_map_is_registered(): void
    {
        $this->app->instance(CompatibilityMaps::class, new CompatibilityMaps);

        $this->artisan('compatibility:check')
            ->expectsOutputToContain('No compatibility map is registered, so nothing was checked.')
            ->doesntExpectOutputToContain('No divergence')
            ->assertFailed();
    }

    private function widget(): int
    {
        return DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'street' => '1 Main St']);
    }
}
