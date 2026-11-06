<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $url
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class Link extends Model
{
    use HasUuids;

    protected $table = 'links';

    protected $guarded = [];
}
