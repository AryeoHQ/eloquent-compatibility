<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;

final readonly class GadgetUpdating
{
    public function __construct(public Model $gadget) {}
}
