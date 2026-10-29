<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

/**
 * @property int $id
 * @property null|string $publication_id
 */
final class Bulletin extends Model implements Compatible
{
    use HasCompatibility;

    public $timestamps = false;

    protected $table = 'bulletins';

    protected $guarded = [];
}
