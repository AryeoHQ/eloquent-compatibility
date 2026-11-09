<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityException;

final class Refusal extends CompatibilityException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
