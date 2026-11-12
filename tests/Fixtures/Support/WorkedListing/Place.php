<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $job_id
 * @property null|string $address_id
 * @property null|string $name
 */
final class Place extends Model
{
    use HasUuids;

    protected $table = 'places';

    protected $guarded = [];

    /** @return BelongsTo<Address, $this> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(
            related: Address::class,
            foreignKey: 'address_id',
            ownerKey: 'uuid',
        );
    }
}
