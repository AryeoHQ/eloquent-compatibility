<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Support\Database\Eloquent\Compatibility\Console\Backfill;
use Support\Database\Eloquent\Compatibility\Console\CacheServedModels;
use Support\Database\Eloquent\Compatibility\Console\CheckDrift;
use Support\Database\Eloquent\Compatibility\Console\ClearServedModels;
use Support\Database\Eloquent\Compatibility\Console\CompileSql;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Events\Forwarder;

class Provider extends ServiceProvider
{
    private const array WITHOUT_CACHE = ['optimize', 'optimize:clear', 'compatibility:cache', 'compatibility:clear', 'package:discover'];

    private bool $forwarding = false;

    public function register(): void
    {
        $this->app->singleton(ServedModels::class, fn () => $this->servedModels());

        foreach ($this->app->getProviders(DatabaseServiceProvider::class) as $database) {
            $database->booted(fn () => $this->forward());
        }
    }

    public function boot(): void
    {
        $this->commands([CompileSql::class, Backfill::class, CheckDrift::class, CacheServedModels::class, ClearServedModels::class]);
        $this->optimizes(optimize: 'compatibility:cache', clear: 'compatibility:clear', key: 'compatibility');
        $this->forward();
    }

    private function forward(): void
    {
        if ($this->forwarding || $this->withoutCache()) {
            return;
        }

        $this->forwarding = true;
        $events = $this->app->make(Dispatcher::class);

        foreach ($this->app->make(ServedModels::class)->all() as $model) {
            Forwarder::register($events, $model);
        }
    }

    private function servedModels(): ServedModels
    {
        return new ServedModels(
            $this->app->bootstrapPath('cache/compatibility.php'),
            $this->app->environment('local', 'testing'),
            $this->withoutCache(),
            new Filesystem,
        );
    }

    private function withoutCache(): bool
    {
        $argv = $_SERVER['argv'] ?? [];

        return $this->app->runningInConsole() && is_array($argv) && in_array(self::command(array_values($argv)), self::WITHOUT_CACHE, true);
    }

    /**
     * @param  list<mixed>  $argv
     */
    private static function command(array $argv): null|string
    {
        foreach (array_slice($argv, 1, preserve_keys: true) as $index => $token) {
            if (is_string($token) && ! str_starts_with($token, '-') && $argv[$index - 1] !== '--env') {
                return $token;
            }
        }

        return null;
    }
}
