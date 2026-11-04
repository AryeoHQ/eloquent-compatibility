<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Database\Eloquent\Model;

final readonly class DriftFinding
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public DriftCheck $check,
        public string $record,
        public string $detail,
    ) {}
}
