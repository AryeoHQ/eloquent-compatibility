<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(CompatibilityMaps::class)]
final class CompatibilityMapsTest extends TestCase
{
    #[Test]
    public function it_holds_no_compatibility_map_until_one_is_registered(): void
    {
        $this->assertSame([], resolve(CompatibilityMaps::class)->all());
    }

    #[Test]
    public function it_returns_the_resolved_map_it_registers(): void
    {
        $resolvedMap = resolve(CompatibilityMaps::class)->register(new WidgetCompatibilityMap);

        $this->assertSame(['widgets', 'widgets', 'gadget'], [$resolvedMap->name, $resolvedMap->previousModel->view, $resolvedMap->primaryCurrentModel->alias]);
    }

    #[Test]
    public function it_lists_every_registered_map_in_the_order_it_was_registered(): void
    {
        $compatibilityMaps = resolve(CompatibilityMaps::class);
        $compatibilityMaps->register(new WidgetCompatibilityMap);
        $compatibilityMaps->register(new AccountCompatibilityMap);
        $compatibilityMaps->register(new ProfileCompatibilityMap);

        $this->assertSame(['widgets', 'accounts', 'profiles'], array_map(fn (ResolvedMap $resolvedMap) => $resolvedMap->name, $compatibilityMaps->all()));
    }

    #[Test]
    public function it_gets_a_registered_map_by_its_name(): void
    {
        $compatibilityMaps = resolve(CompatibilityMaps::class);
        $widgets = $compatibilityMaps->register(new WidgetCompatibilityMap);
        $accounts = $compatibilityMaps->register(new AccountCompatibilityMap);

        $this->assertSame($widgets, $compatibilityMaps->get('widgets'));
        $this->assertSame($accounts, $compatibilityMaps->get('accounts'));
    }

    #[Test]
    public function it_refuses_to_get_a_map_that_no_one_registered(): void
    {
        resolve(CompatibilityMaps::class)->register(new WidgetCompatibilityMap);

        $this->assertThrows(
            fn () => resolve(CompatibilityMaps::class)->get('gadgets'),
            InvalidCompatibilityMap::class,
            "Compatibility map 'gadgets' is declared wrongly: no compatibility map with this name is registered.",
        );
    }

    #[Test]
    public function it_replaces_a_map_registered_again_over_the_same_view_and_keeps_one_entry_for_it(): void
    {
        $compatibilityMaps = resolve(CompatibilityMaps::class);
        $compatibilityMaps->register(new WidgetCompatibilityMap);

        $again = $compatibilityMaps->register(new WidgetCompatibilityMap);

        $this->assertSame([$again], $compatibilityMaps->all());
        $this->assertSame($again, $compatibilityMaps->get('widgets'));
    }

    #[Test]
    public function it_refuses_a_second_map_with_the_name_of_a_registered_map_over_another_view(): void
    {
        $compatibilityMaps = resolve(CompatibilityMaps::class);
        $accounts = $compatibilityMaps->register(new AccountCompatibilityMap);

        $this->assertThrows(
            fn () => $compatibilityMaps->register($this->renamed(new ProfileCompatibilityMap, 'accounts')),
            InvalidCompatibilityMap::class,
            "Compatibility map 'accounts' is declared wrongly: another compatibility map with this name is already registered.",
        );
        $this->assertSame([$accounts], $compatibilityMaps->all());
    }

    private function renamed(CompatibilityMap $map, string $name): CompatibilityMap
    {
        return new readonly class($map, $name) implements CompatibilityMap
        {
            public function __construct(private CompatibilityMap $map, private string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function previousModel(): PreviousModel
            {
                return $this->map->previousModel();
            }

            public function primaryCurrentModel(): PrimaryCurrentModel
            {
                return $this->map->primaryCurrentModel();
            }

            public function dependentCurrentModels(): array
            {
                return $this->map->dependentCurrentModels();
            }

            public function attributes(): array
            {
                return $this->map->attributes();
            }

            public function unrepresentableStates(): array
            {
                return $this->map->unrepresentableStates();
            }
        };
    }
}
