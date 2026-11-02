<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings\Lifecycle;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Listings\Job;

final class Relist extends Trigger
{
    #[Target]
    public readonly Job $record;

    public function handle(): void {}
}
