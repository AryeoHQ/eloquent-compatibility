<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Definition\Guard;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Lifecycle\Close;
use Tests\Fixtures\Support\Listings\Lifecycle\Relist;
use Tests\Fixtures\Support\Listings\Lifecycle\Reopen;
use Tests\Fixtures\Support\Listings\LifecycleStatus;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(CompatibilityWriteRefused::class)]
final class CompatibilityWriteRefusedTest extends TestCase
{
    #[Test]
    public function it_names_what_it_refused_why_and_what_to_do_instead(): void
    {
        $refusals = [
            CompatibilityWriteRefused::readOnly(Listing::class, 'company_id', 7, 'A job never changes company.'),
            CompatibilityWriteRefused::transitions(Listing::class, 'status'),
            CompatibilityWriteRefused::notAStateMachine(Listing::class, 'title', Job::class, 'name'),
            CompatibilityWriteRefused::noTransition(Listing::class, 'status', 1, LifecycleStatus::class, 'live', 'drafting'),
            CompatibilityWriteRefused::severalTransitions(Listing::class, 'status', 3, LifecycleStatus::class, 'closed', 'live', [Reopen::class, Relist::class], Job::class),
            CompatibilityWriteRefused::byTransition(Listing::class, 'status', 1, Close::class, new RuntimeException('Invalid transition from [Live] to [Closed] on [Job].')),
            CompatibilityWriteRefused::notInitial(Listing::class, 'status', Job::class, 'lifecycle_status', 'live', 'drafting'),
            CompatibilityWriteRefused::notInitial(Listing::class, 'status', Job::class, 'lifecycle_status', 'live', null),
            CompatibilityWriteRefused::external(Listing::class, 'mime_type'),
            CompatibilityWriteRefused::unstorable(Listing::class, 'status', "mapping() maps no stored value to 'Pending'."),
            CompatibilityWriteRefused::byGuard(Listing::class, new Guard('sold price frozen', fn () => true, 'A sold listing keeps the price it sold for.', 'Change the price before the sale.'), 7),
            CompatibilityWriteRefused::bySuccessorListener(Listing::class, new Place),
            CompatibilityWriteRefused::noParentRow(Listing::class, ['street', 'city'], 'places', 'job_sites'),
            CompatibilityWriteRefused::noRelatedRow(Listing::class, ['street'], 'places', 'jobs'),
            CompatibilityWriteRefused::rawExpression(Listing::class, 'title', 'It is stored in jobs, and SQL written for the legacy table would read the columns of jobs instead.'),
            CompatibilityWriteRefused::noKeyDefault(Listing::class, ['street', 'city'], 'places', 'id'),
        ];

        $this->assertSame([
            'Tests\Fixtures\Support\Listings\Listing record 7 cannot change $company_id. Why: A job never changes company. Instead: Leave company_id unchanged in writes through Listing.',
            'Tests\Fixtures\Support\Listings\Listing::$status cannot take a mass write. Why: It is declared transitions(), so each record\'s change runs its successor\'s state-machine trigger, and a set-based statement cannot run a trigger per row. Instead: Write the records one at a time through Listing.',
            'Tests\Fixtures\Support\Listings\Listing::$title cannot take a write. Why: It is declared transitions(), and Job::$name has no state-machine cast. Instead: Cast name on Job to a StateMachineable enum, or drop transitions() from the attribute.',
            'Tests\Fixtures\Support\Listings\Listing record 1 cannot change $status from \'live\' to \'drafting\'. Why: LifecycleStatus declares no transition from \'live\' to \'drafting\', so no trigger can make the change. Instead: Change status only along a transition LifecycleStatus declares.',
            'Tests\Fixtures\Support\Listings\Listing record 3 cannot change $status from \'closed\' to \'live\'. Why: LifecycleStatus declares 2 transitions from \'closed\' to \'live\', through Reopen and Relist, so the write cannot choose one. Instead: Run the trigger you mean on Job directly.',
            'Tests\Fixtures\Support\Listings\Listing record 1 cannot change $status, because the trigger Close refused it. Why: Invalid transition from [Live] to [Closed] on [Job]. Instead: Make the change once Close allows it, or change status along another transition.',
            'Tests\Fixtures\Support\Listings\Listing cannot insert a record whose $status stores \'live\'. Why: A new Job starts in its initial state \'drafting\' on lifecycle_status, and an insert runs no transition. Instead: Insert the record with the status that stores \'drafting\', then change it in a second write.',
            'Tests\Fixtures\Support\Listings\Listing cannot insert a record whose $status stores \'live\'. Why: Job declares no initial state for lifecycle_status, so an insert cannot store a state without a transition. Instead: Insert the record without status, then set it through the state machine of Job.',
            'Tests\Fixtures\Support\Listings\Listing::$mime_type cannot take a write. Why: It is declared external(), so its value lives outside the database, and this release writes no external value yet. Instead: Write the value through the successor model.',
            'Tests\Fixtures\Support\Listings\Listing::$status cannot take a write. Why: mapping() maps no stored value to \'Pending\'. Instead: Write a value its conversions can store, or write the stored column through the successor model.',
            'Tests\Fixtures\Support\Listings\Listing record 7 cannot take this write, because guard \'sold price frozen\' refuses it. Why: A sold listing keeps the price it sold for. Instead: Change the price before the sale.',
            'Tests\Fixtures\Support\Listings\Listing cannot take this write, because a listener of Place refused its save. Why: The write stores its values through Place, and that model\'s own listeners refused them. Instead: Make the write satisfy the rules of Place.',
            'Tests\Fixtures\Support\Listings\Listing cannot write $street, $city. Why: They live on a places row, and the record has no job_sites row to hold one. Instead: Write a value that creates the job_sites row in the same write, or create that row through its own model first.',
            'Tests\Fixtures\Support\Listings\Listing cannot write $street. Why: They live on a places row the record does not have, and jobs reaches that row through a belongs-to relation, which creates no row. Instead: Create the places row through its own model and associate it with the jobs row first.',
            'Tests\Fixtures\Support\Listings\Listing::$title cannot take a raw expression. Why: It is stored in jobs, and SQL written for the legacy table would read the columns of jobs instead. Instead: Write the computed value, or write the stored column through the successor model.',
            'Tests\Fixtures\Support\Listings\Listing cannot write $street, $city in one statement to records that have no places row. Why: A set-based write creates the missing places rows in SQL, and places.id has no database default to key them. Instead: Write the records one at a time through Listing, or give places.id a database default.',
        ], array_map(fn (CompatibilityWriteRefused $refusal) => $refusal->getMessage(), $refusals));
        $this->assertContainsOnlyInstancesOf(CompatibilityException::class, $refusals);
    }

    #[Test]
    public function it_keeps_the_trigger_refusal_it_reports(): void
    {
        $refusal = new RuntimeException('Invalid transition from [Live] to [Closed] on [Job].');

        $this->assertSame($refusal, CompatibilityWriteRefused::byTransition(Listing::class, 'status', 1, Close::class, $refusal)->getPrevious());
    }

    #[Test]
    public function it_keeps_each_part_of_the_refusal_apart(): void
    {
        $refusal = CompatibilityWriteRefused::readOnly(Listing::class, 'company_id', 7, 'A job never changes company.');

        $this->assertSame(
            ['Tests\Fixtures\Support\Listings\Listing record 7 cannot change $company_id', 'A job never changes company.', 'Leave company_id unchanged in writes through Listing.'],
            [$refusal->refused, $refusal->because, $refusal->instead],
        );
    }
}
