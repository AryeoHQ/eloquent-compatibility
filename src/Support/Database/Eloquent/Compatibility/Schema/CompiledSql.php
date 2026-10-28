<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

final readonly class CompiledSql
{
    /**
     * @param  list<string>  $view
     * @param  list<string>  $functions
     * @param  list<string>  $triggers
     */
    public function __construct(
        public array $view,
        public array $functions,
        public array $triggers,
    ) {}

    /** @return list<string> */
    public function statements(): array
    {
        return [...$this->view, ...$this->functions, ...$this->triggers];
    }
}
