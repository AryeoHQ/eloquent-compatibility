<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $name
 */
final class Gadget extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'gadgets';

    protected $guarded = [];
}
