<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class LegacyFileUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public static bool $broadcasts = false;

    public function __construct(public readonly LegacyFile $legacyFile) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('legacy-files')];
    }

    public function broadcastWhen(): bool
    {
        return self::$broadcasts;
    }
}
