<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\StateMachines\Attributes\Events\Events;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Transition;
use Support\Database\Eloquent\StateMachines\Contracts\StateMachineable;
use Support\Database\Eloquent\StateMachines\Provides\ManagesState;
use Tests\Fixtures\Support\Listings\Lifecycle\Launch;
use Tests\Fixtures\Support\Listings\Lifecycle\Launched;
use Tests\Fixtures\Support\Listings\Lifecycle\Launching;

/**
 * @method Launch launch()
 */
enum LifecycleStage: int implements StateMachineable
{
    use ManagesState;

    #[Transition(to: self::Live, using: Launch::class)]
    case Drafting = 1;

    #[Events(before: Launching::class, after: Launched::class)]
    case Live = 2;
}
