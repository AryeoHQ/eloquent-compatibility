<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Triggers;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Publication;
use Tests\Fixtures\Support\Workbench\PublicationState\Middleware\Decline;

final class Embargo extends Trigger
{
    #[Target]
    public readonly Publication $publication;

    public function __construct()
    {
        $this->middleware = [new Decline];
    }

    public function handle(Journal $journal): void
    {
        $journal->handle($this->publication);
    }
}
