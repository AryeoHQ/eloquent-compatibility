<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

/**
 * @property string $name
 *
 * @method null|bool restore()
 */
final class Gadget extends Model implements Compatible
{
    use HasCompatibility;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'gadgets';

    protected $guarded = [];
}
