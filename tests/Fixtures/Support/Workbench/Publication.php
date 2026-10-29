<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;
use Tests\Fixtures\Support\Workbench\PublicationState\PublicationState;
use Tests\Fixtures\Support\Workbench\ReviewState\ReviewState;

/**
 * @property bool $locked
 * @property (\Tests\Fixtures\Support\Workbench\PublicationState\PublicationState & \Support\Database\Eloquent\StateMachines\StateMachine) $state
 * @property (\Tests\Fixtures\Support\Workbench\ReviewState\ReviewState & \Support\Database\Eloquent\StateMachines\StateMachine) $review
 *
 * @phpstan-property \Support\Database\Eloquent\StateMachines\StateMachine<\Tests\Fixtures\Support\Workbench\PublicationState\PublicationState> $state
 * @phpstan-property \Support\Database\Eloquent\StateMachines\StateMachine<\Tests\Fixtures\Support\Workbench\ReviewState\ReviewState> $review
 */
class Publication extends Model implements Compatible
{
    use HasCompatibility;
    use HasUuids;

    protected $table = 'publications';

    protected $guarded = [];

    protected $dispatchesEvents = ['saved' => PublicationSaved::class];

    protected function casts(): array
    {
        return [
            'state' => PublicationState::class,
            'review' => ReviewState::class,
            'locked' => 'boolean',
            'published_at' => 'datetime',
            'featured_at' => 'datetime',
        ];
    }
}
