<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Eloquent\Model;

final readonly class Change
{
    public function __construct(
        public Model $record,
        public Model $original,
        public string $attribute,
        public Origin $origin,
    ) {}
}
