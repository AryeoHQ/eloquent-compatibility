<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;
use Throwable;

final class Journal
{
    /** @var list<string> */
    public array $entries = [];

    public null|Throwable $throws = null;

    public function handle(Model $model): void
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $this->entries[] = class_basename($model).' '.json_encode($model->getKey());
    }
}
