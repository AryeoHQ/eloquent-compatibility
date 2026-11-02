<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Console\Command;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;

final class CacheServedModels extends Command
{
    protected $signature = 'compatibility:cache';

    protected $description = 'Discover the models that serve compatibility and cache the list.';

    public function handle(ServedModels $models): int
    {
        $models->cache();

        $this->components->info('Cached the served models.');

        return self::SUCCESS;
    }
}
