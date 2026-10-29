<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Triggers;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Placement;
use Tests\Fixtures\Support\Workbench\Publication;

final class Schedule extends Trigger
{
    #[Target]
    public readonly Publication $publication;

    public function __construct(public readonly Placement $placement) {}

    public function handle(Journal $journal): void
    {
        $journal->handle($this->publication);

        $this->publication->forceFill(['title' => "{$this->publication->getAttribute('title')} ({$this->placement->getAttribute('slot')})"]);
    }
}
