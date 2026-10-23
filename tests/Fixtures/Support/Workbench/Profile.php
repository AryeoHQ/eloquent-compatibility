<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;

final class Profile extends Model
{
    protected $table = 'profiles';

    protected $guarded = [];

    public $timestamps = false;
}
