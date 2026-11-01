<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;

/**
 * Indexes the registered compatibility maps by model class and by current table.
 */
final class MirrorIndex
{
    /** @var array<string, ResolvedMap> */
    private array $maps = [];

    /** @var array<string, EventMirror> */
    private array $mirrors = [];

    /** @var array<class-string<Model>, list<MapMember>> */
    private array $models = [];

    /** @var array<string, list<array{null|string, MapMember}>> */
    private array $tables = [];

    /**
     * @return list<class-string<Model>> the model classes this compatibility map adds
     */
    public function add(ResolvedMap $resolvedMap, EventMirror $mirror): array
    {
        $name = $resolvedMap->name;
        $this->maps[$name] = $resolvedMap;
        $this->mirrors[$name] = $mirror;
        $added = [];
        $previous = $resolvedMap->previousModel;
        $members = [(string) $previous->alias => (string) $previous->model];

        foreach ($resolvedMap->currentModels() as $model => $alias) {
            $members[$alias] = $model;
            $this->tables[$resolvedMap->table($alias)][] = [(new $model)->getConnectionName(), new MapMember($name, $alias)];
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
        return $this->maps[$map];
    }

    /** @return list<ExcludedListener> */
    public function excludedListeners(string $map): array
    {
        return $this->mirrors[$map]->excludedListeners();
    }

    /** @return list<MapMember> */
    public function forModel(string $class): array
    {
        return $this->models[$class] ?? [];
    }

    /**
     * The map whose previous model is this class, if any.
     */
    public function previousOf(string $class): null|ResolvedMap
    {
        foreach ($this->forModel($class) as $member) {
            $resolvedMap = $this->maps[$member->map];

            if ($member->alias === $resolvedMap->previousAlias()) {
                return $resolvedMap;
            }
        }

        return null;
    }

    /**
     * Every current-model member over this table on this connection, whatever model class the map names for it.
     *
     * @return list<MapMember>
     */
    public function forRelation(string $connection, string $relation): array
    {
        $default = Model::getConnectionResolver()?->getDefaultConnection();

        return array_values(array_map(
            fn (array $member) => $member[1],
            array_filter($this->tables[$relation] ?? [], fn (array $member) => ($member[0] ?? $default) === $connection),
        ));
    }
}
