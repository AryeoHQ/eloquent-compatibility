<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings\Lifecycle;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Support\Listings\LifecycleStage;

final class StagedJob extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'staged_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['stage' => LifecycleStage::class];
    }
}
