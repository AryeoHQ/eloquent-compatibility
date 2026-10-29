<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Closure;
use Illuminate\Contracts\Container\ContextualAttribute;
use Illuminate\Database\Eloquent\Model;
use ReflectionAttribute;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Support\Database\Eloquent\Compatibility\Bridge\EventReplayer;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\TriggerFunction;

final readonly class ResolvedMap
{
    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    private const string FULL_WORDS = '/^[a-z]{3,}(?:_[a-z]{3,})*$/';

    private const string TIMESTAMP_WITHOUT_TIME_ZONE = '/^timestamp\s*(?:\(\s*\d+\s*\))?(?:\s+without\s+time\s+zone)?$/i';

    private const int MOST_STATISTICS_EXPRESSIONS = 8;

    private const array VARIABLES = ['previous_row', 'affected_rows', 'created_key'];

    /**
     * @param  list<DependentCurrentModel>  $dependentCurrentModels
     * @param  list<Attribute>  $attributes
     * @param  list<UnrepresentableState>  $unrepresentableStates
     * @param  array<string, AliasedTable>  $tables
     */
    private function __construct(
        public string $name,
        public PreviousModel $previousModel,
        public PrimaryCurrentModel $primaryCurrentModel,
        public array $dependentCurrentModels,
        public array $attributes,
        public array $unrepresentableStates,
        private array $tables,
    ) {}

    public static function of(CompatibilityMap $map): self
    {
        $name = $map->name();
        $previous = $map->previousModel();
        $primary = $map->primaryCurrentModel();
        $dependents = $map->dependentCurrentModels();

        if (preg_match('/^[a-z](?:[a-z0-9]|_(?!_)){0,23}$/', $name) !== 1 || str_ends_with($name, '_')) {
            throw InvalidCompatibilityMap::because($name, 'its name must be at most 24 lowercase letters, digits and single underscores');
        }

        $previous = self::previous($name, $previous);
        $tables = [(string) $previous->alias => new AliasedTable((string) $previous->table, (string) $previous->keyColumn)];
        $aliases = [];

        foreach ([$primary, ...$dependents] as $current) {
            $model = self::model($name, $current->model);

            if ($current->alias === null) {
                throw InvalidCompatibilityMap::because($name, "current model {$current->model} says no aliasedAs()");
            }

            if (array_key_exists($current->alias, $tables)) {
                throw InvalidCompatibilityMap::because($name, "alias '{$current->alias}' is used twice");
            }

            if (array_key_exists($model::class, $aliases)) {
                throw InvalidCompatibilityMap::because($name, "aliases '{$aliases[$model::class]}' and '{$current->alias}' both name current model ".$model::class.'; each current model class has one alias');
            }

            $aliases[$model::class] = $current->alias;
            $tables[$current->alias] = new AliasedTable($model->getTable(), $model->getKeyName());
        }

        foreach ($dependents as $dependent) {
            if ($dependent->primaryForeignKey === null) {
                throw InvalidCompatibilityMap::because($name, "dependent current model '{$dependent->alias}' says no primaryForeignKey(), so no row of it can be tied to the primary current model");
            }
        }

        foreach (array_keys($tables) as $alias) {
            if (preg_match(self::IDENTIFIER, $alias) !== 1 || in_array($alias, ['new', 'old'], true)) {
                throw InvalidCompatibilityMap::because($name, "alias '{$alias}' must be a lowercase identifier other than new and old");
            }

            if (in_array($alias, ReservedWords::POSTGRESQL, true)) {
                throw InvalidCompatibilityMap::because($name, "alias '{$alias}' is a reserved PostgreSQL word");
            }

            if (in_array($alias, self::VARIABLES, true) || str_starts_with($alias, 'key_')) {
                throw InvalidCompatibilityMap::because($name, "alias '{$alias}' names a variable the compiled triggers declare; choose another alias");
            }
        }

        foreach ([$primary, ...$dependents] as $current) {
            self::validateTriggerArguments($name, $current);
        }

        $attributes = array_map(fn (Attribute $attribute) => $attribute->resolve((string) $previous->alias), $map->attributes());
        $resolvedMap = new self($name, $previous, $primary, $dependents, $attributes, $map->unrepresentableStates(), $tables);
        $resolvedMap->validateAttributes();
        $resolvedMap->validateLinks();
        $resolvedMap->validateUnrepresentableStates();
        $resolvedMap->validateTimeZones();
        $resolvedMap->validateIndexes();

        return $resolvedMap;
    }

    public function table(string $alias): string
    {
        return $this->tables[$alias]->table;
    }

    public function keyColumn(string $alias): string
    {
        return $this->tables[$alias]->keyColumn;
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return array_keys($this->tables);
    }

    public function primaryTable(): string
    {
        return $this->table((string) $this->primaryCurrentModel->alias);
    }

    public function dependent(string $alias): null|DependentCurrentModel
    {
        foreach ($this->dependentCurrentModels as $dependent) {
            if ($dependent->alias === $alias) {
                return $dependent;
            }
        }

        return null;
    }

    public function primaryForeignKey(DependentCurrentModel $dependent): string
    {
        return (string) $dependent->primaryForeignKey;
    }

    public function locate(DependentCurrentModel $dependent, string $primaryKey, string $previous): string
    {
        $alias = (string) $dependent->alias;
        $key = $alias.'.'.SqlFragments::identifier($this->keyColumn($alias));

        return 'FROM '.SqlFragments::identifier($this->table($alias)).' '.$alias
            .' WHERE '.$alias.'.'.SqlFragments::identifier($this->primaryForeignKey($dependent)).' = '.$primaryKey
            .($dependent->designationColumn === null ? '' : ' AND '.$key.' = '.$previous.'.'.SqlFragments::identifier($dependent->designationColumn))
            .' AND ('.$dependent->readableWhen.')'
            .' ORDER BY '.$key.' LIMIT 1';
    }

    /** @return list<DependentCurrentModel> */
    public function designated(): array
    {
        return array_values(array_filter($this->dependentCurrentModels, fn (DependentCurrentModel $dependent) => $dependent->designationColumn !== null));
    }

    /**
     * @return list<Assignment>
     */
    public function assignmentsTo(string $alias): array
    {
        $assignments = [];

        foreach ($this->attributes as $attribute) {
            foreach ($attribute->writesTo as $targetColumn) {
                if ($targetColumn->alias === $alias) {
                    $assignments[] = new Assignment($attribute, $targetColumn);
                }
            }
        }

        return $assignments;
    }

    public function writes(string $alias, string $column): bool
    {
        return array_any($this->assignmentsTo($alias), fn (Assignment $assignment) => $assignment->targetColumn->column === $column);
    }

    /** @return list<Attribute> */
    public function readOnlyAttributes(): array
    {
        return array_values(array_filter($this->attributes, fn (Attribute $attribute) => $attribute->readOnlyBecause !== null));
    }

    /** @return list<UnrepresentableState> */
    public function unrepresentableStates(UnrepresentableStateKind $kind): array
    {
        return array_values(array_filter($this->unrepresentableStates, fn (UnrepresentableState $state) => $state->kind === $kind));
    }

    /**
     * @return array<class-string<Model>, string>
     */
    public function currentModels(): array
    {
        $models = [];

        foreach ([$this->primaryCurrentModel, ...$this->dependentCurrentModels] as $current) {
            $models[$current->model] = (string) $current->alias;
        }

        return $models;
    }

    /**
     * @return array<string, Closure>
     */
    public function triggerArguments(string $model, string $trigger): array
    {
        foreach ([$this->primaryCurrentModel, ...$this->dependentCurrentModels] as $current) {
            if ($current->model === $model) {
                return $current->triggerArguments[$trigger] ?? [];
            }
        }

        return [];
    }

    public function functionName(TriggerFunction $function): string
    {
        return $this->object($function->value);
    }

    public function refuseFunctionName(UnrepresentableState $state): string
    {
        return $this->object('refuse_'.$state->name);
    }

    public function designateFunctionName(DependentCurrentModel $dependent): string
    {
        return $this->object('designate_'.$dependent->alias);
    }

    public function statisticsName(): string
    {
        return $this->object('statistics');
    }

    public function indexName(PlannerIndex $index): string
    {
        return $this->object('index_'.$index->name, 'index');
    }

    /** @return list<string> */
    public function statisticsExpressions(): array
    {
        $expressions = [];

        foreach ($this->attributes as $attribute) {
            if ($attribute->timeZone !== null && $attribute->alias === $this->primaryCurrentModel->alias) {
                $expressions[] = (string) $attribute->plannerExpression();
            }
        }

        $expressions = array_values(array_unique($expressions));
        sort($expressions, SORT_STRING);

        return $expressions;
    }

    /** @return list<string> */
    public function indexColumns(PlannerIndex $index): array
    {
        $columns = [];

        foreach ($index->columns as $column) {
            $columns[] = $this->plannerExpression($column['attribute']).($column['descending'] ? ' DESC' : '');
        }

        return $columns;
    }

    public function indexFilter(PlannerIndex $index): null|string
    {
        $filter = $index->filters[0] ?? null;

        return $filter === null ? null : $this->plannerExpression($filter['attribute']).' '.$filter['filter']->predicate();
    }

    public function objectPrefix(): string
    {
        return 'compatibility__'.$this->name.'__';
    }

    private function object(string $suffix, string $shorten = 'unrepresentable state'): string
    {
        $object = $this->objectPrefix().$suffix;

        if (strlen($object) > 63) {
            throw InvalidCompatibilityMap::because($this->name, "the database object name {$object} exceeds 63 characters; shorten the compatibility map or {$shorten} name");
        }

        return $object;
    }

    private function plannerExpression(string $column): null|string
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->column === $column && $attribute->alias === $this->primaryCurrentModel->alias) {
                return $attribute->plannerExpression();
            }
        }

        return null;
    }

    private function validateAttributes(): void
    {
        $columns = [];
        $targets = [];

        foreach ($this->attributes as $attribute) {
            if (preg_match(self::IDENTIFIER, $attribute->column) !== 1) {
                throw InvalidCompatibilityMap::because($this->name, "attribute '{$attribute->column}' must be a lowercase identifier");
            }

            if (array_key_exists($attribute->column, $columns)) {
                throw InvalidCompatibilityMap::because($this->name, "attribute '{$attribute->column}' is declared twice");
            }

            $columns[$attribute->column] = true;

            if (! $attribute->retained && $attribute->type === null) {
                throw InvalidCompatibilityMap::because($this->name, "mapped attribute '{$attribute->column}' says no asType()");
            }

            if ($attribute->alias === null || $attribute->readAs === null) {
                throw InvalidCompatibilityMap::because($this->name, "mapped attribute '{$attribute->column}' says neither storedAs() nor readAs()");
            }

            if (! array_key_exists($attribute->alias, $this->tables)) {
                throw InvalidCompatibilityMap::because($this->name, "attribute '{$attribute->column}' reads unknown alias '{$attribute->alias}'");
            }

            if ($attribute->writesTo === [] && $attribute->readOnlyBecause === null) {
                throw InvalidCompatibilityMap::because($this->name, "attribute '{$attribute->column}' declares no write and is not read-only, so a write through the previous model would be lost");
            }

            foreach ($attribute->writesTo as $targetColumn) {
                if (! array_key_exists($targetColumn->alias, $this->tables)) {
                    throw InvalidCompatibilityMap::because($this->name, "attribute '{$attribute->column}' writes unknown alias '{$targetColumn->alias}'");
                }

                $target = $targetColumn->alias.'.'.$targetColumn->column;

                if (array_key_exists($target, $targets)) {
                    throw InvalidCompatibilityMap::because($this->name, "attributes '{$targets[$target]}' and '{$attribute->column}' both write {$target}; each stored column has one owner");
                }

                $targets[$target] = $attribute->column;
            }
        }

        $readOnlyTargets = [];

        foreach ($this->readOnlyAttributes() as $attribute) {
            if ($attribute->storedAs !== null) {
                $readOnlyTargets[$attribute->alias.'.'.$attribute->storedAs][] = $attribute->column;
            }
        }

        foreach ($readOnlyTargets as $target => $readOnly) {
            if (count($readOnly) > 1 && ! array_key_exists($target, $targets)) {
                throw InvalidCompatibilityMap::because($this->name, "read-only attributes '".implode("', '", $readOnly)."' are stored in {$target}, which no writable attribute writes, so nothing says which value an insert through {$this->previousModel->view} stores");
            }
        }

        if (! array_key_exists((string) $this->previousModel->keyColumn, $columns)) {
            throw InvalidCompatibilityMap::because($this->name, "the previous model's key '{$this->previousModel->keyColumn}' is not an attribute");
        }

        if (! array_key_exists((string) $this->previousModel->primaryForeignKey, $columns)) {
            throw InvalidCompatibilityMap::because($this->name, "the primary foreign key '{$this->previousModel->primaryForeignKey}' is not an attribute");
        }
    }

    private function validateLinks(): void
    {
        foreach ($this->dependentCurrentModels as $dependent) {
            $link = $dependent->alias.'.'.$dependent->primaryForeignKey;

            foreach ($this->attributes as $attribute) {
                $stored = array_map(fn (TargetColumn $targetColumn) => $targetColumn->alias.'.'.$targetColumn->column, $attribute->writesTo);

                if (in_array($link, [...$stored, $attribute->alias.'.'.$attribute->storedAs], true)) {
                    throw InvalidCompatibilityMap::because($this->name, "attribute '{$attribute->column}' stores {$link}, the primary foreign key of dependent current model '{$dependent->alias}'; the compatibility map maintains it");
                }
            }

            if (array_key_exists((string) $dependent->primaryForeignKey, $dependent->fillWhenCreatedFromPrevious)) {
                throw InvalidCompatibilityMap::because($this->name, "dependent current model '{$dependent->alias}' fills its primary foreign key {$dependent->primaryForeignKey} with fillWhenCreatedFromPrevious(); the compatibility map maintains it");
            }
        }

        $maintained = [(string) $this->previousModel->primaryForeignKey => 'the primary foreign key'];

        foreach ($this->designated() as $dependent) {
            $maintained[(string) $dependent->designationColumn] = 'the designation column';
        }

        foreach ($maintained as $column => $role) {
            if (array_key_exists($column, $this->previousModel->fillWhenCreatedFromCurrent)) {
                throw InvalidCompatibilityMap::because($this->name, "the previous model fills {$role} {$column} with fillWhenCreatedFromCurrent(); the compatibility map maintains it");
            }
        }
    }

    private function validateUnrepresentableStates(): void
    {
        $names = [];

        foreach ($this->unrepresentableStates as $state) {
            if (preg_match(self::IDENTIFIER, $state->name) !== 1 || array_key_exists($state->name, $names)) {
                throw InvalidCompatibilityMap::because($this->name, "unrepresentable state '{$state->name}' needs a unique lowercase identifier");
            }

            $names[$state->name] = true;

            if ($state->kind === UnrepresentableStateKind::Refuse && $state->checkCondition === null && $state->previousUpdateCondition === null) {
                throw InvalidCompatibilityMap::because($this->name, "refused state '{$state->name}' says neither checkedOn() nor checkedOnPreviousUpdate()");
            }

            if ($state->checkedOn !== null && ! array_key_exists($state->checkedOn, $this->tables)) {
                throw InvalidCompatibilityMap::because($this->name, "unrepresentable state '{$state->name}' checks unknown alias '{$state->checkedOn}'");
            }
        }
    }

    private function validateTimeZones(): void
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->timeZone === null) {
                continue;
            }

            if ($attribute->retained || $attribute->storedAs === null) {
                throw InvalidCompatibilityMap::because($this->name, "attribute '{$attribute->column}' says inTimeZone() without storedAs(); only a mapped attribute stored in one column converts its time zone");
            }

            if (preg_match(self::TIMESTAMP_WITHOUT_TIME_ZONE, (string) $attribute->type) !== 1) {
                throw InvalidCompatibilityMap::because($this->name, "attribute '{$attribute->column}' says inTimeZone(), which needs asType() of a timestamp without time zone, not '{$attribute->type}'");
            }

            if ($attribute->alias !== $this->primaryCurrentModel->alias) {
                $owner = $this->dependent((string) $attribute->alias) === null ? "the retained table {$this->previousModel->table}" : "dependent current model '{$attribute->alias}'";

                throw InvalidCompatibilityMap::because($this->name, "attribute '{$attribute->column}' says inTimeZone() but is stored on {$owner}; only a column of primary current model '{$this->primaryCurrentModel->alias}' converts its time zone, because the statistics object covers that table alone");
            }
        }

        $expressions = count($this->statisticsExpressions());

        if ($expressions > self::MOST_STATISTICS_EXPRESSIONS) {
            throw InvalidCompatibilityMap::because($this->name, "inTimeZone() gives primary current model '{$this->primaryCurrentModel->alias}' {$expressions} distinct statistics expressions, and a PostgreSQL statistics object holds at most ".self::MOST_STATISTICS_EXPRESSIONS.'; convert at most '.self::MOST_STATISTICS_EXPRESSIONS.' of its columns');
        }
    }

    private function validateIndexes(): void
    {
        $names = [];

        foreach ($this->primaryCurrentModel->indexes as $index) {
            if (preg_match(self::FULL_WORDS, $index->name) !== 1) {
                throw InvalidCompatibilityMap::because($this->name, "index '{$index->name}' must be lowercase words of at least three letters, joined by single underscores");
            }

            if (array_key_exists($index->name, $names)) {
                throw InvalidCompatibilityMap::because($this->name, "index '{$index->name}' is declared twice");
            }

            $names[$index->name] = true;

            if ($index->columns === []) {
                throw InvalidCompatibilityMap::because($this->name, "index '{$index->name}' says no on()");
            }

            $columns = [];

            foreach ($index->columns as $column) {
                if (array_key_exists($column['attribute'], $columns)) {
                    throw InvalidCompatibilityMap::because($this->name, "index '{$index->name}' says on('{$column['attribute']}') twice; an index lists each column once, with one direction");
                }

                $columns[$column['attribute']] = true;
                $this->validateIndexed($index, 'is on', $column['attribute']);
            }

            if (count($index->filters) > 1) {
                $filters = array_map(fn (array $filter) => "{$filter['filter']->value}('{$filter['attribute']}')", $index->filters);

                throw InvalidCompatibilityMap::because($this->name, "index '{$index->name}' says ".implode(' and ', $filters).'; an index takes one filter at most, so declare one whereNull() or whereNotNull()');
            }

            foreach ($index->filters as $filter) {
                $this->validateIndexed($index, 'filters on', $filter['attribute']);
            }

            $this->indexName($index);
        }
    }

    private function validateIndexed(PlannerIndex $index, string $role, string $attribute): void
    {
        if ($this->plannerExpression($attribute) === null) {
            throw InvalidCompatibilityMap::because($this->name, "index '{$index->name}' {$role} '{$attribute}', which is not a mapped attribute that storedAs() stores on primary current model '{$this->primaryCurrentModel->alias}'");
        }
    }

    private static function validateTriggerArguments(string $map, PrimaryCurrentModel|DependentCurrentModel $current): void
    {
        if ($current->triggerArguments === []) {
            return;
        }

        $triggers = EventReplayer::triggers(self::model($map, $current->model));

        if ($triggers === null) {
            throw InvalidCompatibilityMap::because($map, "current model '{$current->alias}' declares triggerArguments(), which needs aryeo/eloquent-state-machines with Trigger::withoutTransition()");
        }

        foreach ($current->triggerArguments as $trigger => $arguments) {
            if (! in_array($trigger, $triggers, true)) {
                throw InvalidCompatibilityMap::because($map, "{$trigger} is not the trigger of a #[Transition] on a state machine that current model '{$current->alias}' casts a column to");
            }

            $parameters = method_exists($trigger, '__construct') ? (new ReflectionMethod($trigger, '__construct'))->getParameters() : [];
            $names = array_map(fn (ReflectionParameter $parameter) => strtolower($parameter->name), $parameters);
            $supplied = array_map(fn (int|string $argument) => strtolower((string) $argument), array_keys($arguments));

            foreach (array_diff_assoc($names, array_unique($names)) as $index => $name) {
                $first = $parameters[(int) array_search($name, $names, true)]->name;

                throw InvalidCompatibilityMap::because($map, "{$trigger} takes constructor parameters '{$first}' and '{$parameters[$index]->name}', whose names differ only in case, so triggerArguments() of current model '{$current->alias}' cannot tell them apart");
            }

            foreach (array_diff_assoc($supplied, array_unique($supplied)) as $argument) {
                throw InvalidCompatibilityMap::because($map, "triggerArguments() of current model '{$current->alias}' passes '{$argument}' more than once for {$trigger}, in names that differ only in case");
            }

            foreach ($supplied as $argument) {
                if (! in_array($argument, $names, true)) {
                    throw InvalidCompatibilityMap::because($map, "triggerArguments() of current model '{$current->alias}' passes '{$argument}', which is not a constructor parameter of {$trigger}");
                }
            }

            foreach ($parameters as $parameter) {
                if ($parameter->isVariadic() && in_array(strtolower($parameter->name), $supplied, true)) {
                    throw InvalidCompatibilityMap::because($map, "triggerArguments() of current model '{$current->alias}' passes '{$parameter->name}', a variadic constructor parameter of {$trigger}, which would take the value as a single element");
                }

                if (! in_array(strtolower($parameter->name), $supplied, true) && self::unresolvable($parameter)) {
                    throw InvalidCompatibilityMap::because($map, "{$trigger} requires constructor parameter '{$parameter->name}', which the container cannot resolve, and triggerArguments() of current model '{$current->alias}' does not pass it");
                }
            }
        }
    }

    private static function unresolvable(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        $enum = $type instanceof ReflectionNamedType && enum_exists($type->getName());

        return EventReplayer::buildsEmptyModel($parameter) || (! $parameter->isDefaultValueAvailable()
            && ! $parameter->isVariadic()
            && ($enum || (! ($type !== null && $type->allowsNull()) && ! ($type instanceof ReflectionNamedType && ! $type->isBuiltin())))
            && $parameter->getAttributes(ContextualAttribute::class, ReflectionAttribute::IS_INSTANCEOF) === []);
    }

    private static function previous(string $map, PreviousModel $previous): PreviousModel
    {
        foreach (['retainedInTable' => $previous->table, 'forModel' => $previous->model, 'aliasedAs' => $previous->alias, 'primaryForeignKey' => $previous->primaryForeignKey] as $call => $value) {
            if ($value === null) {
                throw InvalidCompatibilityMap::because($map, "the previous model says no {$call}()");
            }
        }

        $model = self::model($map, (string) $previous->model);

        return $previous->keyColumn === null ? $previous->keyedBy($model->getKeyName()) : $previous;
    }

    private static function model(string $map, string $class): Model
    {
        if (! is_subclass_of($class, Model::class)) {
            throw InvalidCompatibilityMap::because($map, "{$class} is not an Eloquent model");
        }

        return new $class;
    }
}
