<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 */
final class GadgetPlace extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'gadget_places';

    protected $guarded = [];
}
