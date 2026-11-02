<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings\Lifecycle;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Support\Listings\LifecycleStatus;

final class LaunchedJob extends Model
{
    use HasUuids;

    protected $table = 'jobs';

    protected $guarded = [];

    protected $attributes = ['lifecycle_status' => 'live'];

    protected function casts(): array
    {
        return ['lifecycle_status' => LifecycleStatus::class];
    }
}
