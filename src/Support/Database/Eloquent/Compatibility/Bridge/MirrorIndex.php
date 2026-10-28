<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;

final class MirrorIndex
{
    /** @var array<string, MirroredMap> */
    private array $maps = [];

    /** @var array<class-string<Model>, list<MapMember>> */
    private array $models = [];

    /** @var array<string, array<string, list<MapMember>>> */
    private array $tables = [];

    /**
     * @return list<class-string<Model>> the model classes this compatibility map adds
     */
    public function add(ResolvedMap $resolvedMap, EventMirror $mirror): array
    {
        $name = $resolvedMap->name;
        $this->maps[$name] = new MirroredMap($resolvedMap, $mirror, new KeyTranslator($resolvedMap));
        $added = [];

        $previous = $resolvedMap->previousModel;
        $members = [(string) $previous->alias => (string) $previous->model];

        foreach ($resolvedMap->currentModels() as $model => $alias) {
            $members[$alias] = $model;
            $connection = (new $model)->getConnectionName() ?? (string) Model::getConnectionResolver()?->getDefaultConnection();
            $this->tables[$connection][$resolvedMap->table($alias)][] = new MapMember($name, $alias);
        }

        foreach ($members as $alias => $model) {
            if (! array_key_exists($model, $this->models)) {
                $added[] = $model;
            }

            $this->models[$model][] = new MapMember($name, $alias);
        }

        return $added;
    }

    public function resolvedMap(string $map): ResolvedMap
    {
        return $this->maps[$map]->resolvedMap;
    }

    public function keyTranslator(string $map): KeyTranslator
    {
        return $this->maps[$map]->keyTranslator;
    }

    /** @return list<ExcludedListener> */
    public function excludedListeners(string $map): array
    {
        return $this->maps[$map]->mirror->excludedListeners();
    }

    /** @return list<MapMember> */
    public function forModel(string $class): array
    {
        return $this->models[$class] ?? [];
    }

    /** @return list<MapMember> */
    public function forRelation(string $connection, string $relation): array
    {
        return $this->tables[$connection][$relation] ?? [];
    }
}
