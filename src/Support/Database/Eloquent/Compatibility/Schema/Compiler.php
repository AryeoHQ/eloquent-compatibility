<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Assignment;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Mapping\TargetColumn;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableStateKind;

final class Compiler
{
    private readonly ColumnCatalog $columnCatalog;

    private readonly string $schema;

    public function __construct(ConnectionInterface $connection)
    {
        $this->columnCatalog = new ColumnCatalog($connection);
        $this->schema = (string) $connection->scalar('select current_schema()');
    }

    public function compile(CompatibilityMap|ResolvedMap $map): CompiledSql
    {
        $resolvedMap = $map instanceof ResolvedMap ? $map : ResolvedMap::of($map);
        $this->validateColumns($resolvedMap);

        $functions = [
            $this->insertFunction($resolvedMap),
            $this->updateFunction($resolvedMap),
            $this->deleteFunction($resolvedMap),
            $this->primaryInsertFunction($resolvedMap),
            $this->primaryDeleteFunction($resolvedMap),
        ];

        foreach ($resolvedMap->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->alias !== null) {
                $functions[] = $this->refuseFunction($resolvedMap, $state);
            }
        }

        foreach ($resolvedMap->designated() as $dependent) {
            $functions[] = $this->designateFunction($resolvedMap, $dependent);
        }

        return new CompiledSql(
            [$this->view($resolvedMap), ...$this->defaults($resolvedMap)],
            $functions,
            $this->triggers($resolvedMap),
        );
    }

    private function view(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $primary = (string) $resolvedMap->primaryCurrentModel->alias;
        $columns = array_map(
            fn (Attribute $attribute) => $this->read($resolvedMap, $attribute).' AS '.SqlFragments::id($attribute->column),
            $resolvedMap->attributes,
        );
        $hidden = array_map(
            fn (UnrepresentableState $state) => 'NOT COALESCE(('.$state->condition.'), false)',
            $resolvedMap->unrepresentableStates(UnrepresentableStateKind::Hide),
        );

        return 'CREATE OR REPLACE VIEW '.SqlFragments::id($previous->view)." AS SELECT\n  ".implode(",\n  ", $columns)
            ."\nFROM ".SqlFragments::id((string) $previous->table).' '.$previous->alias
            ."\nJOIN ".SqlFragments::id($resolvedMap->table($primary)).' '.$primary
            .' ON '.$primary.'.'.SqlFragments::id($resolvedMap->key($primary)).' = '.$previous->alias.'.'.SqlFragments::id((string) $previous->primaryForeignKey)
            .($hidden === [] ? '' : "\nWHERE ".implode("\n  AND ", $hidden));
    }

    private function read(ResolvedMap $resolvedMap, Attribute $attribute): string
    {
        if ($attribute->retained) {
            return (string) $attribute->readAs;
        }

        $dependent = $resolvedMap->dependent((string) $attribute->alias);
        $primary = (string) $resolvedMap->primaryCurrentModel->alias;
        $expression = $dependent === null
            ? '('.$attribute->readAs.')'
            : '(SELECT '.$attribute->readAs.' '.$resolvedMap->locate($dependent, $primary.'.'.SqlFragments::id($resolvedMap->key($primary)), (string) $resolvedMap->previousModel->alias).')';

        return 'CAST('.$expression.' AS '.$attribute->type.')';
    }

    /** @return list<string> */
    private function defaults(ResolvedMap $resolvedMap): array
    {
        $statements = [];

        foreach ($resolvedMap->attributes as $attribute) {
            $default = $attribute->defaultsTo;

            if ($default === null && $attribute->storedAs !== null && $attribute->alias !== null && $resolvedMap->dependent($attribute->alias) === null) {
                $default = $this->columnCatalog->of($resolvedMap->table($attribute->alias))[$attribute->storedAs]->default ?? null;
            }

            $statements[] = 'ALTER VIEW '.SqlFragments::id($resolvedMap->previousModel->view)
                .' ALTER COLUMN '.SqlFragments::id($attribute->column).($default === null ? ' DROP DEFAULT' : ' SET DEFAULT '.$default);
        }

        return $statements;
    }

    private function insertFunction(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $previousAlias = (string) $previous->alias;
        $primary = $resolvedMap->primaryCurrentModel;
        $primaryAlias = (string) $primary->alias;
        $primaryKey = 'key_'.$primaryAlias;
        $previousColumns = [];

        foreach ($resolvedMap->attributes as $attribute) {
            if ($attribute->retained) {
                $previousColumns[$attribute->column] = $this->value($resolvedMap, $previousAlias, new TargetColumn($previousAlias, $attribute->column, 'NEW.'.SqlFragments::id($attribute->column)));
            }
        }

        $previousColumns = [
            ...$previousColumns,
            ...$this->insertValues($resolvedMap, $previousAlias, [], true),
            (string) $previous->primaryForeignKey => $primaryKey,
        ];

        $body = [
            ...$this->notNullChecks($resolvedMap, true),
            $this->applying($resolvedMap),
            '  '.$this->insert($resolvedMap->table($primaryAlias), $this->insertValues($resolvedMap, $primaryAlias, $primary->fillWhenCreatedFromPrevious), $resolvedMap->key($primaryAlias), $primaryKey),
            '  '.$this->insert((string) $previous->table, $previousColumns, '*', 'previous_row'),
            '  NEW.'.SqlFragments::id((string) $previous->key).' := previous_row.'.SqlFragments::id((string) $previous->key).';',
        ];

        foreach ($resolvedMap->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;

            if ($resolvedMap->assignmentsTo($alias) === [] && $dependent->fillWhenCreatedFromPrevious === []) {
                continue;
            }

            $body[] = '  IF ('.$this->createdWhen($resolvedMap, $dependent).") THEN\n    "
                .$this->insert($resolvedMap->table($alias), $this->dependentValues($resolvedMap, $dependent, $primaryKey), $resolvedMap->key($alias), 'key_'.$alias)
                .$this->designate($resolvedMap, $dependent, 'previous_row.'.SqlFragments::id((string) $previous->key), "\n    ")
                ."\n  END IF;";
        }

        $body[] = $this->applying($resolvedMap, false);
        $body[] = '  PERFORM set_config('.SqlFragments::literal(Setting::Created->for($resolvedMap)).', previous_row.'.SqlFragments::id((string) $previous->key).'::text, true);';
        $body[] = '  RETURN NEW;';

        return $this->function($resolvedMap->function(TriggerFunction::Insert), $this->variables($resolvedMap), $body);
    }

    private function updateFunction(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $primaryKey = 'key_'.$resolvedMap->primaryCurrentModel->alias;
        $record = ' || OLD.'.SqlFragments::id((string) $previous->key).'::text';
        $body = [];

        foreach ($resolvedMap->readOnlyAttributes() as $attribute) {
            $body[] = '  IF NEW.'.SqlFragments::id($attribute->column).' IS DISTINCT FROM OLD.'.SqlFragments::id($attribute->column)." THEN\n    "
                .SqlFragments::refuse(
                    SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused a change to {$previous->view}.{$attribute->column} on record ").$record,
                    (string) $attribute->readOnlyBecause,
                    "Leave {$attribute->column} unchanged in writes through {$previous->view}.",
                )."\n  END IF;";
        }

        array_push($body, ...$this->notNullChecks($resolvedMap, false));
        $body[] = $this->lockPrimary($resolvedMap);
        $body[] = $this->loadPrevious($resolvedMap);
        $body[] = '  '.$primaryKey.' := previous_row.'.SqlFragments::id((string) $previous->primaryForeignKey).';';

        foreach ($resolvedMap->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->previousUpdate !== null) {
                $body[] = '  IF COALESCE(('.$state->previousUpdate.'), false) THEN'."\n    "
                    .SqlFragments::refuse(
                        SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused {$state->state} on {$previous->view} record ").$record,
                        $state->because,
                        $state->instead,
                    )."\n  END IF;";
            }
        }

        $body[] = $this->conflict($resolvedMap);
        $body[] = $this->applying($resolvedMap);
        $body[] = $this->updateTable($resolvedMap, (string) $resolvedMap->primaryCurrentModel->alias, $primaryKey, true);
        $body[] = $this->updateTable($resolvedMap, (string) $previous->alias, 'OLD.'.SqlFragments::id((string) $previous->key), false);

        foreach ($resolvedMap->dependentCurrentModels as $dependent) {
            $body[] = $this->updateDependent($resolvedMap, $dependent, $primaryKey);
        }

        $body[] = $this->applying($resolvedMap, false);
        $body[] = '  RETURN NEW;';

        return $this->function($resolvedMap->function(TriggerFunction::Update), $this->variables($resolvedMap), array_values(array_filter($body, fn (string $line) => $line !== '')));
    }

    private function deleteFunction(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $primaryAlias = (string) $resolvedMap->primaryCurrentModel->alias;
        $primaryKey = 'previous_row.'.SqlFragments::id((string) $previous->primaryForeignKey);
        $body = [
            $this->lockPrimary($resolvedMap),
            $this->loadPrevious($resolvedMap),
            $this->applying($resolvedMap),
            '  DELETE FROM '.SqlFragments::id((string) $previous->table).' WHERE '.SqlFragments::id((string) $previous->key).' = OLD.'.SqlFragments::id((string) $previous->key).';',
        ];

        foreach (array_reverse($resolvedMap->dependentCurrentModels) as $dependent) {
            $body[] = '  DELETE FROM '.SqlFragments::id($resolvedMap->table((string) $dependent->alias))
                .' WHERE '.SqlFragments::id($resolvedMap->primaryForeignKey($dependent)).' = '.$primaryKey.';';
        }

        $body[] = '  DELETE FROM '.SqlFragments::id($resolvedMap->table($primaryAlias))
            .' WHERE '.SqlFragments::id($resolvedMap->key($primaryAlias)).' = '.$primaryKey.';';
        $body[] = $this->applying($resolvedMap, false);
        $body[] = '  RETURN OLD;';

        return $this->function($resolvedMap->function(TriggerFunction::Delete), $this->variables($resolvedMap), $body);
    }

    private function primaryInsertFunction(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $previousAlias = (string) $previous->alias;
        $values = [(string) $previous->primaryForeignKey => 'NEW.'.SqlFragments::id($resolvedMap->key((string) $resolvedMap->primaryCurrentModel->alias))];

        foreach ($previous->fillWhenCreatedFromCurrent as $column => $expression) {
            $values[$column] = $this->value($resolvedMap, $previousAlias, new TargetColumn($previousAlias, $column, $expression));
        }

        return $this->function($resolvedMap->function(TriggerFunction::PrimaryInsert), '  created_key '.SqlFragments::id((string) $previous->table).'.'.SqlFragments::id((string) $previous->key)."%TYPE;\n", [
            $this->skipWhileApplying($resolvedMap, 'NEW'),
            '  '.$this->insert((string) $previous->table, $values, (string) $previous->key, 'created_key'),
            '  PERFORM set_config('.SqlFragments::literal(Setting::Created->for($resolvedMap)).', created_key::text, true);',
            '  RETURN NEW;',
        ]);
    }

    private function designateFunction(ResolvedMap $resolvedMap, DependentCurrentModel $dependent): string
    {
        $alias = (string) $dependent->alias;
        $previous = $resolvedMap->previousModel;
        $designation = SqlFragments::id((string) $dependent->designation);

        return $this->function($resolvedMap->designateFunction($dependent), '', [
            $this->skipWhileApplying($resolvedMap, 'NEW'),
            '  IF EXISTS (SELECT 1 FROM (SELECT NEW.*) AS '.$alias.' WHERE COALESCE(('.$dependent->readableWhen.'), false)) THEN'."\n    "
                .'UPDATE '.SqlFragments::id((string) $previous->table).' SET '.$designation.' = NEW.'.SqlFragments::id($resolvedMap->key($alias))
                .' WHERE '.SqlFragments::id((string) $previous->primaryForeignKey).' = NEW.'.SqlFragments::id($resolvedMap->primaryForeignKey($dependent)).' AND '.$designation." IS NULL;\n  END IF;",
            '  RETURN NEW;',
        ]);
    }

    private function primaryDeleteFunction(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;

        return $this->function($resolvedMap->function(TriggerFunction::PrimaryDelete), '', [
            $this->skipWhileApplying($resolvedMap, 'OLD'),
            '  DELETE FROM '.SqlFragments::id((string) $previous->table)
                .' WHERE '.SqlFragments::id((string) $previous->primaryForeignKey).' = OLD.'.SqlFragments::id($resolvedMap->key((string) $resolvedMap->primaryCurrentModel->alias)).';',
            '  RETURN OLD;',
        ]);
    }

    private function refuseFunction(ResolvedMap $resolvedMap, UnrepresentableState $state): string
    {
        $alias = (string) $state->alias;
        $dependent = $resolvedMap->dependent($alias);
        $primaryForeignKey = match (true) {
            $dependent !== null => $resolvedMap->primaryForeignKey($dependent),
            $alias === $resolvedMap->previousModel->alias => (string) $resolvedMap->previousModel->primaryForeignKey,
            default => null,
        };
        $serialize = $primaryForeignKey === null ? [] : [
            '  PERFORM pg_advisory_xact_lock(hashtextextended(concat('.SqlFragments::literal($resolvedMap->refuseFunction($state).':')
                .', NEW.'.SqlFragments::id($primaryForeignKey).'), 0));',
        ];

        return $this->function($resolvedMap->refuseFunction($state), '', [
            ...$serialize,
            '  IF EXISTS (SELECT 1 FROM (SELECT NEW.*) AS '.$alias.' WHERE COALESCE(('.$state->condition.'), false)) THEN'."\n    "
                .SqlFragments::refuse(
                    SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused {$state->state} in ".$resolvedMap->table($alias).' record ')
                        .' || NEW.'.SqlFragments::id($resolvedMap->key($alias)).'::text',
                    $state->because,
                    $state->instead,
                )."\n  END IF;",
            '  RETURN NEW;',
        ]);
    }

    /** @return list<string> */
    private function triggers(ResolvedMap $resolvedMap): array
    {
        $view = SqlFragments::id($resolvedMap->previousModel->view);
        $primary = SqlFragments::id($resolvedMap->table((string) $resolvedMap->primaryCurrentModel->alias));
        $trigger = fn (string $function, string $when) => 'CREATE TRIGGER '.SqlFragments::id($function).' '.$when
            .' FOR EACH ROW EXECUTE FUNCTION '.SqlFragments::id($function).'()';

        $triggers = [
            $trigger($resolvedMap->function(TriggerFunction::Insert), 'INSTEAD OF INSERT ON '.$view),
            $trigger($resolvedMap->function(TriggerFunction::Update), 'INSTEAD OF UPDATE ON '.$view),
            $trigger($resolvedMap->function(TriggerFunction::Delete), 'INSTEAD OF DELETE ON '.$view),
            $trigger($resolvedMap->function(TriggerFunction::PrimaryInsert), 'AFTER INSERT ON '.$primary),
            $trigger($resolvedMap->function(TriggerFunction::PrimaryDelete), 'BEFORE DELETE ON '.$primary),
        ];

        foreach ($resolvedMap->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->alias !== null) {
                $triggers[] = $trigger($resolvedMap->refuseFunction($state), 'BEFORE INSERT OR UPDATE ON '.SqlFragments::id($resolvedMap->table($state->alias)));
            }
        }

        foreach ($resolvedMap->designated() as $dependent) {
            $triggers[] = $trigger($resolvedMap->designateFunction($dependent), 'AFTER INSERT ON '.SqlFragments::id($resolvedMap->table((string) $dependent->alias)));
        }

        return $triggers;
    }

    private function updateTable(ResolvedMap $resolvedMap, string $alias, string $key, bool $mustExist): string
    {
        $assignments = $resolvedMap->assignmentsTo($alias);

        if ($assignments === []) {
            return '';
        }

        $statement = '  IF '.SqlFragments::changed($this->attributeColumns($assignments))." THEN\n    "
            .$this->update($resolvedMap, $alias, $assignments, $key);

        if ($mustExist) {
            $statement .= "\n    GET DIAGNOSTICS affected_rows = ROW_COUNT;\n    IF affected_rows <> 1 THEN\n      "
                .$this->missing($resolvedMap, $resolvedMap->table($alias))."\n    END IF;";
        }

        return $statement."\n  END IF;";
    }

    private function updateDependent(ResolvedMap $resolvedMap, DependentCurrentModel $dependent, string $primaryKey): string
    {
        $alias = (string) $dependent->alias;
        $assignments = $resolvedMap->assignmentsTo($alias);

        if ($assignments === []) {
            return '';
        }

        $columns = $this->attributeColumns($assignments);
        $previous = $resolvedMap->previousModel;
        $table = $resolvedMap->table($alias);
        $refused = SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused a change to ".implode(', ', $columns)." on {$previous->view} record ")
            .' || OLD.'.SqlFragments::id((string) $previous->key).'::text';
        $cases = [];

        if ($dependent->designation !== null) {
            $designation = $previous->table.'.'.$dependent->designation;
            $cases['EXISTS (SELECT 1 FROM '.SqlFragments::id($table).' WHERE '.SqlFragments::id($resolvedMap->key($alias)).' = previous_row.'.SqlFragments::id($dependent->designation).')'] = SqlFragments::refuse(
                $refused.' || '.SqlFragments::literal(": its designated {$table} row is not one the previous model reads"),
                "The record reads only the {$table} row that {$designation} names, and that row no longer matches ({$dependent->readableWhen}) or belongs to another record.",
                "Make that {$table} row match again through its own model, or repair {$designation} by hand.",
            );
        }

        $cases['('.$this->createdWhen($resolvedMap, $dependent).')'] = $this->insert($table, $this->dependentValues($resolvedMap, $dependent, $primaryKey), $resolvedMap->key($alias), 'key_'.$alias)
            .$this->designate($resolvedMap, $dependent, 'OLD.'.SqlFragments::id((string) $previous->key), "\n        ");
        $branches = '';

        foreach ($cases as $condition => $statement) {
            $branches .= ($branches === '' ? '      IF ' : "\n      ELSIF ").$condition." THEN\n        ".$statement;
        }

        return '  IF '.SqlFragments::changed($columns)." THEN\n"
            .'    SELECT '.$alias.'.'.SqlFragments::id($resolvedMap->key($alias)).' INTO key_'.$alias.' '.$resolvedMap->locate($dependent, $primaryKey, 'previous_row').";\n"
            .'    IF key_'.$alias." IS NULL THEN\n"
            .$branches
            ."\n      ELSE\n        "
            .SqlFragments::refuse(
                $refused,
                'The record has no '.$table.' row, and the compatibility map does not create one for this write.',
                'Create the '.$table.' row through its own model first, or declare createdWhen() for this dependent current model.',
            )
            ."\n      END IF;\n    ELSE\n      "
            .$this->update($resolvedMap, $alias, $assignments, 'key_'.$alias)
            ."\n    END IF;\n  END IF;";
    }

    private function designate(ResolvedMap $resolvedMap, DependentCurrentModel $dependent, string $previousKey, string $indent): string
    {
        if ($dependent->designation === null) {
            return '';
        }

        $previous = $resolvedMap->previousModel;

        return $indent.'UPDATE '.SqlFragments::id((string) $previous->table).' SET '.SqlFragments::id($dependent->designation).' = key_'.$dependent->alias
            .' WHERE '.SqlFragments::id((string) $previous->key).' = '.$previousKey.';';
    }

    /**
     * @param  list<Assignment>  $assignments
     */
    private function update(ResolvedMap $resolvedMap, string $alias, array $assignments, string $key): string
    {
        $sets = array_map(
            fn (Assignment $assignment) => SqlFragments::id($assignment->targetColumn->column).' = CASE WHEN NEW.'.SqlFragments::id($assignment->attribute->column)
                .' IS DISTINCT FROM OLD.'.SqlFragments::id($assignment->attribute->column)
                .' THEN '.$this->value($resolvedMap, $alias, $assignment->targetColumn)
                .' ELSE '.$alias.'.'.SqlFragments::id($assignment->targetColumn->column).' END',
            $assignments,
        );

        return 'UPDATE '.SqlFragments::id($resolvedMap->table($alias)).' AS '.$alias.' SET '.implode(', ', $sets)
            .' WHERE '.$alias.'.'.SqlFragments::id($resolvedMap->key($alias)).' = '.$key.';';
    }

    /**
     * @param  array<string, string>  $values
     */
    private function insert(string $table, array $values, string $returning, string $into): string
    {
        $returning = $returning === '*' ? '*' : SqlFragments::id($returning);

        if ($values === []) {
            return 'INSERT INTO '.SqlFragments::id($table).' DEFAULT VALUES RETURNING '.$returning.' INTO '.$into.';';
        }

        return 'INSERT INTO '.SqlFragments::id($table).' ('.implode(', ', array_map(SqlFragments::id(...), array_keys($values))).')'
            .' VALUES ('.implode(', ', $values).') RETURNING '.$returning.' INTO '.$into.';';
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function insertValues(ResolvedMap $resolvedMap, string $alias, array $extra, bool $mappedOnly = false): array
    {
        $values = [];

        foreach ($resolvedMap->assignmentsTo($alias) as $assignment) {
            if (! $mappedOnly || ! $assignment->attribute->retained) {
                $values[$assignment->targetColumn->column] = $this->value($resolvedMap, $alias, $assignment->targetColumn);
            }
        }

        foreach ($extra as $column => $expression) {
            if (array_key_exists($column, $values)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$alias}.{$column} is set both by an attribute and by fillWhenCreatedFromPrevious()");
            }

            $values[$column] = $this->value($resolvedMap, $alias, new TargetColumn($alias, $column, $expression));
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function dependentValues(ResolvedMap $resolvedMap, DependentCurrentModel $dependent, string $primaryKey): array
    {
        return [$resolvedMap->primaryForeignKey($dependent) => $primaryKey, ...$this->insertValues($resolvedMap, (string) $dependent->alias, $dependent->fillWhenCreatedFromPrevious)];
    }

    private function createdWhen(ResolvedMap $resolvedMap, DependentCurrentModel $dependent): string
    {
        if ($dependent->createdWhen !== null) {
            return $dependent->createdWhen;
        }

        $columns = $this->attributeColumns($resolvedMap->assignmentsTo((string) $dependent->alias));

        return $columns === []
            ? 'true'
            : implode(' OR ', array_map(fn (string $column) => 'NEW.'.SqlFragments::id($column).' IS NOT NULL', $columns));
    }

    /**
     * @param  list<Assignment>  $assignments
     * @return list<string>
     */
    private function attributeColumns(array $assignments): array
    {
        return array_values(array_unique(array_map(fn (Assignment $assignment) => $assignment->attribute->column, $assignments)));
    }

    private function value(ResolvedMap $resolvedMap, string $alias, TargetColumn $targetColumn): string
    {
        $table = $resolvedMap->table($alias);
        $type = $this->columnCatalog->type($table, $targetColumn->column);

        if ($type === null) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "{$table} has no column {$targetColumn->column}");
        }

        return 'CAST(('.$targetColumn->expression.') AS '.$type.')';
    }

    /** @return list<string> */
    private function notNullChecks(ResolvedMap $resolvedMap, bool $insert): array
    {
        $previous = $resolvedMap->previousModel;
        $record = $insert ? SqlFragments::literal('a new record') : SqlFragments::literal('record ').' || OLD.'.SqlFragments::id((string) $previous->key).'::text';
        $checks = [];

        foreach ($resolvedMap->attributes as $attribute) {
            if ($attribute->retained || ! $this->columnCatalog->notNull((string) $previous->table, $attribute->column)) {
                continue;
            }

            $column = SqlFragments::id($attribute->column);
            $checks[] = '  IF NEW.'.$column.' IS NULL'.($insert ? '' : ' AND OLD.'.$column.' IS NOT NULL')." THEN\n    "
                .SqlFragments::refuse(
                    SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused a null {$previous->view}.{$attribute->column} on ").' || '.$record,
                    "{$previous->table}.{$attribute->column} is NOT NULL, so the previous model never stores null.",
                    "Write a value to {$attribute->column}, or omit it from an insert to take its default.",
                )."\n  END IF;";
        }

        return $checks;
    }

    private function lockPrimary(ResolvedMap $resolvedMap): string
    {
        $primary = (string) $resolvedMap->primaryCurrentModel->alias;

        return '  PERFORM 1 FROM '.SqlFragments::id($resolvedMap->table($primary))
            .' WHERE '.SqlFragments::id($resolvedMap->key($primary)).' = OLD.'.SqlFragments::id((string) $resolvedMap->previousModel->primaryForeignKey).' FOR UPDATE;';
    }

    private function conflict(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $primary = (string) $resolvedMap->primaryCurrentModel->alias;
        $attributes = array_values(array_filter($resolvedMap->attributes, fn (Attribute $attribute) => $resolvedMap->dependent((string) $attribute->alias) === null));

        return '  IF EXISTS (SELECT 1 FROM '.SqlFragments::id((string) $previous->table).' '.$previous->alias
            .' JOIN '.SqlFragments::id($resolvedMap->table($primary)).' '.$primary
            .' ON '.$primary.'.'.SqlFragments::id($resolvedMap->key($primary)).' = '.$previous->alias.'.'.SqlFragments::id((string) $previous->primaryForeignKey)
            .' WHERE '.$previous->alias.'.'.SqlFragments::id((string) $previous->key).' = OLD.'.SqlFragments::id((string) $previous->key)
            .' AND ROW('.implode(', ', array_map(fn (Attribute $attribute) => $this->read($resolvedMap, $attribute), $attributes)).')'
            .' IS DISTINCT FROM ROW('.implode(', ', array_map(fn (Attribute $attribute) => 'OLD.'.SqlFragments::id($attribute->column), $attributes)).")) THEN\n    "
            .SqlFragments::raise(
                SqlState::SerializationFailure,
                SqlFragments::literal("Compatibility map '{$resolvedMap->name}' found a concurrent change to {$previous->view} record ")
                    .' || OLD.'.SqlFragments::id((string) $previous->key).'::text || '.SqlFragments::literal(" since this statement's snapshot"),
                "An INSTEAD OF trigger receives OLD from the statement's snapshot and cannot re-read the row, so writing would lose that change.",
                "Retry the transaction, or write through the {$previous->model} model: the event bridge locks the record before its statement.",
            )."\n  END IF;";
    }

    private function loadPrevious(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;

        return '  SELECT * INTO previous_row FROM '.SqlFragments::id((string) $previous->table)
            .' WHERE '.SqlFragments::id((string) $previous->key).' = OLD.'.SqlFragments::id((string) $previous->key)." FOR UPDATE;\n"
            ."  IF NOT FOUND THEN\n    ".$this->missing($resolvedMap, (string) $previous->table)."\n  END IF;";
    }

    private function missing(ResolvedMap $resolvedMap, string $table): string
    {
        $previous = $resolvedMap->previousModel;

        return SqlFragments::refuse(
            SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused a write to {$previous->view} record ")
                .' || OLD.'.SqlFragments::id((string) $previous->key).'::text || '.SqlFragments::literal(": its {$table} row is missing"),
            'The record has nowhere to store the change, so the previous and current models have diverged.',
            "Run php artisan compatibility:check --map={$resolvedMap->name} and repair the record it names.",
        );
    }

    private function applying(ResolvedMap $resolvedMap, bool $on = true): string
    {
        return '  PERFORM set_config('.SqlFragments::literal(Setting::Applying->for($resolvedMap)).', '.SqlFragments::literal($on ? $resolvedMap->name : '').', true);';
    }

    private function skipWhileApplying(ResolvedMap $resolvedMap, string $row): string
    {
        return '  IF current_setting('.SqlFragments::literal(Setting::Applying->for($resolvedMap)).', true) = '.SqlFragments::literal($resolvedMap->name)." THEN\n    RETURN {$row};\n  END IF;";
    }

    private function variables(ResolvedMap $resolvedMap): string
    {
        $lines = ['  previous_row '.SqlFragments::id((string) $resolvedMap->previousModel->table).'%ROWTYPE;', '  affected_rows integer;'];

        foreach ([$resolvedMap->primaryCurrentModel, ...$resolvedMap->dependentCurrentModels] as $current) {
            $alias = (string) $current->alias;
            $lines[] = '  key_'.$alias.' '.SqlFragments::id($resolvedMap->table($alias))
                .'.'.SqlFragments::id($resolvedMap->key($alias)).'%TYPE;';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $body
     */
    private function function(string $name, string $variables, array $body): string
    {
        return 'CREATE OR REPLACE FUNCTION '.SqlFragments::id($name).'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = '.SqlFragments::id($this->schema)
            .", pg_temp AS \$compatibility\$\n"
            .($variables === '' ? '' : "DECLARE\n".$variables)
            ."BEGIN\n".implode("\n", $body)."\nEND;\n\$compatibility\$";
    }

    private function validateColumns(ResolvedMap $resolvedMap): void
    {
        $previous = $resolvedMap->previousModel;
        $table = (string) $previous->table;
        $columns = $this->columnCatalog->of($table);

        if ($columns === []) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "the retained table {$table} does not exist");
        }

        $designations = array_map(fn (DependentCurrentModel $dependent) => (string) $dependent->designation, $resolvedMap->designated());

        foreach ([(string) $previous->key, (string) $previous->primaryForeignKey, ...$designations] as $column) {
            if (! array_key_exists($column, $columns)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$table} has no column {$column}");
            }
        }

        foreach ($resolvedMap->attributes as $attribute) {
            if ($attribute->retained && ! array_key_exists($attribute->column, $columns)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "retained attribute {$attribute->column} is not a column of {$table}");
            }

            foreach ($attribute->writesTo as $targetColumn) {
                if ($targetColumn->alias === $previous->alias && $targetColumn->column === $previous->primaryForeignKey) {
                    throw InvalidCompatibilityMap::because($resolvedMap->name, "attribute {$attribute->column} writes the primary foreign key {$targetColumn->column}; declare it read-only");
                }

                if ($targetColumn->alias === $previous->alias && in_array($targetColumn->column, $designations, true)) {
                    throw InvalidCompatibilityMap::because($resolvedMap->name, "attribute {$attribute->column} writes the designation column {$targetColumn->column}; the compatibility map maintains it");
                }
            }
        }

        foreach ($resolvedMap->dependentCurrentModels as $dependent) {
            $dependentTable = $resolvedMap->table((string) $dependent->alias);

            if ($this->columnCatalog->type($dependentTable, $resolvedMap->primaryForeignKey($dependent)) === null) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, $dependentTable.' has no column '.$resolvedMap->primaryForeignKey($dependent));
            }
        }
    }
}
