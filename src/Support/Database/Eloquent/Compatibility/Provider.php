<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;
use Support\Database\Eloquent\Compatibility\Bridge\EventBridge;
use Support\Database\Eloquent\Compatibility\Console\CheckDivergence;

class Provider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompatibilityMaps::class);
        $this->app->singleton(EventBridge::class);
    }

    public function boot(Dispatcher $events): void
    {
        $release = function (): void {
            if ($this->app->resolved(EventBridge::class)) {
                $this->app->make(EventBridge::class)->releaseAbandoned();
            }
        };

        $events->listen(Looping::class, $release);
        $this->app->terminating($release);

        $this->commands([CheckDivergence::class]);
    }
}
