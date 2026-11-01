<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;

/**
 * The compatibility map a Compatibility declaration compiles to. It is its own event mirror.
 */
final readonly class DefinedMap implements CompatibilityMap, EventMirror
{
    /**
     * @param  list<DependentCurrentModel>  $dependentCurrentModels
     * @param  list<Attribute>  $attributes
     * @param  list<UnrepresentableState>  $unrepresentableStates
     * @param  list<ExcludedListener>  $excludedListeners
     */
    public function __construct(
        private string $name,
        private PreviousModel $previousModel,
        private PrimaryCurrentModel $primaryCurrentModel,
        private array $dependentCurrentModels,
        private array $attributes,
        private array $unrepresentableStates,
        private array $excludedListeners,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function previousModel(): PreviousModel
    {
        return $this->previousModel;
    }

    public function primaryCurrentModel(): PrimaryCurrentModel
    {
        return $this->primaryCurrentModel;
    }

    public function dependentCurrentModels(): array
    {
        return $this->dependentCurrentModels;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function unrepresentableStates(): array
    {
        return $this->unrepresentableStates;
    }

    public function compatibilityMap(): CompatibilityMap
    {
        return $this;
    }

    public function excludedListeners(): array
    {
        return $this->excludedListeners;
    }
}
