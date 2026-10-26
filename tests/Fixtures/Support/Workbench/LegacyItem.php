<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;

final class LegacyItem extends Model
{
    protected $table = 'legacy_items';

    protected $guarded = [];

    public $timestamps = false;
}
