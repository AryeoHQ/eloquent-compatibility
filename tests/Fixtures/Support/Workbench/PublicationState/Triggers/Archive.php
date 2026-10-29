<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Triggers;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Publication;

final class Archive extends Trigger
{
    #[Target]
    public readonly Publication $publication;

    public function __construct(public readonly string $reason) {}

    public function handle(): void
    {
        $this->publication->forceFill(['title' => "{$this->publication->getAttribute('title')} ({$this->reason})"]);
    }
}
