<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\StateMachines\Attributes\Events\Events;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Transition;
use Support\Database\Eloquent\StateMachines\Contracts\StateMachineable;
use Support\Database\Eloquent\StateMachines\Provides\ManagesState;
use Tests\Fixtures\Support\Listings\Lifecycle\Close;
use Tests\Fixtures\Support\Listings\Lifecycle\EnteredClosed;
use Tests\Fixtures\Support\Listings\Lifecycle\EnteredLive;
use Tests\Fixtures\Support\Listings\Lifecycle\EnteringClosed;
use Tests\Fixtures\Support\Listings\Lifecycle\EnteringLive;
use Tests\Fixtures\Support\Listings\Lifecycle\Publish;
use Tests\Fixtures\Support\Listings\Lifecycle\Relist;
use Tests\Fixtures\Support\Listings\Lifecycle\Reopen;

/**
 * @method Publish publish()
 * @method Close close()
 * @method Reopen reopen()
 * @method Relist relist()
 */
enum LifecycleStatus: string implements StateMachineable
{
    use ManagesState;

    #[Transition(to: self::Live, using: Publish::class)]
    case Drafting = 'drafting';

    #[Events(before: EnteringLive::class, after: EnteredLive::class)]
    #[Transition(to: self::Closed, using: Close::class)]
    case Live = 'live';

    #[Events(before: EnteringClosed::class, after: EnteredClosed::class)]
    #[Transition(to: self::Live, using: Reopen::class)]
    #[Transition(to: self::Live, using: Relist::class)]
    case Closed = 'closed';
}
