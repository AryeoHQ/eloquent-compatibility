<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
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

    private null|Successor $primary = null;

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
        self::run($model, Statements::install(...));
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

    /**
     * @param  Closure(Successor): mixed  $definition
     */
    public function servedFrom(string $successor, Closure $definition): self
    {
        if ($this->primary !== null) {
            throw $this->invalid('servedFrom() is declared twice');
        }

        if (! is_subclass_of($successor, Model::class)) {
            throw $this->invalid("servedFrom() names {$successor}, which is not an Eloquent model");
        }

        ResolvedMap::refuseCaptures($this->map, 'the servedFrom() closure', $definition);

        $this->primary = new Successor($this->map, $successor);
        $definition($this->primary);

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
