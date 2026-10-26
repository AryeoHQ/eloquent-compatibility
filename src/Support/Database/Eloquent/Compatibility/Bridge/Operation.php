<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

enum Operation: string
{
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';

    public function accepts(self $operation): bool
    {
        return $operation === $this || ($this === self::Delete && $operation === self::Update);
    }
}
