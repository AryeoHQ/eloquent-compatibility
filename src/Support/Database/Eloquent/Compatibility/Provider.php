<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Console\CacheServedModels;
use Support\Database\Eloquent\Compatibility\Console\ClearServedModels;
use Support\Database\Eloquent\Compatibility\Console\CompileSql;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Events\Forwarder;

class Provider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ServedModels::class, fn () => $this->servedModels());
    }

    public function boot(): void
    {
        $this->commands([CompileSql::class, CacheServedModels::class, ClearServedModels::class]);
        $this->optimizes(optimize: 'compatibility:cache', clear: 'compatibility:clear', key: 'compatibility');
        $this->app->booted(function (): void {
            $served = $this->servedModels();

            if (! $served->available()) {
                return;
            }

            foreach ($served->all() as $model) {
                Forwarder::register($this->app->make(Dispatcher::class), LegacyBuilder::mapOf($model));
            }
        });
    }

    private function servedModels(): ServedModels
    {
        return new ServedModels(
            $this->app->bootstrapPath('cache/compatibility.php'),
            $this->app->environment('local', 'testing'),
            new Filesystem,
        );
    }
}
