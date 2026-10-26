<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\TriggerFunction;

final readonly class ResolvedMap
{
    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

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
        }

        $attributes = array_map(fn (Attribute $attribute) => $attribute->resolve((string) $previous->alias), $map->attributes());
        $resolvedMap = new self($name, $previous, $primary, $dependents, $attributes, $map->unrepresentableStates(), $tables);
        $resolvedMap->validateAttributes();
        $resolvedMap->validateUnrepresentableStates();

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

    public function objectPrefix(): string
    {
        return 'compatibility__'.$this->name.'__';
    }

    private function object(string $suffix): string
    {
        $object = $this->objectPrefix().$suffix;

        if (strlen($object) > 63) {
            throw InvalidCompatibilityMap::because($this->name, "the database object name {$object} exceeds 63 characters; shorten the compatibility map or unrepresentable state name");
        }

        return $object;
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

        if (! array_key_exists((string) $this->previousModel->keyColumn, $columns)) {
            throw InvalidCompatibilityMap::because($this->name, "the previous model's key '{$this->previousModel->keyColumn}' is not an attribute");
        }

        if (! array_key_exists((string) $this->previousModel->primaryForeignKey, $columns)) {
            throw InvalidCompatibilityMap::because($this->name, "the primary foreign key '{$this->previousModel->primaryForeignKey}' is not an attribute");
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
