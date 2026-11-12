<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UnstampedJob extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'jobs';

    protected $guarded = [];
}
