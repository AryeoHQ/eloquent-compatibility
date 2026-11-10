<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Support\Str;
use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Refusal;
use Support\Database\Eloquent\Compatibility\Write\Successors;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Transition;
use Support\Database\Eloquent\StateMachines\StateMachine;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;

/**
 * @implements WritesAttribute<Job>
 */
final class TransitionListingStatus implements WritesAttribute
{
    private const array STATES = [
        'Draft' => LifecycleStatus::Drafting,
        'Active' => LifecycleStatus::Live,
        'Sold' => LifecycleStatus::Closed,
    ];

    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $job = $successors->root();
        $status = $value instanceof ListingStatus ? $value->value : $value;
        $to = self::STATES[is_string($status) ? $status : ''] ?? throw Refusal::because('A listing status is Draft, Active or Sold, and '.var_export($status, true).' is none of them.');

        if (! $job->exists) {
            if ($to !== LifecycleStatus::Drafting) {
                throw Refusal::because("A new listing starts as a Draft, so it cannot start as {$status}.");
            }

            return;
        }

        $from = LifecycleStatus::from((string) $job->getRawOriginal('lifecycle_status'));

        if ($from === $to) {
            return;
        }

        $transitions = $from->transitions()->filter(fn (Transition $transition) => $transition->to === $to)->values()->all();

        if (count($transitions) !== 1) {
            throw Refusal::because("A listing moves from {$from->value} to {$to->value} along exactly one transition, and LifecycleStatus declares ".count($transitions).'.');
        }

        $trigger = Str::camel(class_basename($transitions[0]->using));
        $successors->afterSave(function () use ($job, $trigger): void {
            $state = $job->getAttribute('lifecycle_status');
            $run = $state instanceof StateMachine ? $state->{$trigger}() : null;

            if (! $run instanceof Trigger) {
                throw Refusal::because("Job::\$lifecycle_status gives no {$trigger} trigger.");
            }

            $run->now();
        });
    }
}
