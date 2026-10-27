<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\PublicationState;

use Support\Database\Eloquent\StateMachines\Attributes\Events\Events;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Transition;
use Support\Database\Eloquent\StateMachines\Contracts\StateMachineable;
use Support\Database\Eloquent\StateMachines\Provides\ManagesState;
use Tests\Fixtures\Support\Workbench\PublicationState\Events\Transitioned;
use Tests\Fixtures\Support\Workbench\PublicationState\Events\Transitioning;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Archive;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Feature;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Publish;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Reopen;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Restore;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\ReturnToPublished;

/**
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Publish publish()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Archive archive(string $reason)
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Feature feature()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\ReturnToPublished returnToPublished()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Restore restore()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Reopen reopen()
 */
enum PublicationState: string implements StateMachineable
{
    use ManagesState;

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    #[Transition(to: self::Published, using: Publish::class)]
    #[Transition(to: self::Archived, using: Archive::class)]
    case Draft = 'draft';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    #[Transition(to: self::Featured, using: Feature::class)]
    case Published = 'published';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    #[Transition(to: self::Published, using: ReturnToPublished::class)]
    case Featured = 'featured';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    #[Transition(to: self::Draft, using: Restore::class)]
    #[Transition(to: self::Draft, using: Reopen::class)]
    case Archived = 'archived';
}
