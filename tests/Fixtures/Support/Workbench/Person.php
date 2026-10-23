<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Person extends Model
{
    use HasUuids;

    protected $table = 'people';

    protected $guarded = [];
}
