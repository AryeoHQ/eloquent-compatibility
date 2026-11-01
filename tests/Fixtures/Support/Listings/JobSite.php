<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

final class JobSite extends Model implements Compatible
{
    use HasCompatibility;
    use HasUuids;

    protected $table = 'job_sites';

    protected $guarded = [];
}
