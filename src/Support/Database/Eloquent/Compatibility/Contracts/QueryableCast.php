<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Contracts;

/**
 * A cast whose stored value the database can still filter and sort on. A routing builder converts each filter value
 * through the cast's set() before the query reaches SQL, so `where('price', '>', 400000)` on dollars compares cents.
 */
interface QueryableCast
{
    /**
     * Whether a < b holds exactly when the stored a < the stored b. Without it, the builder refuses range filters,
     * orderBy(), min() and max() on the attribute.
     */
    public function preservesOrder(): bool;

    /**
     * Whether the stored value is a constant multiple of the value, so sum(), avg() and increment() translate.
     */
    public function isProportional(): bool;
}
