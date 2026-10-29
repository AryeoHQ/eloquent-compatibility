<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

enum PlannerIndexFilter: string
{
    case WhereNull = 'whereNull';
    case WhereNotNull = 'whereNotNull';

    public function predicate(): string
    {
        return match ($this) {
            self::WhereNull => 'IS NULL',
            self::WhereNotNull => 'IS NOT NULL',
        };
    }
}
