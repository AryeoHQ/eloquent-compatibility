<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Triggers;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Publication;
use Throwable;

final class ReturnToPublished extends Trigger
{
    #[Target]
    public readonly Publication $publication;

    public function handle(): void
    {
        $this->publication->forceFill(['featured_at' => null]);
    }

    public function failed(Throwable $exception): void
    {
        $row = fn () => $this->publication->getConnection()->table('publications')->where('id', $this->publication->getKey());

        resolve(Journal::class)->entries[] = "failed in {$row()->value('state')}: {$exception->getMessage()}";

        $row()->update(['title' => 'Written by failed()']);
    }
}
