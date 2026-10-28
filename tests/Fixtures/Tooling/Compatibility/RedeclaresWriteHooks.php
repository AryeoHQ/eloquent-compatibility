<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

final class RedeclaresWriteHooks extends Model implements Compatible
{
    use HasCompatibility;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        return [];
    }
}
