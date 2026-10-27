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
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Credit;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Embargo;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Feature;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Grade;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Pin;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Publish;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Release;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Reopen;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Reserve;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Restore;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\ReturnToPublished;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Schedule;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Tag;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Withdraw;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Withdrawal;

/**
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Publish publish()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Archive archive(string $reason)
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Feature feature()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Withdraw withdraw(string $reason)
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\ReturnToPublished returnToPublished()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Restore restore()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Reopen reopen()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Schedule schedule(\Tests\Fixtures\Support\Workbench\Placement $placement)
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Pin pin(null|\Tests\Fixtures\Support\Workbench\Placement $placement)
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Embargo embargo()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Reserve reserve()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Release release()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Withdrawal\Release release()
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Tag tag(string ...$tags)
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Credit credit(string $author, string $Author)
 * @method \Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Grade grade(null|\Tests\Fixtures\Support\Workbench\ReviewState\ReviewState $review)
 */
enum PublicationState: string implements StateMachineable
{
    use ManagesState;

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    #[Transition(to: self::Published, using: Publish::class)]
    #[Transition(to: self::Archived, using: Archive::class)]
    #[Transition(to: self::Scheduled, using: Schedule::class)]
    #[Transition(to: self::Pinned, using: Pin::class)]
    #[Transition(to: self::Embargoed, using: Embargo::class)]
    #[Transition(to: self::Reserved, using: Reserve::class)]
    #[Transition(to: self::Tagged, using: Tag::class)]
    #[Transition(to: self::Credited, using: Credit::class)]
    #[Transition(to: self::Graded, using: Grade::class)]
    case Draft = 'draft';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    #[Transition(to: self::Featured, using: Feature::class)]
    #[Transition(to: self::Archived, using: Withdraw::class)]
    case Published = 'published';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    #[Transition(to: self::Published, using: ReturnToPublished::class)]
    case Featured = 'featured';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    #[Transition(to: self::Draft, using: Restore::class)]
    #[Transition(to: self::Draft, using: Reopen::class)]
    case Archived = 'archived';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    #[Transition(to: self::Published, using: Release::class)]
    #[Transition(to: self::Archived, using: Withdrawal\Release::class)]
    case Scheduled = 'scheduled';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    case Pinned = 'pinned';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    case Embargoed = 'embargoed';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    case Reserved = 'reserved';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    case Tagged = 'tagged';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    case Credited = 'credited';

    #[Events(before: Transitioning::class, after: Transitioned::class)]
    case Graded = 'graded';
}
