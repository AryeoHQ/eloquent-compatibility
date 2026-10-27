<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;

final class CastGadget extends Model
{
    protected $table = 'gadgets';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'code' => 'integer',
    ];
}
