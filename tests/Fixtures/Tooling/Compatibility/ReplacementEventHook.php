<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

trait ReplacementEventHook
{
    /**
     * @param  string  $event
     * @param  bool  $halt
     * @return mixed
     */
    protected function fireModelEvent($event, $halt = true)
    {
        return true;
    }
}
