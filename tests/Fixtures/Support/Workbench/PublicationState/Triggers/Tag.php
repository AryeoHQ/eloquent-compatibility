<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Triggers;

use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Publication;

final class Tag extends Trigger
{
    #[Target]
    public readonly Publication $publication;

    /** @var list<string> */
    public readonly array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = array_values($tags);
    }

    public function handle(): void
    {
        $this->publication->forceFill(['title' => "{$this->publication->getAttribute('title')} (".implode(', ', $this->tags).')']);
    }
}
