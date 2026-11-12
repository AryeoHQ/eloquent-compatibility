<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 */
final class Region extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'regions';

    protected $guarded = [];
}
