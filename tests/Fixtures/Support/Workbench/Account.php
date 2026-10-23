<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;

final class Account extends Model
{
    protected $table = 'accounts';

    protected $guarded = [];
}
