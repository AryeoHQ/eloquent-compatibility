<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final class Targets
{
    /** @var array<class-string<Model>, Successor> */
    private array $declared = [];

    private function __construct(private readonly string $map) {}

    /**
     * @param  Closure(self): mixed  $targets
     * @return non-empty-array<class-string<Model>, Successor>
     */
    public static function declare(string $map, Closure $targets): array
    {
        ResolvedMap::refuseCaptures(
            map: $map,
            role: 'the relatedMorph() targets: closure',
            closure: $targets,
        );

        $declaring = new self($map);
        $targets($declaring);

        if ($declaring->declared === []) {
            throw InvalidCompatibilityMap::because(
                map: $map,
                problem: 'the targets: of relatedMorph() declare no target; declare each with as()',
            );
        }

        return $declaring->declared;
    }

    /**
     * @param  Closure(Successor): mixed  $successor
     */
    public function as(string $target, Closure $successor): self
    {
        if (array_key_exists(
            key: $target,
            array: $this->declared,
        )) {
            throw InvalidCompatibilityMap::because(
                map: $this->map,
                problem: "as({$target}) is declared twice",
            );
        }

        if (! is_subclass_of(
            object_or_class: $target,
            class: Model::class,
        )) {
            throw InvalidCompatibilityMap::because(
                map: $this->map,
                problem: "as() names {$target}, which is not an Eloquent model",
            );
        }

        ResolvedMap::refuseCaptures(
            map: $this->map,
            role: 'the as('.class_basename($target).') closure',
            closure: $successor,
        );

        $declared = new Successor(
            map: $this->map,
            model: $target,
        );
        $successor($declared);
        $this->declared[$target] = $declared;

        return $this;
    }
}
