<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;

final class CompatibilityEventMirrorBypassed extends CompatibilityException
{
    /**
     * @param  list<string>  $maps
     */
    public static function byFakedDispatcher(string $relation, array $maps): self
    {
        $names = implode("', '", $maps);

        return new self(
            "A write to '{$relation}' was stopped before it reached the database, because Event::fake() replaced the model event dispatcher and its event mirror could not run. "
            ."Why: compatibility map '{$names}' raises the other side's model events from this model's events, so a faked dispatcher hides every listener of the other side and the test proves nothing about compatibility. "
            .'Instead: fake only the events the test asserts, such as Event::fake([OrderShipped::class]), or wrap the write in EventBridge::withoutMirroring() to switch the mirror off on purpose.',
        );
    }

    /**
     * @param  list<string>  $maps
     */
    public static function byFakedBus(string $trigger, array $maps): self
    {
        $names = implode("', '", $maps);

        return new self(
            'A write that runs the '.class_basename($trigger).' trigger was rolled back, because Bus::fake() fakes that trigger and the event bridge could not run it. '
            ."Why: compatibility map '{$names}' runs a state machine's trigger for a state change through the other side, so a faked bus would store the new state without the trigger and the test would prove nothing about compatibility. "
            .'Instead: fake only the jobs the test asserts, such as Bus::fake([ShipOrder::class]), or wrap the write in EventBridge::withoutMirroring() to switch the mirror off on purpose.',
        );
    }

    /**
     * @param  list<string>  $maps
     */
    public static function byUnmarkedStatement(string $model, ModelEvent $event, array $maps): self
    {
        $names = implode("', '", $maps);

        return new self(
            "The {$model} write that fired '{$event->value}' reached the database on a statement HasCompatibility never marked, so the event bridge mirrored none of it. "
            ."Why: compatibility map '{$names}' mirrors a write only on the statement HasCompatibility marks from setKeysForSaveQuery() or getAttributesForInsert(), and this write's method called neither. "
            .'Instead: write the model through the Eloquent method that calls them, or wrap the write in EventBridge::withoutMirroring() to switch the mirror off on purpose.',
        );
    }
}
