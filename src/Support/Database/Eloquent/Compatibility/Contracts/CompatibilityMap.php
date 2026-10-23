<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;

interface CompatibilityMap
{
    public function name(): string;

    public function previousModel(): PreviousModel;

    public function primaryCurrentModel(): PrimaryCurrentModel;

    /** @return list<DependentCurrentModel> */
    public function dependentCurrentModels(): array;

    /** @return list<Attribute> */
    public function attributes(): array;

    /** @return list<UnrepresentableState> */
    public function unrepresentableStates(): array;
}
