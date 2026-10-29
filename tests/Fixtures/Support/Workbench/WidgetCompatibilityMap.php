<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\PlannerIndex;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;

final class WidgetCompatibilityMap implements CompatibilityMap
{
    /**
     * @param  array<string, Attribute>  $replacedAttributes
     */
    public function __construct(private readonly array $replacedAttributes = []) {}

    public function name(): string
    {
        return 'widgets';
    }

    public function previousModel(): PreviousModel
    {
        return PreviousModel::servedAsView('widgets')
            ->retainedInTable('retained_widgets')
            ->forModel(Widget::class)
            ->aliasedAs('widget')
            ->primaryForeignKey('gadget_id')
            ->fillWhenCreatedFromCurrent(['status' => "CASE WHEN NEW.archived THEN 'Archived' ELSE 'Draft' END"]);
    }

    public function primaryCurrentModel(): PrimaryCurrentModel
    {
        return PrimaryCurrentModel::for(Gadget::class)
            ->aliasedAs('gadget')
            ->fillWhenCreatedFromPrevious(['id' => 'COALESCE(NEW.gadget_id, gen_random_uuid())'])
            ->indexedBy(PlannerIndex::named('archived_newest')->on('is_archived')->on('created_at', descending: true)->whereNull('deleted_at'));
    }

    public function dependentCurrentModels(): array
    {
        return [
            DependentCurrentModel::for(GadgetPlace::class)
                ->aliasedAs('place')
                ->primaryForeignKey('gadget_id')
                ->readableWhen('place.parent_id IS NULL AND place.deleted_at IS NULL'),
            DependentCurrentModel::for(GadgetSite::class)
                ->aliasedAs('site')
                ->primaryForeignKey('gadget_id'),
        ];
    }

    public function attributes(): array
    {
        return array_map(fn (Attribute $attribute) => $this->replacedAttributes[$attribute->column] ?? $attribute, [
            Attribute::retained('id')->readOnlyBecause('A widget keeps the id other records and queued jobs hold.'),
            Attribute::retained('uuid')->readOnlyBecause('A widget keeps the public id partners hold.'),
            Attribute::retained('sku'),
            Attribute::retained('status'),
            Attribute::mapped('title')->asType('character varying(255)')->storedAs('gadget', 'name'),
            Attribute::mapped('price')->asType('character varying(255)')
                ->readAs('gadget', "to_char(gadget.price_cents / 100.0, 'FM999999990.00')")
                ->writesTo('gadget', 'price_cents', 'round(NEW.price::numeric * 100)'),
            Attribute::mapped('delivered_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'delivered_at')->inTimeZone('UTC'),
            Attribute::mapped('is_archived')->asType('boolean')->storedAs('gadget', 'archived'),
            Attribute::mapped('street')->asType('character varying(255)')->storedAs('place', 'street'),
            Attribute::mapped('latitude')->asType('double precision')->storedAs('site', 'latitude'),
            Attribute::retained('gadget_id')->readOnlyBecause('A widget stays tied to the gadget that stores its mapped attributes.'),
            Attribute::mapped('created_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'created_at')->inTimeZone('UTC'),
            Attribute::mapped('updated_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'updated_at')->inTimeZone('UTC'),
            Attribute::mapped('deleted_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'deleted_at')->inTimeZone('UTC'),
        ]);
    }

    public function unrepresentableStates(): array
    {
        return [
            UnrepresentableState::refuse(
                'second_root_place',
                'a second root place for one gadget',
                'A widget has one street, so a gadget may have only one place without a parent.',
                'Give the new place a parent_id, or soft-delete the current root place first.',
            )->checkedOn('place', 'place.parent_id IS NULL AND place.deleted_at IS NULL AND EXISTS (SELECT 1 FROM gadget_places other_place WHERE other_place.gadget_id = place.gadget_id AND other_place.parent_id IS NULL AND other_place.deleted_at IS NULL AND other_place.id <> place.id)'),
            UnrepresentableState::refuse(
                'archived_status',
                'a status change on an archived gadget',
                'An archived gadget has no status the widget can change.',
                'Unarchive the gadget first.',
            )->checkedOnPreviousUpdate('NEW.status IS DISTINCT FROM OLD.status AND OLD.is_archived'),
            UnrepresentableState::degrade(
                'child_place',
                'a place under another place',
                'The widget shows only the street of the root place; child places are invisible to it.',
            ),
            UnrepresentableState::hide(
                'retired',
                'a retired widget with no gadget',
                'Retired widgets predate gadgets and stay out of the widget view.',
                "widget.gadget_id IS NULL AND widget.status = 'Retired'",
            ),
        ];
    }
}
