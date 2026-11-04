<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final class Successor
{
    public readonly string $table;

    public readonly string $key;

    /** @var array<string, Attribute> */
    private array $attributes = [];

    /** @var list<Related> */
    private array $related = [];

    private null|RelatedMorph $morph = null;

    private null|string $atMostOne = null;

    private null|Closure $when = null;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(private readonly string $map, public readonly string $model)
    {
        $instance = new $model;
        $this->table = $instance->getTable();
        $this->key = $instance->getKeyName();
    }

    /**
     * @param  Closure(Attribute): mixed  $definition
     */
    public function attribute(string $name, Closure $definition): self
    {
        if ($name === '') {
            throw InvalidCompatibilityMap::because($this->map, 'an attribute of '.class_basename($this->model).' has no name');
        }

        if (array_key_exists($name, $this->attributes)) {
            throw InvalidCompatibilityMap::because($this->map, "attribute '{$name}' is declared twice");
        }

        ResolvedMap::refuseCaptures($this->map, "the closure of attribute '{$name}'", $definition);

        $attribute = new Attribute($this->map, $name);
        $definition($attribute);
        $this->attributes[$name] = $attribute;

        return $this;
    }

    /**
     * @param  Closure(Successor): mixed  $definition
     */
    public function related(Closure $relation, Closure $definition): self
    {
        $this->related[] = Related::compile($this->map, $this->model, $relation, $definition);

        return $this;
    }

    /**
     * @param  Closure(Successors): mixed  $successors
     */
    public function relatedMorph(Closure $relation, Closure $successors, null|Closure $choose = null): self
    {
        if ($this->morph !== null) {
            throw InvalidCompatibilityMap::because($this->map, 'relatedMorph() on '.class_basename($this->model).' is declared twice; one legacy record reads one MorphTo of its successor');
        }

        $this->morph = RelatedMorph::compile($this->map, $this->model, $relation, $successors, $choose);

        return $this;
    }

    public function atMostOne(string $because): self
    {
        if (trim($because) === '') {
            throw InvalidCompatibilityMap::because($this->map, 'atMostOne() on '.class_basename($this->model).' gives no reason; it takes the reason a second related row is refused');
        }

        $this->atMostOne = $because;

        return $this;
    }

    public function when(Closure $scope): self
    {
        if ($this->when !== null) {
            throw InvalidCompatibilityMap::because($this->map, 'when() on '.class_basename($this->model).' is declared twice');
        }

        ResolvedMap::refuseCaptures($this->map, 'the when() scope on '.class_basename($this->model), $scope);

        $this->when = $scope;

        return $this;
    }

    /**
     * @return array<string, Attribute>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return list<Related>
     */
    public function relations(): array
    {
        return $this->related;
    }

    public function morph(): null|RelatedMorph
    {
        return $this->morph;
    }

    public function atMostOneBecause(): null|string
    {
        return $this->atMostOne;
    }

    public function membershipScope(): null|Closure
    {
        return $this->when;
    }
}
