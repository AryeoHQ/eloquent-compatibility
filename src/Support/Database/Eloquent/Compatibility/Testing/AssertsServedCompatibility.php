<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Testing;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PHPUnit\Framework\Assert;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Console\DriftFinding;
use Support\Database\Eloquent\Compatibility\Console\DriftQueries;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityException;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\CarriesLegacy;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\Statements;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use Support\Database\Eloquent\Compatibility\Write\RetainedRows;

trait AssertsServedCompatibility
{
    /**
     * @param  class-string<Model&Compatible>  $model
     * @param  Closure(): mixed  $nativeCreate
     */
    protected function assertServesCompatibility(string $model, Closure $nativeCreate): void
    {
        $served = Container::getInstance()->make(ServedModels::class);

        Assert::assertTrue(
            $served->contains($model),
            "Discovery does not find {$model}, so no successor event reaches it. Keep the class in the composer classmap, and run php artisan optimize after adding it.",
        );

        try {
            $map = Compatibility::of($model)->resolve();
            $connection = Compatibility::connection($map);
            $views = (new DriftQueries($connection))->views($map);

            if ($views === null) {
                Statements::install(
                    map: $map,
                    connection: $connection,
                );
            }
        } catch (InvalidCompatibilityMap $exception) {
            Assert::fail("The map of {$model} does not compile: {$exception->getMessage()}");
        }

        if ($views !== null) {
            Assert::assertSame(
                $views['compiled'],
                $views['installed'],
                "The installed view {$map->table} differs from the view the map of {$model} compiles. Run Compatibility::update({$model}::class) in a migration.",
            );
        }

        $siblings = [];

        foreach ($served->all() as $sibling) {
            if ($sibling === $model) {
                continue;
            }

            try {
                $siblings[] = Compatibility::of($sibling)->resolve();
            } catch (InvalidCompatibilityMap $exception) {
                Assert::fail("The map of {$sibling}, which compatibility:check reads beside {$model} to prove that no shared successor serves two legacy records, does not compile: {$exception->getMessage()}");
            }
        }
        if ($map->legacyInitializer === null) {
            $uninitialized = [];
            $legacy = new $model;
            $allocated = [$legacy->getKeyName(), $map->successorKey, ...($legacy->usesUniqueIds() ? $legacy->uniqueIds() : [])];

            $read = $views === null ? $map->table : ViewCompiler::retainedTable($map);

            foreach ((new ColumnCatalog($connection, throughSearchPath: true))->of($read) as $name => $column) {
                if ($column->needsAValue() && ! in_array($name, $allocated, true) && $map->legacyOnly([$name]) !== []) {
                    $uninitialized[] = $name;
                }
            }

            Assert::assertSame([], $uninitialized, "The legacy-only column {$read}.".implode(', ', $uninitialized).' is NOT NULL with no default, and the map of '.class_basename($model).' declares no legacyInitializedBy(), so a native create cannot fill it.');
        }

        $connection->beginTransaction();

        try {
            if ($views === null) {
                try {
                    Compatibility::install($model, statementTimeout: CarbonInterval::minutes(1));
                } catch (CompatibilityException|LogicException $exception) {
                    Assert::fail("Compatibility::install() of {$model} refuses inside the test's transaction: {$exception->getMessage()}");
                }
            }

            $root = $nativeCreate();

            Assert::assertInstanceOf($map->primary->model, $root, "nativeCreate creates no {$map->primary->model} to serve as the root of a new {$model}; return the root it creates.");
            Assert::assertTrue($root->exists, "nativeCreate returns a {$map->primary->model} it never saved.");
            Assert::assertTrue(RetainedRows::member(root: $root, map: $map), 'nativeCreate creates a '.class_basename($map->primary->model).' outside the when() of '.class_basename($model).", so a native create inserts no {$map->table} row. Set its membership column at birth, through a Creating listener.");

            Assert::assertTrue(
                $connection->table(ViewCompiler::retainedTable($map))->where(
                    column: $map->successorKey,
                    operator: '=',
                    value: $root->getKey(),
                )->exists(),
                'nativeCreate inserts no '.ViewCompiler::retainedTable($map).' row for the new '.class_basename($map->primary->model).'. Use '.CarriesLegacy::class.' on '.class_basename($map->primary->model).'.',
            );

            foreach ($siblings as $sibling) {
                Assert::assertFalse(
                    $sibling->primary->model === $map->primary->model && RetainedRows::member(root: $root, map: $sibling),
                    'nativeCreate creates a '.class_basename($map->primary->model).' that the when() of '.class_basename($model).' and of '.class_basename($sibling->model).' both match, so one root would gain a legacy row in each. Make membership exclusive across the maps that share it.',
                );
            }

            $findings = (new DriftQueries($connection))->membership(
                map: $map,
                siblings: $siblings,
                limit: 5,
            );

            Assert::assertSame([], array_map(
                callback: fn (DriftFinding $finding) => "{$finding->record}: {$finding->detail}",
                array: $findings,
            ), "compatibility:check finds a membership drift in {$map->table}.");
        } finally {
            $connection->rollBack();
            LegacyBuilder::forgetViews();
        }
    }
}
