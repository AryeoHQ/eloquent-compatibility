<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Support\ServiceProvider;
use Support\Database\Eloquent\Compatibility\Console\CompileSql;

class Provider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([CompileSql::class]);
    }
}
