<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;

final class ItemCompatibilityMap implements CompatibilityMap
{
    public function name(): string
    {
        return 'items';
    }

    public function previousModel(): PreviousModel
    {
        return PreviousModel::servedAsView('legacy_items')
            ->retainedInTable('retained_items')
            ->forModel(LegacyItem::class)
            ->aliasedAs('legacy')
            ->primaryForeignKey('item_id');
    }

    public function primaryCurrentModel(): PrimaryCurrentModel
    {
        return PrimaryCurrentModel::for(GadgetItem::class)->aliasedAs('item');
    }

    public function dependentCurrentModels(): array
    {
        return [];
    }

    public function attributes(): array
    {
        return [
            Attribute::retained('id')->readOnlyBecause('A legacy item keeps its id.'),
            Attribute::mapped('label')->asType('character varying(255)')->storedAs('item', 'label'),
            Attribute::retained('item_id')->readOnlyBecause('A legacy item stays tied to its item.'),
        ];
    }

    public function unrepresentableStates(): array
    {
        return [];
    }
}
