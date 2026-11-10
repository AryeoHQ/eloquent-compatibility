<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 */
final class NumberedJob extends Model
{
    public $timestamps = false;

    protected $table = 'numbered_jobs';

    protected $guarded = [];

    protected $keyType = 'string';
}
