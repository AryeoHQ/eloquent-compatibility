<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

/**
 * @property int $id
 * @property string $title
 * @property null|string $gadget_id
 *
 * @method null|bool restore()
 */
final class Widget extends Model implements Compatible
{
    use HasCompatibility;
    use SoftDeletes;

    protected $table = 'widgets';

    protected $guarded = [];
}
