<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;
use Support\Database\Eloquent\Compatibility\Bridge\EventBridge;
use Support\Database\Eloquent\Compatibility\Bridge\Events\Mirrored;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Throwable;

trait InteractsWithCompatibilityMaps
{
    /** @var list<Mirrored> */
    private array $mirroredEvents = [];

    private bool $recordingMirroredEvents = false;

    protected function recordMirroredEvents(): void
    {
        $this->mirroredEvents = [];

        if ($this->recordingMirroredEvents) {
            return;
        }

        $this->recordingMirroredEvents = true;

        Event::listen(Mirrored::class, function (Mirrored $mirrored): void {
            $this->mirroredEvents[] = $mirrored;
        });
    }

    /** @return list<Mirrored> */
    protected function mirroredEvents(): array
    {
        return $this->mirroredEvents;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  null|Closure(TModel, Mirrored): bool  $where
     */
    protected function assertMirrored(string $model, string $event, null|Closure $where = null, int $times = 1): void
    {
        $matches = array_filter(
            $this->mirroredEvents,
            fn (Mirrored $mirrored) => $mirrored->model instanceof $model
                && in_array($event, $mirrored->events, true)
                && ($where === null || $where($mirrored->model, $mirrored)),
        );

        Assert::assertCount(
            $times,
            $matches,
            "Expected the event bridge to raise '{$event}' on {$model} {$times} time(s). It raised: ".$this->describeMirroredEvents(),
        );
    }

    protected function assertNothingMirrored(): void
    {
        Assert::assertSame([], $this->mirroredEvents, 'Expected no mirrored events. The event bridge raised: '.$this->describeMirroredEvents());
    }

    /**
     * @param  callable(): mixed  $write
     */
    protected function assertWriteRefused(callable $write, null|string $contains = null): CompatibilityWriteRefused
    {
        try {
            DB::transaction(fn () => $write());
        } catch (Throwable $e) {
            $refusal = match (true) {
                $e instanceof CompatibilityWriteRefused => $e,
                $e instanceof QueryException => CompatibilityWriteRefused::fromQueryException($e),
                default => null,
            };

            if ($refusal === null) {
                throw $e;
            }

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
        return resolve(EventBridge::class)->withoutMirroring($callback);
    }

    private function describeMirroredEvents(): string
    {
        if ($this->mirroredEvents === []) {
            return 'nothing';
        }

        return implode('; ', array_map(
            fn (Mirrored $mirrored) => class_basename($mirrored->model).' '.json_encode($mirrored->model->getKey()).' ['.implode(', ', $mirrored->events).']',
            $this->mirroredEvents,
        ));
    }
}
