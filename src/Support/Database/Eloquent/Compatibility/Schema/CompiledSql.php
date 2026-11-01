<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

final readonly class CompiledSql
{
    /**
     * @param  list<string>  $view
     */
    public function __construct(
        public array $view,
    ) {}

    /** @return list<string> */
    public function statements(): array
    {
        return $this->view;
    }
}
