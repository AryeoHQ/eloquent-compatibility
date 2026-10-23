<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Declaration;

final class CompatibilityMaps
{
    /** @var array<string, Declaration> */
    private array $declarations = [];

    public function register(CompatibilityMap $map): Declaration
    {
        $declaration = Declaration::of($map);
        $existing = $this->declarations[$declaration->name] ?? null;

        if ($existing !== null && $existing->previousModel->view !== $declaration->previousModel->view) {
            throw InvalidCompatibilityMap::because($declaration->name, 'another compatibility map with this name is already registered');
        }

        return $this->declarations[$declaration->name] = $declaration;
    }

    /** @return list<Declaration> */
    public function all(): array
    {
        return array_values($this->declarations);
    }

    public function get(string $name): Declaration
    {
        return $this->declarations[$name] ?? throw InvalidCompatibilityMap::because($name, 'no compatibility map with this name is registered');
    }
}
