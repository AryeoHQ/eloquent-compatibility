<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Write\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\Write\WriteRouter;
use Throwable;

trait InteractsWithCompatibilityMaps
{
    /** @var list<RecordMirrored> */
    private array $mirroredEvents = [];

    private bool $recordingMirroredEvents = false;

    protected function recordMirroredEvents(): void
    {
        $this->mirroredEvents = [];

        if ($this->recordingMirroredEvents) {
            return;
        }

        $this->recordingMirroredEvents = true;

        Event::listen(RecordMirrored::class, function (RecordMirrored $recordMirrored): void {
            $this->mirroredEvents[] = $recordMirrored;
        });
    }

    /** @return list<RecordMirrored> */
    protected function mirroredEvents(): array
    {
        return $this->mirroredEvents;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  null|Closure(TModel, RecordMirrored): bool  $where
     */
    protected function assertMirrored(string $model, ModelEvent $event, null|Closure $where = null, int $times = 1): void
    {
        $matches = array_filter(
            $this->mirroredEvents,
            fn (RecordMirrored $recordMirrored) => $recordMirrored->destination instanceof $model
                && in_array($event, $recordMirrored->events, true)
                && ($where === null || $where($recordMirrored->destination, $recordMirrored)),
        );

        Assert::assertCount(
            $times,
            $matches,
            "Expected the write router to raise '{$event->value}' on {$model} {$times} time(s). It raised: ".$this->describeMirroredEvents(),
        );
    }

    protected function assertNothingMirrored(): void
    {
        Assert::assertSame([], $this->mirroredEvents, 'Expected no mirrored events. The write router raised: '.$this->describeMirroredEvents());
    }

    /**
     * @param  callable(): mixed  $write
     */
    protected function assertWriteRefused(callable $write, null|string $contains = null): CompatibilityWriteRefused
    {
        try {
            DB::transaction(fn () => $write());
        } catch (Throwable $e) {
            if (! $e instanceof CompatibilityWriteRefused) {
                throw $e;
            }

            $refusal = $e;

            if ($contains !== null) {
                Assert::assertStringContainsString($contains, $refusal->getMessage());
            }

            return $refusal;
        }

        Assert::fail('Expected the write to be refused, and it succeeded.');
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    protected function withoutEventMirroring(callable $callback): mixed
    {
        return resolve(WriteRouter::class)->withoutMirroring($callback);
    }

    private function describeMirroredEvents(): string
    {
        if ($this->mirroredEvents === []) {
            return 'nothing';
        }

        return implode('; ', array_map(
            fn (RecordMirrored $recordMirrored) => class_basename($recordMirrored->destination).' '.json_encode($recordMirrored->destination->getKey()).' ['.implode(', ', array_map(fn (ModelEvent $event) => $event->value, $recordMirrored->events)).']',
            $this->mirroredEvents,
        ));
    }
}
