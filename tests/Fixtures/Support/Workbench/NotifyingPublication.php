<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

final class NotifyingPublication extends Publication
{
    protected $dispatchesEvents = ['updated' => PublicationChanged::class, 'saved' => PublicationChanged::class];
}
