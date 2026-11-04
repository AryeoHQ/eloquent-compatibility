<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Console\Command;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;

final class ClearServedModels extends Command
{
    protected $signature = 'compatibility:clear';

    protected $description = 'Remove the cached list of models that serve compatibility.';

    public function handle(ServedModels $models): int
    {
        $models->clear();

        $this->components->info('Cleared the cached served models.');

        return self::SUCCESS;
    }
}
