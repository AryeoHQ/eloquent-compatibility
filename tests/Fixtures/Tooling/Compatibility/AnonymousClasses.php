<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

$compatibleWithoutHasCompatibility = new class extends Model implements Compatible {};

$hasCompatibilityWithoutCompatible = new class extends Model
{
    use HasCompatibility;
};

$compatibleOnNonModel = new class implements Compatible {};

$redeclaresWriteHook = new class extends ValidModel
{
    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        return [];
    }
};
