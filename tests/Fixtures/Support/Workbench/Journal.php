<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;

final class Journal
{
    /** @var list<string> */
    public array $entries = [];

    public function handle(Model $model): void
    {
        $this->entries[] = class_basename($model).' '.json_encode($model->getKey());
    }
}
