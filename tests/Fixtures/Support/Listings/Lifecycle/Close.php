<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings\Lifecycle;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Listings\Job;

final class Close extends Trigger
{
    #[Target]
    public readonly Job $record;

    public function allowed(): bool
    {
        return $this->record->price_cents !== null;
    }

    public function handle(): void {}
}
