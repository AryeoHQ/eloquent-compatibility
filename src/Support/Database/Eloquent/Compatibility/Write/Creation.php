<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Eloquent\Model;

final readonly class Creation
{
    public function __construct(
        public Model $record,
        public Origin $origin,
    ) {}
}
