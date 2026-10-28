<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;

enum Setting: string
{
    case Applying = 'compatibility.applying';
    case Created = 'compatibility.created';

    public function for(ResolvedMap $resolvedMap): string
    {
        return match ($this) {
            self::Applying => $this->value,
            self::Created => $this->value.'_'.$resolvedMap->name,
        };
    }
}
