<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings\Lifecycle;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;

final class Launch extends Trigger
{
    #[Target]
    public readonly StagedJob $record;

    public function handle(): void {}
}
