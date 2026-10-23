<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

final class CompatibilityEventMirrorBypassed extends CompatibilityException
{
    /**
     * @param  list<string>  $maps
     */
    public static function byFakedDispatcher(string $relation, array $maps): self
    {
        $names = implode("', '", $maps);

        return new self(
            "A write to '{$relation}' reached the database without its event mirror, because Event::fake() replaced the model event dispatcher. "
            ."Why: compatibility map '{$names}' raises the other side's model events from this model's events, so a faked dispatcher hides every listener of the other side and the test proves nothing about compatibility. "
            .'Instead: fake only the events the test asserts, such as Event::fake([OrderShipped::class]), or wrap the write in EventBridge::withoutMirroring() to switch the mirror off on purpose.',
        );
    }
}
