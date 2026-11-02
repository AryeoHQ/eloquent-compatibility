<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property null|string $tagline
 */
final class CompanyProfile extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'company_profiles';

    protected $guarded = [];
}
