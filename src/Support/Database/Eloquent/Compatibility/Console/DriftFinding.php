<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Database\Eloquent\Model;

final readonly class DriftFinding
{
    public const string NO_PRIMARY = 'no primary';

    public const string VALUES_DIFFER = 'values differ';

    public const string STALE_VIEW = 'stale view';

    public const string RETAINED_WITHOUT_PRIMARY = 'retained without primary';

    public const string HELD_TWICE = 'held twice';

    public const string NO_MORPH_TARGET = 'no morph target';

    public const string UNCLASSIFIED = 'unclassified rows';

    public const string GUARD_REFUSES = 'guard refuses';

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public string $check,
        public string $record,
        public string $detail,
    ) {}
}
