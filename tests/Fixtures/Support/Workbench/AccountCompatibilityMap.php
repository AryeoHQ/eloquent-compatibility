<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;

final class AccountCompatibilityMap implements CompatibilityMap
{
    public function name(): string
    {
        return 'accounts';
    }

    public function previousModel(): PreviousModel
    {
        return PreviousModel::servedAsView('accounts')
            ->retainedInTable('retained_accounts')
            ->forModel(Account::class)
            ->aliasedAs('account')
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
            Attribute::retained('id')->readOnlyBecause('An account keeps its id.'),
            Attribute::mapped('email')->asType('character varying(255)')->storedAs('person', 'email'),
            Attribute::retained('password_hint'),
            Attribute::retained('person_id')->readOnlyBecause('An account stays tied to its person.'),
            Attribute::mapped('created_at')->asType('timestamp(0) without time zone')->storedAs('person', 'created_at'),
            Attribute::mapped('updated_at')->asType('timestamp(0) without time zone')->storedAs('person', 'updated_at'),
        ];
    }

    public function unrepresentableStates(): array
    {
        return [];
    }
}
