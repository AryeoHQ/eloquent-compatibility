<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final class Successors
{
    /** @var array<class-string<Model>, Branch> */
    private array $declared = [];

    private function __construct(private readonly string $map) {}

    /**
     * @param  Closure(self): mixed  $successors
     * @return non-empty-array<class-string<Model>, Branch>
     */
    public static function declare(string $map, string $method, null|Closure $choose, Closure $successors, string $instead): array
    {
        if ($choose !== null) {
            ResolvedMap::refuseCaptures($map, "the {$method} choose: closure", $choose);
        }

        ResolvedMap::refuseCaptures($map, "the {$method} successors: closure", $successors);

        $declaring = new self($map);
        $successors($declaring);
        $declared = $declaring->declared;
        $names = implode(' and ', array_map(class_basename(...), array_keys($declared)));

        if ($declared === []) {
            throw InvalidCompatibilityMap::because($map, "the successors: of {$method} declare no successor; declare each with as()");
        }

        if (count($declared) === 1 && $choose !== null) {
            throw InvalidCompatibilityMap::because($map, "choose: on {$method} picks one of the successors: classes, and successors: declares only {$names}; ".sprintf($instead, $names));
        }

        if (count($declared) > 1 && $choose === null) {
            throw InvalidCompatibilityMap::because($map, "successors: on {$method} declares {$names} without choose:; give choose: a closure that returns one of them");
        }

        return $declared;
    }

    /**
     * @param  Closure(Successor): mixed  $definition
     */
    public function as(string $successor, Closure $definition): self
    {
        if (array_key_exists($successor, $this->declared)) {
            throw InvalidCompatibilityMap::because($this->map, "as({$successor}) is declared twice");
        }

        $branch = Branch::declare($this->map, $successor, $definition);
        $this->declared[$branch->model] = $branch;

        return $this;
    }
}
