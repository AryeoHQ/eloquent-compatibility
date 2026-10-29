<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState\Triggers;

use Illuminate\Container\Attributes\Config;
use Support\Database\Eloquent\StateMachines\Triggers\Target\Target;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Publication;

final class Withdraw extends Trigger
{
    #[Target]
    public readonly Publication $publication;

    public function __construct(public readonly string $reason, #[Config('bulletins.desk')] public readonly string $desk) {}

    public function handle(): void
    {
        $this->publication->forceFill(['title' => "{$this->publication->getAttribute('title')} ({$this->reason} by {$this->desk})"]);
    }
}
