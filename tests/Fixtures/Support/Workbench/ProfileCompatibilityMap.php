<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;

final class ProfileCompatibilityMap implements CompatibilityMap
{
    public function name(): string
    {
        return 'profiles';
    }

    public function previousModel(): PreviousModel
    {
        return PreviousModel::servedAsView('profiles')
            ->retainedInTable('retained_profiles')
            ->forModel(Profile::class)
            ->aliasedAs('profile')
            ->primaryForeignKey('person_id');
    }

    public function primaryCurrentModel(): PrimaryCurrentModel
    {
        return PrimaryCurrentModel::for(Person::class)->aliasedAs('person');
    }

    public function dependentCurrentModels(): array
    {
        return [];
    }

    public function attributes(): array
    {
        return [
            Attribute::retained('id')->readOnlyBecause('A profile keeps its id.'),
            Attribute::mapped('display_name')->asType('character varying(255)')->storedAs('person', 'display_name'),
            Attribute::mapped('email')->asType('character varying(255)')->storedAs('person', 'email'),
            Attribute::retained('bio'),
            Attribute::retained('person_id')->readOnlyBecause('A profile stays tied to its person.'),
        ];
    }

    public function unrepresentableStates(): array
    {
        return [];
    }
}
