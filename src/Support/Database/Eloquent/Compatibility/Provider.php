<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Support\ServiceProvider;
use Support\Database\Eloquent\Compatibility\Console\CheckDrift;
use Support\Database\Eloquent\Compatibility\Write\WriteRouter;

class Provider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompatibilityMaps::class);
        $this->app->singleton(WriteRouter::class);
    }

    public function boot(): void
    {
        $this->commands([CheckDrift::class]);
    }
}
