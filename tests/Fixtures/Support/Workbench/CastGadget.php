<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

final class CastGadget extends Model implements Compatible
{
    use HasCompatibility;

    protected $table = 'gadgets';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'code' => 'integer',
    ];
}
