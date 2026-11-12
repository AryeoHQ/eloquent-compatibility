<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property string $street
 * @property null|float $latitude
 * @property null|float $longitude
 */
final class Address extends Model
{
    use SoftDeletes;

    protected $table = 'addresses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function label(): string
    {
        return $this->street;
    }

    public function hasValidCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
