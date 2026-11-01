<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use LogicException;
use Support\Database\Eloquent\Compatibility\Write\CompatibleBuilder;

/**
 * @extends CompatibleBuilder<Listing>
 */
final class ListingBuilder extends CompatibleBuilder
{
    protected function filterTranslations(): array
    {
        return [
            'is_published' => fn (self $query, string $operator, mixed $value) => match ($operator) {
                '=' => $value ? $query->whereRelation('job', 'published_at', '!=', null) : $query->whereRelation('job', 'published_at', '=', null),
                default => throw new LogicException("is_published cannot be filtered with {$operator}."),
            },
        ];
    }

    public function pricedBetween(string $min, string $max): self
    {
        return $this->whereBetween('price', [$min, $max]);
    }

    public function published(): self
    {
        return $this->where('is_published', true);
    }

    public function inCity(string $city): self
    {
        return $this->where('city', $city);
    }
}
