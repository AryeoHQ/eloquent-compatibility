<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class Membership
{
    /**
     * @param  class-string<Model>  $successor
     */
    public function __construct(
        private string $map,
        public string $successor,
        public string $table,
        public string $column,
        public string $alias,
        private null|Closure $when,
    ) {}

    public function of(string $table): string
    {
        return SqlFragments::identifier($table).'.'.SqlFragments::identifier($this->column).' = '.SqlFragments::literal($this->alias);
    }

    public function check(): null|string
    {
        $condition = $this->condition();

        return $condition === null ? null : SqlFragments::identifier($this->column).' IS DISTINCT FROM '.SqlFragments::literal($this->alias).' OR COALESCE(('.$condition.'), false)';
    }

    public function constraint(): string
    {
        return SqlFragments::objectName($this->map, $this->table.'_'.$this->map.'_membership');
    }

    private function condition(): null|string
    {
        if ($this->when === null) {
            return null;
        }

        $query = (new $this->successor)->newModelQuery();
        ($this->when)($query);
        $base = $query->getQuery();
        $where = $base->getGrammar()->compileWheres($base);

        if ($where === '' || ($base->joins ?? []) !== [] || ($base->orders ?? []) !== [] || $base->limit !== null || ($base->groups ?? []) !== []) {
            throw InvalidCompatibilityMap::because($this->map, 'when() on '.class_basename($this->successor)." compiles to no plain condition on {$this->table}; it takes where clauses only, because it becomes a CHECK constraint on {$this->table}");
        }

        return SqlFragments::bind(Str::after($where, 'where '), SqlFragments::asSent(array_values($base->getRawBindings()['where']), $base->getGrammar()->getDateFormat()));
    }
}
