<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\Compiler;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(ResolvedMap::class)]
#[CoversClass(Attribute::class)]
final class ResolvedMapTest extends TestCase
{
    #[Test]
    public function it_resolves_a_retained_attribute_to_the_retained_table(): void
    {
        $resolvedMap = ResolvedMap::of(new WidgetCompatibilityMap);

        $sku = array_values(array_filter($resolvedMap->attributes, fn (Attribute $attribute) => $attribute->column === 'sku'))[0];

        $this->assertSame(['widget', 'widget."sku"', 'sku'], [$sku->alias, $sku->readAs, $sku->storedAs]);
        $this->assertSame([['widget', 'sku', 'NEW."sku"']], array_map(fn (TargetColumn $targetColumn) => [$targetColumn->alias, $targetColumn->column, $targetColumn->expression], $sku->writesTo));
        $this->assertSame('gadgets', $resolvedMap->table('gadget'));
    }

    #[Test]
    #[TestWith(['Widgets'])]
    #[TestWith(['wide__gadgets'])]
    #[TestWith(['gadgets_'])]
    #[TestWith(['a_name_much_longer_than_24'])]
    public function it_refuses_a_name_that_cannot_prefix_database_objects(string $name): void
    {
        $this->expectInvalidMap('its name must be at most 24 lowercase letters, digits and single underscores', ['name' => $name]);
    }

    #[Test]
    public function it_refuses_an_alias_used_twice(): void
    {
        $this->expectInvalidMap("alias 'gadget' is used twice", ['dependentCurrentModels' => [DependentCurrentModel::for(GadgetPlace::class)->aliasedAs('gadget')->primaryForeignKey('gadget_id')]]);
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[Test]
    #[TestWith(['gadget', Gadget::class])]
    #[TestWith(['place', GadgetPlace::class])]
    public function it_refuses_two_current_models_of_one_class(string $first, string $model): void
    {
        $this->expectInvalidMap("aliases '{$first}' and 'twin' both name current model {$model}; each current model class has one alias", ['dependentCurrentModels' => [
            ...(new WidgetCompatibilityMap)->dependentCurrentModels(),
            DependentCurrentModel::for($model)->aliasedAs('twin')->primaryForeignKey('gadget_id'),
        ]]);
    }

    #[Test]
    #[TestWith(['order'])]
    #[TestWith(['join'])]
    public function it_refuses_an_alias_that_is_a_reserved_postgresql_word(string $alias): void
    {
        $this->expectInvalidMap("alias '{$alias}' is a reserved PostgreSQL word", ['dependentCurrentModels' => [DependentCurrentModel::for(GadgetPlace::class)->aliasedAs($alias)->primaryForeignKey('gadget_id')]]);
    }

    #[Test]
    public function it_refuses_a_dependent_current_model_with_no_primary_foreign_key(): void
    {
        $this->expectInvalidMap("dependent current model 'place' says no primaryForeignKey()", ['dependentCurrentModels' => [DependentCurrentModel::for(GadgetPlace::class)->aliasedAs('place')]]);
    }

    #[Test]
    #[TestWith(['retainedInTable'])]
    #[TestWith(['forModel'])]
    #[TestWith(['aliasedAs'])]
    #[TestWith(['primaryForeignKey'])]
    public function it_refuses_a_previous_model_that_skips_a_required_builder(string $skipped): void
    {
        $calls = [
            'retainedInTable' => fn (PreviousModel $previous) => $previous->retainedInTable('retained_widgets'),
            'forModel' => fn (PreviousModel $previous) => $previous->forModel(Widget::class),
            'aliasedAs' => fn (PreviousModel $previous) => $previous->aliasedAs('widget'),
            'primaryForeignKey' => fn (PreviousModel $previous) => $previous->primaryForeignKey('gadget_id'),
        ];
        unset($calls[$skipped]);

        $this->expectInvalidMap("the previous model says no {$skipped}()", [
            'previousModel' => array_reduce($calls, fn (PreviousModel $previous, callable $call) => $call($previous), PreviousModel::servedAsView('widgets')),
        ]);
    }

    #[Test]
    public function it_refuses_a_mapped_attribute_without_a_type_when_the_map_is_registered(): void
    {
        $this->assertThrows(
            fn () => resolve(CompatibilityMaps::class)->register($this->compatibilityMap(['attributes' => [
                Attribute::retained('id'),
                Attribute::retained('gadget_id')->readOnlyBecause('Linked.'),
                Attribute::mapped('title')->storedAs('gadget', 'name'),
            ]])),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: mapped attribute 'title' says no asType().",
        );
    }

    #[Test]
    public function it_refuses_a_mapped_attribute_that_names_no_owner(): void
    {
        $this->expectInvalidMap("mapped attribute 'title' says neither storedAs() nor readAs()", ['attributes' => [Attribute::retained('id'), Attribute::mapped('title')->asType('text')]]);
    }

    #[Test]
    public function it_refuses_a_mapped_attribute_whose_writes_through_the_previous_model_would_be_lost(): void
    {
        $this->expectInvalidMap("attribute 'title' declares no write and is not read-only", ['attributes' => [Attribute::retained('id'), Attribute::mapped('title')->asType('text')->readAs('gadget', 'gadget.name')]]);
    }

    #[Test]
    public function it_refuses_two_attributes_that_own_one_stored_column(): void
    {
        $this->expectInvalidMap("attributes 'title' and 'label' both write gadget.name", ['attributes' => [
            Attribute::retained('id'),
            Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name'),
            Attribute::mapped('label')->asType('text')->storedAs('gadget', 'name'),
        ]]);
    }

    #[Test]
    public function it_refuses_a_write_to_an_unknown_alias(): void
    {
        $this->expectInvalidMap("attribute 'title' writes unknown alias 'unknown'", ['attributes' => [Attribute::retained('id'), Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name')->writesTo('unknown', 'name', 'NEW.title')]]);
    }

    #[Test]
    public function it_refuses_a_map_whose_previous_model_key_is_not_an_attribute(): void
    {
        $this->expectInvalidMap("the previous model's key 'id' is not an attribute", ['attributes' => [Attribute::retained('sku')]]);
    }

    #[Test]
    public function it_refuses_a_map_whose_primary_foreign_key_is_not_an_attribute(): void
    {
        $this->expectInvalidMap("the primary foreign key 'gadget_id' is not an attribute", ['attributes' => [Attribute::retained('id')]]);
    }

    #[Test]
    public function it_refuses_a_refused_state_that_names_no_state_to_check(): void
    {
        $this->expectInvalidMap("refused state 'never' says neither checkedOn() nor checkedOnPreviousUpdate()", ['unrepresentableStates' => [UnrepresentableState::refuse('never', 'a state', 'because', 'instead')]]);
    }

    /**
     * @param  array{attributes: string}  $case
     */
    #[Test]
    #[TestWith([['attributes' => 'missing_column'], 'gadgets has no column nope'])]
    #[TestWith([['attributes' => 'missing_retained'], 'retained attribute nope is not a column of retained_widgets'])]
    #[TestWith([['attributes' => 'written_primary_foreign_key'], 'attribute gadget_id writes the primary foreign key gadget_id; declare it read-only'])]
    public function it_refuses_an_attribute_the_tables_cannot_store(array $case, string $message): void
    {
        Tables::create();
        $attributes = [
            'missing_column' => [Attribute::retained('id'), Attribute::retained('gadget_id')->readOnlyBecause('Linked.'), Attribute::mapped('title')->asType('text')->storedAs('gadget', 'nope')],
            'missing_retained' => [Attribute::retained('id'), Attribute::retained('gadget_id')->readOnlyBecause('Linked.'), Attribute::retained('nope')],
            'written_primary_foreign_key' => [Attribute::retained('id'), Attribute::retained('gadget_id')],
        ][$case['attributes']];

        $this->assertThrows(
            fn () => (new Compiler(app('db.connection')))->compile($this->compatibilityMap(['attributes' => $attributes])),
            InvalidCompatibilityMap::class,
            $message,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function expectInvalidMap(string $message, array $overrides): void
    {
        $this->assertThrows(fn () => ResolvedMap::of($this->compatibilityMap($overrides)), InvalidCompatibilityMap::class, $message);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function compatibilityMap(array $overrides): CompatibilityMap
    {
        return new readonly class(new WidgetCompatibilityMap, $overrides) implements CompatibilityMap
        {
            /**
             * @param  array<string, mixed>  $overrides
             */
            public function __construct(private WidgetCompatibilityMap $base, private array $overrides) {}

            public function name(): string
            {
                return $this->overrides['name'] ?? $this->base->name();
            }

            public function previousModel(): PreviousModel
            {
                return $this->overrides['previousModel'] ?? $this->base->previousModel();
            }

            public function primaryCurrentModel(): PrimaryCurrentModel
            {
                return $this->base->primaryCurrentModel();
            }

            public function dependentCurrentModels(): array
            {
                return $this->overrides['dependentCurrentModels'] ?? $this->base->dependentCurrentModels();
            }

            public function attributes(): array
            {
                return $this->overrides['attributes'] ?? $this->base->attributes();
            }

            public function unrepresentableStates(): array
            {
                return $this->overrides['unrepresentableStates'] ?? $this->base->unrepresentableStates();
            }
        };
    }
}
