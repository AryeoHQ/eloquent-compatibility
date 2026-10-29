<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Triggers;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Publication;

final class Publish extends Trigger
{
    #[Target]
    public readonly Publication $publication;

    public function allowed(): bool
    {
        return ! $this->publication->locked;
    }

    public function handle(Journal $journal): void
    {
        $journal->handle($this->publication);

        $this->publication->forceFill(['published_at' => now()]);
    }
}
