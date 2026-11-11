<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final class QueueNestedListingActions implements WritesAttribute
{
    /** @var list<string> */
    public static array $ran = [];

    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->root()->setAttribute(
            key: 'name',
            value: $value,
        );
        $successors->afterSave(function () use ($successors): void {
            self::$ran[] = 'queued by the operation';
            $successors->afterSave(function (): void {
                self::$ran[] = 'queued by that action';
            });
        });
    }
}
