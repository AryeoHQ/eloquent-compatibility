<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Operations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Order extends Model
{
    protected $table = 'orders';

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasOne<Invoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /** @return MorphTo<Model, $this> */
    public function receipt(): MorphTo
    {
        return $this->morphTo();
    }
}
