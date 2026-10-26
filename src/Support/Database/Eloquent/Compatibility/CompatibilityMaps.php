<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;

final class CompatibilityMaps
{
    /** @var array<string, ResolvedMap> */
    private array $resolvedMaps = [];

    public function register(CompatibilityMap $map): ResolvedMap
    {
        $resolvedMap = ResolvedMap::of($map);
        $existing = $this->resolvedMaps[$resolvedMap->name] ?? null;

        if ($existing !== null && $existing->previousModel->view !== $resolvedMap->previousModel->view) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, 'another compatibility map with this name is already registered');
        }

        return $this->resolvedMaps[$resolvedMap->name] = $resolvedMap;
    }

    /** @return list<ResolvedMap> */
    public function all(): array
    {
        return array_values($this->resolvedMaps);
    }

    public function get(string $name): ResolvedMap
    {
        return $this->resolvedMaps[$name] ?? throw InvalidCompatibilityMap::because($name, 'no compatibility map with this name is registered');
    }
}
