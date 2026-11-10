<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Write\RetainedRows;

/**
 * @phpstan-require-extends Model
 */
trait CarriesLegacy
{
    /**
     * @param  Builder<static>  $query
     * @return bool
     */
    protected function performInsert(Builder $query)
    {
        return $this->insertCarryingLegacy(fn (): bool => parent::performInsert($query));
    }

    /**
     * @param  Builder<static>  $query
     * @param  array<mixed>|string|null  $uniqueBy
     * @return bool
     */
    protected function performInsertOrIgnore(Builder $query, array|string|null $uniqueBy)
    {
        return $this->insertCarryingLegacy(fn (): bool => parent::performInsertOrIgnore($query, $uniqueBy));
    }

    /**
     * @param  Closure(): bool  $insert
     */
    private function insertCarryingLegacy(Closure $insert): bool
    {
        $connection = $this->getConnection();

        if ($connection->transactionLevel() > 0 || ! RetainedRows::carries($this)) {
            return $insert();
        }

        return $connection->transaction($insert);
    }

    /**
     * @param  string  $event
     * @param  bool  $halt
     * @return mixed
     */
    protected function fireModelEvent($event, $halt = true)
    {
        if ($event === 'created') {
            RetainedRows::carry($this);
        }

        return parent::fireModelEvent($event, $halt);
    }
}
