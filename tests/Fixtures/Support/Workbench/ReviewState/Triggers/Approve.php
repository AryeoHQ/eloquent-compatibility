<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\ReviewState\Triggers;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Placement;
use Tests\Fixtures\Support\Workbench\Publication;

final class Approve extends Trigger
{
    #[Target]
    public readonly Publication $publication;

    public function handle(): void
    {
        Placement::query()->where('publication_id', $this->publication->getKey())->firstOrFail()->update(['slot' => 'front']);
    }
}
