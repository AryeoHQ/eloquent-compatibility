<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

final readonly class UnguardedColumnTrigger
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $map,
        public string $table,
        public string $trigger,
        public array $columns,
    ) {}
}
