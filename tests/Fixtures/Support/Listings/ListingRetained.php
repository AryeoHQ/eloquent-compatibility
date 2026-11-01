<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The old listings table after its rename. It keeps what moved nowhere: its key, uuid, MLS number and status.
 *
 * @property string $status
 */
final class ListingRetained extends Model
{
    protected $table = 'listings_retained';

    protected $guarded = [];

    public $timestamps = false;

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}
