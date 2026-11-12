<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

/**
 * @internal
 */
enum Outcome: string
{
    case Created = 'created';

    case Updated = 'updated';

    case Unchanged = 'unchanged';

    case Refused = 'refused';

    case Failed = 'failed';

    case Deleted = 'deleted';

    public function blocks(): bool
    {
        return $this === self::Refused || $this === self::Failed;
    }

    public function changesSuccessors(): bool
    {
        return $this === self::Created || $this === self::Updated || $this === self::Deleted;
    }
}
