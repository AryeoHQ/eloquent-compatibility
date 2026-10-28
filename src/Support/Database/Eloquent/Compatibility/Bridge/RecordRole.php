<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

enum RecordRole
{
    case Primary;
    case Previous;
    case Dependent;

    public function lockOrder(): int
    {
        return match ($this) {
            self::Primary => 0,
            self::Previous => 1,
            self::Dependent => 2,
        };
    }
}
