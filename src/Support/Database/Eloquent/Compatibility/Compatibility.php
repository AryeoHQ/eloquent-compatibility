<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Branch;
use Support\Database\Eloquent\Compatibility\Definition\Guard;
use Support\Database\Eloquent\Compatibility\Definition\Hide;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
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

    /** @var array<string, Identifier> */
    private array $identifiers = [];

    private null|string $retainedTable = null;

    private null|string $retainedKey = null;

    private bool $timestamps = false;

    private null|string $discriminator = null;

    private null|Successor $primary = null;

    private null|Closure $selector = null;

    /** @var array<class-string<Model>, Branch> */
    private array $branches = [];

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

    public static function install(string $model): void
    {
        $map = self::of($model)->resolve();
        $connection = (new $map->model)->getConnection();

        try {
            (new Installer($connection))->install($map, fn () => $connection->transaction(fn () => Statements::install($map, $connection)));
        } finally {
            LegacyBuilder::forgetViews();
        }
    }

    public static function recompile(string $model): void
    {
        self::run($model, Statements::recompile(...));
    }

    public static function uninstall(string $model): void
    {
        self::run($model, Statements::uninstall(...));
    }

    /**
     * @param  Closure(Identifier): mixed  $identifier
     */
    public function identifiedBy(string $attribute, Closure $identifier): self
    {
        if (array_key_exists($attribute, $this->identifiers)) {
            throw $this->invalid("identifier '{$attribute}' is declared twice");
        }

        ResolvedMap::refuseCaptures($this->map, "the closure of identifier '{$attribute}'", $identifier);

        $declared = new Identifier($this->map, $attribute);
        $identifier($declared);
        $this->identifiers[$attribute] = $declared;

        return $this;
    }

    public function retainedIn(string $table, null|string $key = null): self
    {
        if ($this->retainedTable !== null) {
            throw $this->invalid('retainedIn() is declared twice');
        }

        if ($table === '' || $key === '') {
            throw $this->invalid('retainedIn() names an empty table or key');
        }

        $this->retainedTable = $table;
        $this->retainedKey = $key;

        return $this;
    }

    public function timestamps(): self
    {
        $this->timestamps = true;

        return $this;
    }

    public function discriminatedBy(string $column): self
    {
        if ($this->discriminator !== null) {
            throw $this->invalid('discriminatedBy() is declared twice');
        }

        if ($column === '' || str_contains($column, '.')) {
            throw $this->invalid("discriminatedBy() names '{$column}', which names no column of the primary successor's own table");
        }

        $this->discriminator = $column;

        return $this;
    }

    /**
     * @param  null|Closure(Successor): mixed  $definition
     */
    public function servedFrom(string|Closure $successor, null|Closure $definition = null): self
    {
        if ($this->primary !== null || $this->selector !== null) {
            throw $this->invalid('servedFrom() is declared twice');
        }

        if ($successor instanceof Closure) {
            if ($definition !== null) {
                throw $this->invalid('servedFrom() takes a selector closure alone; declare each successor it chooses with as()');
            }

            ResolvedMap::refuseCaptures($this->map, 'the servedFrom() selector', $successor);
            $this->selector = $successor;

            return $this;
        }

        if ($definition === null) {
            throw $this->invalid("servedFrom() names {$successor} without a closure over it");
        }

        if (! is_subclass_of($successor, Model::class)) {
            throw $this->invalid("servedFrom() names {$successor}, which is not an Eloquent model");
        }

        ResolvedMap::refuseCaptures($this->map, 'the servedFrom() closure', $definition);

        $this->primary = new Successor($this->map, $successor);
        $definition($this->primary);

        return $this;
    }

    /**
     * @param  Closure(Successor): mixed  $definition
     */
    public function as(string $successor, Closure $definition): self
    {
        if ($this->selector === null) {
            throw $this->invalid('as() declares a successor for the servedFrom() selector, and no servedFrom() selector precedes it');
        }

        if (array_key_exists($successor, $this->branches)) {
            throw $this->invalid("as({$successor}) is declared twice");
        }

        $branch = Branch::declare($this->map, $successor, $definition);
        $this->branches[$branch->model] = $branch;

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
     * @return array<class-string<Model>, list<class-string<Model>>>
     */
    public function successors(): array
    {
        $served = [];

        foreach ($this->branches === [] ? array_filter([$this->primary]) : array_map(fn (Branch $branch) => $branch->successor, $this->branches) as $primary) {
            $models = [];

            for ($pending = [$primary]; $pending !== [];) {
                $successor = array_shift($pending);
                $models[] = $successor->model;

                foreach ($successor->relations() as $relation) {
                    $pending[] = $relation->successor;
                }
            }

            $served[$primary->model] = $models;
        }

        return $served;
    }

    public function resolve(): ResolvedMap
    {
        return new ResolvedMap(
            $this->model,
            $this->map,
            $this->identifiers,
            $this->retainedTable,
            $this->retainedKey,
            $this->timestamps,
            $this->primary,
            $this->hides,
            $this->guards,
            $this->withoutForwarding,
            $this->discriminator,
            $this->selector,
            $this->branches,
        );
    }

    /**
     * @param  Closure(ResolvedMap, ConnectionInterface): Statements  $statements
     */
    private static function run(string $model, Closure $statements): void
    {
        $map = self::of($model)->resolve();
        $connection = (new $map->model)->getConnection();

        try {
            (new Installer($connection))->run($connection->transaction(fn () => $statements($map, $connection)));
        } finally {
            LegacyBuilder::forgetViews();
        }
    }

    private function invalid(string $problem): InvalidCompatibilityMap
    {
        return InvalidCompatibilityMap::because($this->map, $problem);
    }
}
