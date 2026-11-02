<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Support\Database\Eloquent\Compatibility\Console\CacheServedModels;
use Support\Database\Eloquent\Compatibility\Console\ClearServedModels;
use Support\Database\Eloquent\Compatibility\Console\CompileSql;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;

class Provider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ServedModels::class, fn () => new ServedModels(
            $this->app->bootstrapPath('cache/compatibility.php'),
            $this->app->environment('local', 'testing'),
            new Filesystem,
        ));
    }

    public function boot(): void
    {
        $this->commands([CompileSql::class, CacheServedModels::class, ClearServedModels::class]);
        $this->optimizes(optimize: 'compatibility:cache', clear: 'compatibility:clear', key: 'compatibility');
    }
}
