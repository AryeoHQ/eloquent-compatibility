<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property null|string $job_id
 * @property null|string $place_id
 * @property null|string $job_site_id
 * @property null|int $address_id
 * @property string $title
 * @property string $company_id
 * @property bool $is_draft
 * @property bool $downloads_enabled
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property null|Carbon $deleted_at
 * @property-read Collection<int, ListingOrder> $orders
 */
abstract class Listing extends Model
{
    use SoftDeletes;

    protected $table = 'listings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'address_id' => 'integer',
            'is_draft' => 'boolean',
            'downloads_enabled' => 'boolean',
        ];
    }

    public static function makeUuid(): string
    {
        return (string) Str::uuid();
    }

    /** @return HasMany<ListingOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(
            related: ListingOrder::class,
            foreignKey: 'listing_id',
        );
    }
}
