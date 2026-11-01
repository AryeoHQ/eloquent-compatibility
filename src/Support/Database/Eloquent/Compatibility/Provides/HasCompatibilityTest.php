<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Write\CompatibleBuilder;
use Support\Database\Eloquent\Compatibility\Write\RoutesCompatibleWrites;
use Support\Database\Eloquent\Compatibility\Write\WriteRouter;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversTrait(HasCompatibility::class)]
#[CoversTrait(RoutesCompatibleWrites::class)]
final class HasCompatibilityTest extends TestCase
{
    #[Test]
    public function it_gives_a_model_that_names_no_builder_the_compatible_builder(): void
    {
        $this->assertInstanceOf(CompatibleBuilder::class, Widget::query());
        $this->assertInstanceOf(CompatibleBuilder::class, (new Gadget)->newQueryWithoutScopes());
    }

    #[Test]
    public function it_keeps_a_custom_builder_that_routes_its_writes(): void
    {
        $this->assertInstanceOf(RoutingGadgetBuilder::class, RoutedGadget::query());
    }

    #[Test]
    public function it_refuses_a_custom_builder_that_would_bypass_the_write_router(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(BypassingGadget::class.' uses HasCompatibility, and its Eloquent builder '.BypassingBuilder::class.' does not use '.RoutesCompatibleWrites::class);

        BypassingGadget::query();
    }

    #[Test]
    public function it_writes_natively_while_no_compatibility_map_is_registered(): void
    {
        Tables::create();
        $this->assertFalse($this->app->resolved(WriteRouter::class));

        $gadget = Gadget::query()->create(['name' => 'Unmapped']);
        $gadget->update(['name' => 'Still unmapped']);

        $this->assertSame('Still unmapped', DB::table('gadgets')->value('name'));
        $this->assertSame(0, DB::table('retained_widgets')->count());
    }

    #[Test]
    public function it_routes_every_write_method_a_model_or_its_builder_offers(): void
    {
        $routed = ['insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'insertOrIgnoreUsing', 'upsert', 'update', 'touch', 'increment', 'decrement', 'incrementEach', 'decrementEach', 'delete', 'forceDelete'];
        $declared = array_map(fn (\ReflectionMethod $method) => $method->getName(), (new ReflectionClass(RoutesCompatibleWrites::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        $this->assertSame([], array_values(array_diff($routed, $declared)));
    }

    #[Test]
    public function it_routes_a_models_own_write_and_its_query_builder_writes_through_the_registered_map(): void
    {
        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror(new WidgetCompatibilityMap));

        $widget = Widget::query()->create(['title' => 'Own write']);
        $widget->touch();
        Widget::query()->whereKey($widget->id)->touch();
        Widget::query()->whereKey($widget->id)->update(['title' => 'Builder write']);

        $this->assertSame('Builder write', DB::table('gadgets')->value('name'));
        $this->assertSame(1, DB::table('retained_widgets')->count());
    }
}

/**
 * @extends Builder<RoutedGadget>
 */
final class RoutingGadgetBuilder extends Builder
{
    use RoutesCompatibleWrites;
}

#[UseEloquentBuilder(RoutingGadgetBuilder::class)]
final class RoutedGadget extends Model implements Compatible
{
    use HasCompatibility;

    protected $table = 'gadgets';
}

final class BypassingGadget extends Model implements Compatible
{
    use HasCompatibility;

    protected $table = 'gadgets';

    protected static string $builder = BypassingBuilder::class;
}

/**
 * @extends Builder<BypassingGadget>
 */
final class BypassingBuilder extends Builder {}
