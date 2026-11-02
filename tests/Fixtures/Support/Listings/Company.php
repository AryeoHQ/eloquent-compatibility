<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 */
final class Company extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'companies';

    protected $guarded = [];

    /** @return HasOne<CompanyProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(CompanyProfile::class);
    }
}
