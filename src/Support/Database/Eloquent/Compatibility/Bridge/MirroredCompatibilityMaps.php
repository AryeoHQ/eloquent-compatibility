<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;
use Support\Database\Eloquent\Compatibility\Mapping\Declaration;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;

final class MirroredCompatibilityMaps
{
    /** @var array<string, array{declaration: Declaration, mirror: EventMirror, locator: Locator}> */
    private array $maps = [];

    /** @var array<class-string<Model>, list<array{map: string, alias: string}>> */
    private array $models = [];

    /** @var array<string, list<array{map: string, alias: string}>> */
    private array $tables = [];

    /** @var array<string, list<class-string<Model>>> */
    private array $relations = [];

    /**
     * @return list<class-string<Model>> the model classes this compatibility map adds
     */
    public function add(Declaration $declaration, EventMirror $mirror): array
    {
        $name = $declaration->name;
        $this->maps[$name] = ['declaration' => $declaration, 'mirror' => $mirror, 'locator' => new Locator($declaration)];
        $added = [];

        $previous = $declaration->previousModel;
        $members = [(string) $previous->alias => [(string) $previous->model, $previous->view]];

        foreach ($declaration->currentModels() as $model => $alias) {
            $members[$alias] = [$model, $declaration->table($alias)];
            $this->tables[$declaration->table($alias)][] = ['map' => $name, 'alias' => $alias];
        }

        foreach ($members as $alias => [$model, $relation]) {
            if (! array_key_exists($model, $this->models)) {
                $added[] = $model;
            }

            $this->models[$model][] = ['map' => $name, 'alias' => $alias];
            $this->relations[$relation][] = $model;
        }

        return $added;
    }

    public function declaration(string $map): Declaration
    {
        return $this->maps[$map]['declaration'];
    }

    public function locator(string $map): Locator
    {
        return $this->maps[$map]['locator'];
    }

    /** @return list<ExcludedListener> */
    public function excludedListeners(string $map): array
    {
        return $this->maps[$map]['mirror']->excludedListeners();
    }

    /** @return list<array{map: string, alias: string}> */
    public function forModel(string $class): array
    {
        return $this->models[$class] ?? [];
    }

    /** @return list<array{map: string, alias: string}> */
    public function forTable(string $table): array
    {
        return $this->tables[$table] ?? [];
    }

    /** @return list<class-string<Model>> */
    public function modelsOf(string $relation): array
    {
        return array_values(array_unique($this->relations[$relation] ?? []));
    }

    /** @return list<string> */
    public function mapsOf(string $relation): array
    {
        $maps = [];

        foreach ($this->modelsOf($relation) as $model) {
            foreach ($this->forModel($model) as $member) {
                $maps[] = $member['map'];
            }
        }

        return array_values(array_unique($maps));
    }
}
