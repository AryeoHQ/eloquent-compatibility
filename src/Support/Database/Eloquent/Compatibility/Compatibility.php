<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Contracts\ManagesDependents;
use Support\Database\Eloquent\Compatibility\Definition\Guard;
use Support\Database\Eloquent\Compatibility\Definition\Hide;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Schema\Statements;

final class Compatibility
{
    /** @var class-string<Model> */
    private readonly string $model;

    private readonly string $map;

    private null|string $retainedTable = null;

    private null|string $successorKey = null;

    private null|Successor $root = null;

    /** @var null|class-string<ManagesDependents> */
    private null|string $dependents = null;

    /** @var list<Hide> */
    private array $hides = [];

    /** @var array<string, Guard> */
    private array $guards = [];

    /** @var array<class-string, string> */
    private array $withoutForwarding = [];

    public function __construct(string $model)
    {
        if (! is_subclass_of($model, Model::class)) {
            throw InvalidCompatibilityMap::because($model, 'it names no Eloquent model');
        }

        $this->model = $model;
        $this->map = (new $model)->getTable();
    }

    public static function of(string $model): self
    {
        $compatibility = new self($model);

        if (! is_subclass_of($model, Compatible::class)) {
            throw InvalidCompatibilityMap::because($compatibility->map, class_basename($model).' does not implement '.Compatible::class.', so it declares no map');
        }

        return $model::compatibility($compatibility);
    }

    public static function prepare(string $model): void
    {
        self::run($model, Statements::prepare(...));
    }

    public static function install(string $model, CarbonInterval $statementTimeout): void
    {
        $map = self::of($model)->resolve();
        $connection = self::connection($map);

        try {
            (new Installer($connection))->install(
                map: $map,
                statementTimeout: $statementTimeout,
                compile: fn () => self::logged(
                    map: $map,
                    statements: Statements::install(
                        map: $map,
                        connection: $connection,
                    ),
                ),
            );
        } finally {
            LegacyBuilder::forgetViews();
        }
    }

    public static function update(string $model): void
    {
        self::run(
            model: $model,
            statements: Statements::update(...),
        );
    }

    public static function rollback(string $model): void
    {
        $map = self::of($model)->resolve();
        $connection = self::connection($map);

        try {
            (new Installer($connection))->rollback(
                map: $map,
                compile: fn () => self::logged(
                    map: $map,
                    statements: Statements::rollback(
                        map: $map,
                        connection: $connection,
                    ),
                ),
            );
        } finally {
            LegacyBuilder::forgetViews();
        }
    }

    public static function retire(string $model): void
    {
        self::run(
            model: $model,
            statements: Statements::retire(...),
        );
    }

    public function retainedIn(string $table): self
    {
        if ($this->retainedTable !== null) {
            throw $this->invalid('retainedIn() is declared twice');
        }

        if ($table === '') {
            throw $this->invalid('retainedIn() names an empty table');
        }

        $this->retainedTable = $table;

        return $this;
    }

    /**
     * @param  class-string<Model>  $root
     * @param  Closure(Successor): mixed  $successor
     */
    public function servedFrom(string $root, string $key, Closure $successor): self
    {
        if ($this->root !== null) {
            throw $this->invalid('servedFrom() is declared twice');
        }

        if (! is_subclass_of(
            object_or_class: $root,
            class: Model::class,
        )) {
            throw $this->invalid("servedFrom() names {$root}, which is not an Eloquent model");
        }

        if ($key === '' || str_contains(
            haystack: $key,
            needle: '.',
        )) {
            throw $this->invalid("servedFrom() names the successor key '{$key}', which names no column of {$this->map}");
        }

        ResolvedMap::refuseCaptures(
            map: $this->map,
            role: 'the servedFrom() closure',
            closure: $successor,
        );

        $this->root = new Successor(
            map: $this->map,
            model: $root,
        );
        $this->successorKey = $key;
        $successor($this->root);

        return $this;
    }

    /**
     * @param  class-string<ManagesDependents>  $dependents
     */
    public function dependents(string $dependents): self
    {
        if ($this->dependents !== null) {
            throw $this->invalid('dependents() is declared twice');
        }

        if (! is_subclass_of(
            object_or_class: $dependents,
            class: ManagesDependents::class,
        )) {
            throw $this->invalid("dependents() names {$dependents}, which does not implement ".ManagesDependents::class);
        }

        $this->dependents = $dependents;

        return $this;
    }

    public function hide(Closure $scope, string $because): self
    {
        ResolvedMap::refuseCaptures($this->map, 'a hide() scope', $scope);

        if (trim($because) === '') {
            throw $this->invalid('hide() gives no reason; it takes the reason the legacy model never shows these records');
        }

        $this->hides[] = new Hide($scope, $because);

        return $this;
    }

    public function guard(string $name, Closure $when, string $because, string $instead): self
    {
        ResolvedMap::refuseCaptures($this->map, "guard '{$name}'", $when);

        if (trim($name) === '' || trim($because) === '' || trim($instead) === '') {
            throw $this->invalid("guard '{$name}' needs a name, a reason and what to do instead");
        }

        if (array_key_exists($name, $this->guards)) {
            throw $this->invalid("guard '{$name}' is declared twice");
        }

        $this->guards[$name] = new Guard($name, $when, $because, $instead);

        return $this;
    }

    public function withoutForwardingTo(string $listener, string $because): self
    {
        if (! class_exists($listener)) {
            throw $this->invalid("withoutForwardingTo() names {$listener}, which is not a class");
        }

        if (trim($because) === '') {
            throw $this->invalid("withoutForwardingTo({$listener}) gives no reason");
        }

        if (array_key_exists($listener, $this->withoutForwarding)) {
            throw $this->invalid("withoutForwardingTo({$listener}) is declared twice");
        }

        $this->withoutForwarding[$listener] = $because;

        return $this;
    }

    /**
     * @return list<class-string<Model>>
     */
    public function successors(): array
    {
        if ($this->root === null) {
            return [];
        }

        $models = [];

        for ($pending = [$this->root]; $pending !== [];) {
            $successor = array_shift($pending);
            $models[] = $successor->model;

            foreach ([...$successor->relations(), ...array_values($successor->morph()->targets ?? [])] as $relation) {
                $pending[] = $relation->successor;
            }
        }

        return $models;
    }

    public function resolve(): ResolvedMap
    {
        return new ResolvedMap(
            $this->model,
            $this->map,
            $this->retainedTable,
            $this->successorKey,
            $this->root,
            $this->hides,
            $this->guards,
            $this->withoutForwarding,
            $this->dependents,
        );
    }

    public static function connection(ResolvedMap $map): ConnectionInterface
    {
        $legacy = new $map->model;
        $connection = $legacy->getConnection();
        $successors = [];

        $successors[$map->primary->model] = [(new $map->primary->model)->getConnectionName(), 'the successor '.class_basename($map->primary->model)];

        foreach ($map->related as $related) {
            $successors[$related->model] = [(new $related->model)->getConnectionName() ?? $successors[$related->parent][0] ?? null, 'the relation from '.class_basename($related->parent).' to '.class_basename($related->model)];
        }

        self::refusePrefix($map, $connection, class_basename($legacy));

        foreach ($successors as [$name, $successor]) {
            $name ??= DB::getDefaultConnection();

            if ($name === $connection->getName()) {
                continue;
            }

            if (! is_array(Config::get("database.connections.{$name}"))) {
                throw InvalidCompatibilityMap::because($map->table, "{$successor} reads the connection {$name}, which config/database.php does not declare");
            }

            self::refusePrefix($map, DB::connection($name), $successor);

            if (self::database(DB::connection($name)) !== self::database($connection)) {
                throw InvalidCompatibilityMap::because($map->table, "{$successor} reads the connection {$name}, which reaches another database than the connection {$connection->getName()} of ".class_basename($legacy).'; the view and its filters read every successor through the connection of '.class_basename($legacy).', so a map reads one database');
            }

            foreach (self::readTargets($name) as $target) {
                if ($target !== array_map($connection->getConfig(...), ['driver' => 'driver', 'database' => 'database', 'search_path' => 'search_path'])) {
                    throw InvalidCompatibilityMap::because($map->table, "{$successor} reads the connection {$name}, whose read configuration reaches another database or search_path than the connection {$connection->getName()} of ".class_basename($legacy).'; the view joins the successors the connection of '.class_basename($legacy).' writes, so the successor model would read other rows than the view serves');
                }
            }
        }

        return $connection;
    }

    private static function refusePrefix(ResolvedMap $map, Connection $connection, string $reader): void
    {
        if ($connection->getTablePrefix() !== '') {
            throw InvalidCompatibilityMap::because($map->table, "{$reader} reads the connection {$connection->getName()}, which adds the table prefix {$connection->getTablePrefix()}, and the package reads and compiles every table by its unprefixed name; use a connection without a prefix");
        }
    }

    /**
     * @return list<array{driver: mixed, database: mixed, search_path: mixed}>
     */
    private static function readTargets(string $name): array
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration((array) Config::get("database.connections.{$name}"));
        $reads = $config['read'] ?? null;
        $sections = match (true) {
            ! is_array($reads) => [[]],
            array_is_list($reads) && $reads !== [] => $reads,
            default => [$reads],
        };
        $base = array_diff_key($config, ['read' => true, 'write' => true]);

        return array_values(array_map(fn (mixed $section) => array_map(
            fn (string $key) => [...$base, ...(is_array($section) ? $section : [])][$key] ?? null,
            ['driver' => 'driver', 'database' => 'database', 'search_path' => 'search_path'],
        ), $sections));
    }

    /**
     * @param  Closure(ResolvedMap, ConnectionInterface): Statements  $statements
     */
    private static function run(string $model, Closure $statements): void
    {
        $map = self::of($model)->resolve();
        $connection = self::connection($map);

        try {
            (new Installer($connection))->compileAndRun(
                tables: $map->tables(),
                compile: fn () => self::logged(
                    map: $map,
                    statements: $statements($map, $connection),
                ),
            );
        } finally {
            LegacyBuilder::forgetViews();
        }
    }

    private static function logged(ResolvedMap $map, Statements $statements): Statements
    {
        foreach ($statements->notes as $note) {
            Log::info("Compatibility map '{$map->table}' {$statements->operation}: {$note}");
        }

        return $statements;
    }

    /**
     * @return list<mixed>
     */
    private static function database(Connection $connection): array
    {
        return array_map($connection->getConfig(...), ['driver', 'host', 'port', 'database', 'search_path', 'prefix']);
    }

    private function invalid(string $problem): InvalidCompatibilityMap
    {
        return InvalidCompatibilityMap::because($this->map, $problem);
    }
}
