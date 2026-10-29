<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench\ReviewState;

use Support\Database\Eloquent\StateMachines\Attributes\Events\Events;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Transition;
use Support\Database\Eloquent\StateMachines\Contracts\StateMachineable;
use Support\Database\Eloquent\StateMachines\Provides\ManagesState;
use Tests\Fixtures\Support\Workbench\ReviewState\Events\Reviewed;
use Tests\Fixtures\Support\Workbench\ReviewState\Events\Reviewing;
use Tests\Fixtures\Support\Workbench\ReviewState\Triggers\Approve;

/**
 * @method \Tests\Fixtures\Support\Workbench\ReviewState\Triggers\Approve approve()
 */
enum ReviewState: string implements StateMachineable
{
    use ManagesState;

    #[Events(before: Reviewing::class, after: Reviewed::class)]
    #[Transition(to: self::Approved, using: Approve::class)]
    case Pending = 'pending';

    #[Events(before: Reviewing::class, after: Reviewed::class)]
    case Approved = 'approved';
}
