<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

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
        $connection = $this->getConnection();

        if ($connection->transactionLevel() > 0 || ! RetainedRows::served($this)) {
            return parent::performInsert($query);
        }

        return $connection->transaction(fn (): bool => parent::performInsert($query));
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
