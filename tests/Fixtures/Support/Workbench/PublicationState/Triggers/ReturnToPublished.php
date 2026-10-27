<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Triggers;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Publication;

final class ReturnToPublished extends Trigger
{
    #[Target]
    public readonly Publication $publication;

    public function handle(): void
    {
        $this->publication->forceFill(['featured_at' => null]);
    }
}
