<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final readonly class Branch
{
    /**
     * @param  class-string<Model>  $model
     */
    private function __construct(public string $model, public Successor $successor) {}

    /**
     * @param  Closure(Successor): mixed  $definition
     */
    public static function declare(string $map, string $model, Closure $definition): self
    {
        if (! is_subclass_of($model, Model::class)) {
            throw InvalidCompatibilityMap::because($map, "as() names {$model}, which is not an Eloquent model");
        }

        ResolvedMap::refuseCaptures($map, 'the as('.class_basename($model).') closure', $definition);

        $successor = new Successor($map, $model);
        $definition($successor);

        return new self($model, $successor);
    }
}
