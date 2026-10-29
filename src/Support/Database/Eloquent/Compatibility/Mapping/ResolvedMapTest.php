<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\Compiler;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\BulletinCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Placement;
use Tests\Fixtures\Support\Workbench\Publication;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Archive;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Credit;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Grade;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Pin;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Schedule;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Tag;
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

    /**
     * @param  class-string<Model>  $model
     */
    #[Test]
    #[TestWith(['Tests\\Fixtures\\Support\\Workbench\\Nothing'])]
    #[TestWith(['\\Tests\\Fixtures\\Support\\Workbench\\Nothing'])]
    public function it_refuses_a_current_model_class_that_does_not_load(string $model): void
    {
        $this->expectInvalidMap("{$model} is not an Eloquent model", ['dependentCurrentModels' => [DependentCurrentModel::for($model)->aliasedAs('twin')->primaryForeignKey('gadget_id')]]);
    }

    #[Test]
    #[TestWith(['order'])]
    #[TestWith(['join'])]
    public function it_refuses_an_alias_that_is_a_reserved_postgresql_word(string $alias): void
    {
        $this->expectInvalidMap("alias '{$alias}' is a reserved PostgreSQL word", ['dependentCurrentModels' => [DependentCurrentModel::for(GadgetPlace::class)->aliasedAs($alias)->primaryForeignKey('gadget_id')]]);
    }

    #[Test]
    #[TestWith(['previous_row'])]
    #[TestWith(['affected_rows'])]
    #[TestWith(['created_key'])]
    #[TestWith(['key_gadget'])]
    #[TestWith(['key_anything'])]
    public function it_refuses_an_alias_that_names_a_variable_the_compiled_triggers_declare(string $alias): void
    {
        $this->expectInvalidMap("alias '{$alias}' names a variable the compiled triggers declare", ['dependentCurrentModels' => [DependentCurrentModel::for(GadgetPlace::class)->aliasedAs($alias)->primaryForeignKey('gadget_id')]]);
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
    public function it_refuses_two_read_only_attributes_stored_in_one_column_that_no_writable_attribute_owns(): void
    {
        $this->expectInvalidMap("read-only attributes 'title', 'label' are stored in gadget.name, which no writable attribute writes, so nothing says which value an insert through widgets stores", ['attributes' => [
            Attribute::retained('id'),
            Attribute::retained('gadget_id')->readOnlyBecause('Linked.'),
            Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name')->readOnlyBecause('A gadget names it.'),
            Attribute::mapped('label')->asType('text')->storedAs('gadget', 'name')->readOnlyBecause('A gadget names it.'),
        ]]);
    }

    #[Test]
    public function it_refuses_a_read_only_mapped_attribute_stored_in_the_column_of_a_read_only_retained_attribute(): void
    {
        $this->expectInvalidMap("read-only attributes 'uuid', 'public_id' are stored in widget.uuid, which no writable attribute writes", ['attributes' => [
            Attribute::retained('id'),
            Attribute::retained('gadget_id')->readOnlyBecause('Linked.'),
            Attribute::retained('uuid')->readOnlyBecause('Partners hold it.'),
            Attribute::mapped('public_id')->asType('uuid')->storedAs('widget', 'uuid')->readOnlyBecause('Partners hold it.'),
        ]]);
    }

    #[Test]
    public function it_accepts_read_only_attributes_stored_in_a_column_that_a_writable_attribute_owns(): void
    {
        $resolvedMap = ResolvedMap::of($this->compatibilityMap(['primaryCurrentModel' => $this->unindexedGadget(), 'attributes' => [
            Attribute::retained('id'),
            Attribute::retained('gadget_id')->readOnlyBecause('Linked.'),
            Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name'),
            Attribute::mapped('title_copy')->asType('text')->storedAs('gadget', 'name')->readOnlyBecause('A copy follows the title.'),
            Attribute::mapped('label')->asType('text')->storedAs('gadget', 'name')->readOnlyBecause('A label follows the title.'),
        ]]));

        $this->assertSame(['gadget_id', 'title_copy', 'label'], array_map(fn (Attribute $attribute) => $attribute->column, $resolvedMap->readOnlyAttributes()));
    }

    #[Test]
    #[TestWith(['dependent_attribute', "attribute 'place_owner_id' stores place.gadget_id, the primary foreign key of dependent current model 'place'; the compatibility map maintains it"])]
    #[TestWith(['read_only_dependent_attribute', "attribute 'place_owner_id' stores place.gadget_id, the primary foreign key of dependent current model 'place'; the compatibility map maintains it"])]
    #[TestWith(['dependent_write', "attribute 'place_owner_id' stores place.gadget_id, the primary foreign key of dependent current model 'place'; the compatibility map maintains it"])]
    #[TestWith(['dependent_fill', "dependent current model 'place' fills its primary foreign key gadget_id with fillWhenCreatedFromPrevious(); the compatibility map maintains it"])]
    #[TestWith(['retained_fill', 'the previous model fills the primary foreign key gadget_id with fillWhenCreatedFromCurrent(); the compatibility map maintains it'])]
    #[TestWith(['designation_fill', 'the previous model fills the designation column place_id with fillWhenCreatedFromCurrent(); the compatibility map maintains it'])]
    public function it_refuses_at_registration_an_attribute_or_a_fill_that_sets_a_column_linking_the_record(string $case, string $message): void
    {
        $widgets = new WidgetCompatibilityMap;
        [$place, $site] = $widgets->dependentCurrentModels();

        $overrides = [
            'dependent_attribute' => ['attributes' => [...$widgets->attributes(), Attribute::mapped('place_owner_id')->asType('uuid')->storedAs('place', 'gadget_id')]],
            'read_only_dependent_attribute' => ['attributes' => [...$widgets->attributes(), Attribute::mapped('place_owner_id')->asType('uuid')->storedAs('place', 'gadget_id')->readOnlyBecause('A place keeps its gadget.')]],
            'dependent_write' => ['attributes' => [...$widgets->attributes(), Attribute::mapped('place_owner_id')->asType('uuid')->readAs('place', 'place.gadget_id')->writesTo('place', 'gadget_id', 'NEW.place_owner_id')]],
            'dependent_fill' => ['dependentCurrentModels' => [$place->fillWhenCreatedFromPrevious(['gadget_id' => 'NEW.gadget_id']), $site]],
            'retained_fill' => ['previousModel' => $widgets->previousModel()->fillWhenCreatedFromCurrent(['gadget_id' => 'NULL'])],
            'designation_fill' => [
                'previousModel' => $widgets->previousModel()->fillWhenCreatedFromCurrent(['place_id' => 'NULL']),
                'dependentCurrentModels' => [$place->designatedBy('place_id'), $site],
            ],
        ][$case];

        $this->assertThrows(
            fn () => resolve(CompatibilityMaps::class)->register($this->compatibilityMap($overrides)),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: {$message}.",
        );
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
            fn () => (new Compiler(app('db.connection')))->compile($this->compatibilityMap(['primaryCurrentModel' => $this->unindexedGadget(), 'attributes' => $attributes])),
            InvalidCompatibilityMap::class,
            $message,
        );
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_accepts_trigger_arguments_named_in_any_case_and_keeps_the_last_call_for_a_trigger(): void
    {
        $publication = PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication')
            ->triggerArguments(Archive::class, ['until' => fn () => 'later'])
            ->triggerArguments(Archive::class, ['REASON' => fn () => 'retired']);

        $resolvedMap = ResolvedMap::of(new BulletinCompatibilityMap(publication: $publication));

        $this->assertSame(['REASON'], array_keys($resolvedMap->triggerArguments(Publication::class, Archive::class)));
        $this->assertSame([], $resolvedMap->triggerArguments(Placement::class, Archive::class));
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_trigger_arguments_for_a_class_no_transition_of_the_current_model_uses(): void
    {
        $this->expectInvalidTriggerArguments(Journal::class." is not the trigger of a #[Transition] on a state machine that current model 'publication' casts a column to", Journal::class, ['reason' => fn () => 'retired']);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_trigger_arguments_on_a_current_model_that_casts_no_column_to_the_trigger_s_state_machine(): void
    {
        $this->assertThrows(
            fn () => ResolvedMap::of(new BulletinCompatibilityMap(placement: DependentCurrentModel::for(Placement::class)->aliasedAs('placement')->primaryForeignKey('publication_id')->triggerArguments(Archive::class, ['reason' => fn () => 'retired']))),
            InvalidCompatibilityMap::class,
            Archive::class." is not the trigger of a #[Transition] on a state machine that current model 'placement' casts a column to",
        );
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_a_trigger_argument_the_constructor_does_not_declare(): void
    {
        $this->expectInvalidTriggerArguments("triggerArguments() of current model 'publication' passes 'until', which is not a constructor parameter of ".Archive::class, Archive::class, ['reason' => fn () => 'retired', 'until' => fn () => 'later']);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_two_trigger_argument_names_that_differ_only_in_case(): void
    {
        $this->expectInvalidTriggerArguments("triggerArguments() of current model 'publication' passes 'reason' more than once for ".Archive::class.', in names that differ only in case', Archive::class, ['reason' => fn () => 'retired', 'Reason' => fn () => 'withdrawn']);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_trigger_arguments_that_leave_out_a_parameter_the_container_cannot_resolve(): void
    {
        $this->expectInvalidTriggerArguments(Archive::class." requires constructor parameter 'reason', which the container cannot resolve, and triggerArguments() of current model 'publication' does not pass it", Archive::class, []);
    }

    /**
     * @param  class-string  $trigger
     */
    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    #[TestWith([Schedule::class])]
    #[TestWith([Pin::class])]
    public function it_refuses_trigger_arguments_that_leave_out_a_model_parameter_the_container_would_build_without_attributes(string $trigger): void
    {
        $this->expectInvalidTriggerArguments($trigger." requires constructor parameter 'placement', which the container cannot resolve, and triggerArguments() of current model 'publication' does not pass it", $trigger, []);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_trigger_arguments_that_leave_out_an_enum_parameter_nullable_or_not(): void
    {
        $this->expectInvalidTriggerArguments(Grade::class." requires constructor parameter 'review', which the container cannot resolve, and triggerArguments() of current model 'publication' does not pass it", Grade::class, []);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_a_trigger_argument_for_a_variadic_parameter(): void
    {
        $this->expectInvalidTriggerArguments("triggerArguments() of current model 'publication' passes 'tags', a variadic constructor parameter of ".Tag::class.', which would take the value as a single element', Tag::class, ['tags' => fn () => ['news']]);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_trigger_arguments_for_a_constructor_whose_parameter_names_differ_only_in_case(): void
    {
        $this->expectInvalidTriggerArguments(Credit::class." takes constructor parameters 'author' and 'Author', whose names differ only in case, so triggerArguments() of current model 'publication' cannot tell them apart", Credit::class, ['author' => fn () => 'Ada']);
    }

    #[Test]
    public function it_refuses_trigger_arguments_without_eloquent_state_machines(): void
    {
        if (method_exists(Trigger::class, 'withoutTransition')) { // @phpstan-ignore function.alreadyNarrowedType
            $this->markTestSkipped('aryeo/eloquent-state-machines is installed.');
        }

        $this->expectInvalidTriggerArguments("current model 'publication' declares triggerArguments(), which needs aryeo/eloquent-state-machines", Archive::class, ['reason' => fn () => 'retired']);
    }

    #[Test]
    public function it_exposes_the_primary_table_with_its_statistics_and_indexes_and_runs_no_statement(): void
    {
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $resolvedMap = ResolvedMap::of(new WidgetCompatibilityMap);
        [$index] = $resolvedMap->primaryCurrentModel->indexes;

        $this->assertSame([
            'gadgets',
            'compatibility__widgets__statistics',
            [
                'CAST(("delivered_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone)',
                'CAST(("created_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone)',
                'CAST(("updated_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone)',
                'CAST(("deleted_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone)',
            ],
            'compatibility__widgets__index_archived_newest',
            ['CAST(("archived") AS boolean)', 'CAST(("created_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone) DESC'],
            'CAST(("deleted_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone) IS NULL',
            [],
        ], [
            $resolvedMap->primaryTable(),
            $resolvedMap->statisticsName(),
            $resolvedMap->statisticsExpressions(),
            $resolvedMap->indexName($index),
            $resolvedMap->indexColumns($index),
            $resolvedMap->indexFilter($index),
            $statements,
        ]);
    }

    #[Test]
    public function it_exposes_each_planner_expression_as_the_view_reads_its_attribute_without_the_alias(): void
    {
        Tables::create();
        $resolvedMap = ResolvedMap::of(new WidgetCompatibilityMap);
        [$index] = $resolvedMap->primaryCurrentModel->indexes;
        $view = (new Compiler(app('db.connection')))->compile($resolvedMap)->view[0];
        $read = function (string $attribute) use ($view): string {
            preg_match('/^  (.+) AS "'.$attribute.'",?$/m', $view, $column);

            return str_replace('gadget.', '', $column[1] ?? '');
        };

        $this->assertSame(
            [$read('delivered_at'), $read('created_at'), $read('updated_at'), $read('deleted_at'), $read('is_archived'), $read('created_at').' DESC', $read('deleted_at').' IS NULL'],
            [...$resolvedMap->statisticsExpressions(), ...$resolvedMap->indexColumns($index), $resolvedMap->indexFilter($index)],
        );
    }

    #[Test]
    public function it_exposes_a_filter_on_an_attribute_that_is_not_null_and_none_for_an_index_without_one(): void
    {
        $delivered = PlannerIndex::named('delivered')->on('delivered_at')->whereNotNull('delivered_at');
        $titled = PlannerIndex::named('titled')->on('title');

        $resolvedMap = ResolvedMap::of($this->compatibilityMap(['primaryCurrentModel' => $this->unindexedGadget()->indexedBy($delivered, $titled)]));

        $this->assertSame([
            ['CAST(("delivered_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone)'],
            'CAST(("delivered_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone) IS NOT NULL',
            ['CAST(("name") AS character varying(255))'],
            null,
        ], [$resolvedMap->indexColumns($delivered), $resolvedMap->indexFilter($delivered), $resolvedMap->indexColumns($titled), $resolvedMap->indexFilter($titled)]);
    }

    #[Test]
    public function it_exposes_no_statistics_expression_and_no_index_for_a_map_that_declares_neither(): void
    {
        $resolvedMap = ResolvedMap::of(new AccountCompatibilityMap);

        $this->assertSame(['people', [], []], [$resolvedMap->primaryTable(), $resolvedMap->statisticsExpressions(), $resolvedMap->primaryCurrentModel->indexes]);
    }

    #[Test]
    public function it_converts_an_attribute_stored_in_the_retained_table_and_leaves_it_out_of_the_statistics(): void
    {
        $resolvedMap = ResolvedMap::of($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::mapped('retired_at')->asType('timestamp(0) without time zone')->storedAs('widget', 'retired_at')->inTimeZone('UTC'),
        ]]));

        $this->assertSame(ResolvedMap::of(new WidgetCompatibilityMap)->statisticsExpressions(), $resolvedMap->statisticsExpressions());
        $this->assertSame('widget."retired_at" AT TIME ZONE \'UTC\'', array_values(array_filter($resolvedMap->attributes, fn (Attribute $attribute) => $attribute->column === 'retired_at'))[0]->readAs);
    }

    #[Test]
    #[TestWith(['delivered_at'])]
    #[TestWith(['sku'])]
    public function it_refuses_a_time_zone_on_an_attribute_without_a_stored_column(string $attribute): void
    {
        $replaced = $attribute === 'sku'
            ? Attribute::retained('sku')->inTimeZone('UTC')
            : Attribute::mapped('delivered_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'delivered_at')->inTimeZone('UTC')->readAs('gadget', 'gadget.delivered_at')->writesTo('gadget', 'delivered_at', 'NEW.delivered_at');

        $this->expectInvalidMap("attribute '{$attribute}' says inTimeZone() without storedAs(); only a mapped attribute stored in one column converts its time zone", ['attributes' => (new WidgetCompatibilityMap([$attribute => $replaced]))->attributes()]);
    }

    #[Test]
    #[TestWith(['timestamp(0) with time zone'])]
    #[TestWith(['timestamptz'])]
    #[TestWith(['date'])]
    #[TestWith(['text'])]
    #[TestWith(['timestamp(0) without time zone) AS delivered_at, (SELECT 1'])]
    public function it_refuses_a_time_zone_on_an_attribute_whose_type_is_not_a_timestamp_without_time_zone(string $type): void
    {
        $this->expectInvalidMap("attribute 'delivered_at' says inTimeZone(), which needs asType() of a timestamp without time zone, not '{$type}'", ['attributes' => (new WidgetCompatibilityMap([
            'delivered_at' => Attribute::mapped('delivered_at')->asType($type)->storedAs('gadget', 'delivered_at')->inTimeZone('UTC'),
        ]))->attributes()]);
    }

    #[Test]
    #[TestWith(['timestamp'])]
    #[TestWith(['timestamp(3)'])]
    #[TestWith(['timestamp without time zone'])]
    #[TestWith(['TIMESTAMP (0) WITHOUT TIME ZONE'])]
    public function it_accepts_a_time_zone_on_an_attribute_of_any_timestamp_without_time_zone_type(string $type): void
    {
        $resolvedMap = ResolvedMap::of($this->compatibilityMap(['attributes' => (new WidgetCompatibilityMap([
            'delivered_at' => Attribute::mapped('delivered_at')->asType($type)->storedAs('gadget', 'delivered_at')->inTimeZone('UTC'),
        ]))->attributes()]));

        $this->assertSame("CAST((\"delivered_at\" AT TIME ZONE 'UTC') AS {$type})", $resolvedMap->statisticsExpressions()[0]);
    }

    #[Test]
    public function it_refuses_a_time_zone_on_an_attribute_stored_on_a_dependent_current_model(): void
    {
        $this->expectInvalidMap("attribute 'placed_at' says inTimeZone() but is stored on dependent current model 'place'; only a column of the primary current model or the retained table converts its time zone", ['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::mapped('placed_at')->asType('timestamp(0) without time zone')->storedAs('place', 'created_at')->inTimeZone('UTC'),
        ]]);
    }

    #[Test]
    #[TestWith(['nope', 'on', 'is on'])]
    #[TestWith(['sku', 'on', 'is on'])]
    #[TestWith(['price', 'on', 'is on'])]
    #[TestWith(['street', 'on', 'is on'])]
    #[TestWith(['retired_at', 'on', 'is on'])]
    #[TestWith(['nope', 'whereNull', 'filters on'])]
    #[TestWith(['sku', 'whereNotNull', 'filters on'])]
    #[TestWith(['price', 'whereNull', 'filters on'])]
    #[TestWith(['street', 'whereNotNull', 'filters on'])]
    #[TestWith(['retired_at', 'whereNull', 'filters on'])]
    public function it_refuses_an_index_on_an_attribute_that_is_not_mapped_and_stored_on_the_primary_current_model(string $attribute, string $declaration, string $role): void
    {
        $index = PlannerIndex::named('archived_newest')->on('is_archived');
        $index = match ($declaration) {
            'on' => $index->on($attribute),
            'whereNull' => $index->whereNull($attribute),
            default => $index->whereNotNull($attribute),
        };

        $this->expectInvalidMap("index 'archived_newest' {$role} '{$attribute}', which is not a mapped attribute that storedAs() stores on primary current model 'gadget'", [
            'primaryCurrentModel' => $this->unindexedGadget()->indexedBy($index),
            'attributes' => [
                ...(new WidgetCompatibilityMap)->attributes(),
                Attribute::mapped('retired_at')->asType('timestamp(0) without time zone')->storedAs('widget', 'retired_at'),
            ],
        ]);
    }

    #[Test]
    public function it_refuses_an_index_that_says_no_column(): void
    {
        $this->expectInvalidMap("index 'archived_newest' says no on()", ['primaryCurrentModel' => $this->unindexedGadget()->indexedBy(PlannerIndex::named('archived_newest')->whereNull('deleted_at'))]);
    }

    #[Test]
    #[TestWith(['by_newest'])]
    #[TestWith(['newest2'])]
    #[TestWith(['Newest'])]
    #[TestWith(['archived__newest'])]
    #[TestWith(['archived_'])]
    #[TestWith(['_archived'])]
    #[TestWith(['archived-newest'])]
    #[TestWith([''])]
    public function it_refuses_an_index_whose_name_is_not_full_words(string $name): void
    {
        $this->expectInvalidMap("index '{$name}' must be lowercase words of at least three letters, joined by single underscores", ['primaryCurrentModel' => $this->unindexedGadget()->indexedBy(PlannerIndex::named($name)->on('created_at'))]);
    }

    #[Test]
    public function it_refuses_two_indexes_of_one_name(): void
    {
        $this->expectInvalidMap("index 'archived_newest' is declared twice", ['primaryCurrentModel' => (new WidgetCompatibilityMap)->primaryCurrentModel()->indexedBy(PlannerIndex::named('archived_newest')->on('title'))]);
    }

    #[Test]
    public function it_refuses_an_index_whose_object_name_exceeds_63_characters(): void
    {
        $this->expectInvalidMap('the database object name compatibility__widgets__index_archived_gadgets_with_newest_first exceeds 63 characters; shorten the compatibility map or index name', [
            'primaryCurrentModel' => $this->unindexedGadget()->indexedBy(PlannerIndex::named('archived_gadgets_with_newest_first')->on('created_at')),
        ]);
    }

    #[Test]
    public function it_names_an_index_whose_object_name_has_exactly_63_characters(): void
    {
        $index = PlannerIndex::named('archived_gadget_with_newest_first')->on('created_at');

        $name = ResolvedMap::of($this->compatibilityMap(['primaryCurrentModel' => $this->unindexedGadget()->indexedBy($index)]))->indexName($index);

        $this->assertSame(['compatibility__widgets__index_archived_gadget_with_newest_first', 63], [$name, strlen($name)]);
    }

    private function unindexedGadget(): PrimaryCurrentModel
    {
        return PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget')->fillWhenCreatedFromPrevious(['id' => 'COALESCE(NEW.gadget_id, gen_random_uuid())']);
    }

    /**
     * @param  class-string  $trigger
     * @param  array<string, Closure>  $arguments
     */
    private function expectInvalidTriggerArguments(string $message, string $trigger, array $arguments): void
    {
        $publication = PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication')->triggerArguments($trigger, $arguments);

        $this->assertThrows(fn () => ResolvedMap::of(new BulletinCompatibilityMap(publication: $publication)), InvalidCompatibilityMap::class, $message);
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
                return $this->overrides['primaryCurrentModel'] ?? $this->base->primaryCurrentModel();
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
