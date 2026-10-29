<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Triggers;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Publication;

final class Reserve extends Trigger implements ShouldBeUnique
{
    #[Target]
    public readonly Publication $publication;

    public function uniqueId(): string
    {
        return 'publication';
    }

    public function handle(Journal $journal): void
    {
        $journal->handle($this->publication);
    }
}
