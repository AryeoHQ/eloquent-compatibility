<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge\Events;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;

final readonly class RecordMirrored
{
    /**
     * @param  list<ModelEvent>  $events
     * @param  null|array<string, mixed>  $rowBefore
     * @param  null|array<string, mixed>  $rowAfter
     */
    public function __construct(
        public string $map,
        public Model $source,
        public Model $destination,
        public array $events,
        public null|array $rowBefore,
        public null|array $rowAfter,
    ) {}
}
