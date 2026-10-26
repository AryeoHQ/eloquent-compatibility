<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

final class ModelClass
{
    /**
     * @param  class-string<Model>  $model
     * @return class-string<Model>
     */
    public static function canonical(string $model): string
    {
        return class_exists($model) ? (new ReflectionClass($model))->getName() : $model;
    }
}
