<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Testing;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Console\DriftQueries;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\Statements;

trait AssertsServedCompatibility
{
    /**
     * @param  class-string<Model&Compatible>  $model
     */
    protected function assertServesCompatibility(string $model): void
    {
        Assert::assertTrue(
            Container::getInstance()->make(ServedModels::class)->contains($model),
            "Discovery does not find {$model}, so no successor event reaches it. Keep the class in the composer classmap, and run php artisan optimize after adding it.",
        );

        try {
            $map = Compatibility::of($model)->resolve();
            $connection = Compatibility::connection($map);
            $views = (new DriftQueries($connection))->views($map);
            $creates = $views === null ? array_filter(
                Statements::install($map, $connection, catchingUp: true)->transaction,
                fn (string $statement) => str_starts_with($statement, 'CREATE VIEW '.SqlFragments::identifier($map->table)." AS\n"),
            ) : [];
        } catch (InvalidCompatibilityMap $exception) {
            Assert::fail("The map of {$model} does not compile: {$exception->getMessage()}");
        }

        if ($views === null) {
            Assert::assertCount(1, $creates, "Compatibility::install() of {$model} compiles no view for {$map->table}.");

            return;
        }

        Assert::assertSame(
            $views['compiled'],
            $views['installed'],
            "The installed view {$map->table} differs from the view the map of {$model} compiles. Run Compatibility::recompile({$model}::class) in a migration.",
        );
    }
}
