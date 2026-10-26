<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge\Events;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;

final readonly class RecordMirrored
{
    /**
     * @param  list<ModelEvent>  $events
     * @param  null|array<string, mixed>  $before
     * @param  null|array<string, mixed>  $after
     */
    public function __construct(
        public string $map,
        public Model $source,
        public Model $model,
        public array $events,
        public null|array $before,
        public null|array $after,
    ) {}
}
