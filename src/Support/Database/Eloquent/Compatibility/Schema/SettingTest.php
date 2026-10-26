<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(Setting::class)]
final class SettingTest extends TestCase
{
    #[Test]
    public function it_names_the_applying_flag_and_the_created_key_settings(): void
    {
        $this->assertSame(['Applying' => 'compatibility.applying', 'Created' => 'compatibility.created'], array_column(Setting::cases(), 'value', 'name'));
    }

    #[Test]
    public function it_shares_one_applying_flag_across_maps(): void
    {
        $this->assertSame(
            ['compatibility.applying', 'compatibility.applying'],
            [Setting::Applying->for(ResolvedMap::of(new WidgetCompatibilityMap)), Setting::Applying->for(ResolvedMap::of(new AccountCompatibilityMap))],
        );
    }

    #[Test]
    public function it_gives_each_map_its_own_created_key_setting(): void
    {
        $this->assertSame(
            ['compatibility.created_widgets', 'compatibility.created_accounts'],
            [Setting::Created->for(ResolvedMap::of(new WidgetCompatibilityMap)), Setting::Created->for(ResolvedMap::of(new AccountCompatibilityMap))],
        );
    }

    #[Test]
    public function it_names_settings_postgresql_accepts_as_transaction_local_custom_settings(): void
    {
        $resolvedMap = ResolvedMap::of(new WidgetCompatibilityMap);

        foreach (Setting::cases() as $setting) {
            DB::select('select set_config(?, ?, true)', [$setting->for($resolvedMap), 'widgets']);

            $this->assertSame('widgets', DB::scalar('select current_setting(?)', [$setting->for($resolvedMap)]), $setting->name);
        }
    }
}
