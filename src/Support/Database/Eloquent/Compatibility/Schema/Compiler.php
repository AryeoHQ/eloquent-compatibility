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
    private const string TIMESTAMP_WITH_TIME_ZONE = '/^timestamp(?:\(\d+\))? with time zone$/';

    private readonly ColumnCatalog $columnCatalog;

    private readonly string $schema;

    /** @var array<string, bool> */
    private array $timeZones = [];

    /**
     * @param  array<string, string>  $sources
     */
    public function __construct(private readonly ConnectionInterface $connection, array $sources = [])
    {
        $this->schema = (string) $connection->scalar('select current_schema()');
        $this->columnCatalog = new ColumnCatalog($connection, $this->searchPath(), $sources);
    }

    public function compile(CompatibilityMap|ResolvedMap $map): CompiledSql
    {
        $resolvedMap = $map instanceof ResolvedMap ? $map : ResolvedMap::of($map);
        $this->validateColumns($resolvedMap);

        $functions = [
            $resolvedMap->functionName(TriggerFunction::Insert) => $this->insertFunction($resolvedMap),
            $resolvedMap->functionName(TriggerFunction::Update) => $this->updateFunction($resolvedMap),
            $resolvedMap->functionName(TriggerFunction::Delete) => $this->deleteFunction($resolvedMap),
            $resolvedMap->functionName(TriggerFunction::PrimaryInsert) => $this->primaryInsertFunction($resolvedMap),
            $resolvedMap->functionName(TriggerFunction::PrimaryDelete) => $this->primaryDeleteFunction($resolvedMap),
        ];

        foreach ($resolvedMap->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->checkedOn !== null) {
                $functions[$resolvedMap->refuseFunctionName($state)] = $this->refuseFunction($resolvedMap, $state);
            }
        }

        foreach ($resolvedMap->designated() as $dependent) {
            $functions[$resolvedMap->designateFunctionName($dependent)] = $this->designateFunction($resolvedMap, $dependent);
        }

        $this->validateFills($resolvedMap);
        $statements = [];

        foreach ($functions as $name => $function) {
            $statements[] = $function;
            $statements[] = 'REVOKE EXECUTE ON FUNCTION '.SqlFragments::identifier($name).'() FROM PUBLIC';
        }

        return new CompiledSql(
            [$this->view($resolvedMap), ...$this->defaults($resolvedMap)],
            $statements,
            $this->triggers($resolvedMap),
            $this->planner($resolvedMap, false),
        );
    }

    /** @return list<string> */
    public function prepare(CompatibilityMap|ResolvedMap $map): array
    {
        $resolvedMap = $map instanceof ResolvedMap ? $map : ResolvedMap::of($map);

        if ($resolvedMap->statisticsExpressions() === [] && $resolvedMap->primaryCurrentModel->indexes === []) {
            return [];
        }

        $table = $resolvedMap->primaryTable();

        if ($this->columnCatalog->of($table) === []) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "the current table {$table} does not exist");
        }

        $planned = [];

        foreach ($resolvedMap->primaryCurrentModel->indexes as $index) {
            $planned = [...$planned, ...array_column($index->columns, 'attribute'), ...array_column($index->filters, 'attribute')];
        }

        foreach ($resolvedMap->attributes as $attribute) {
            if ($attribute->alias !== $resolvedMap->primaryCurrentModel->alias) {
                continue;
            }

            if ($attribute->timeZone !== null) {
                $this->validateTimeZone($resolvedMap, $attribute);
            } elseif (in_array($attribute->column, $planned, true)) {
                $this->storedColumn($resolvedMap, $attribute);
            }
        }

        return $this->planner($resolvedMap, true);
    }

    /** @return list<string> */
    private function planner(ResolvedMap $resolvedMap, bool $concurrently): array
    {
        $table = SqlFragments::identifier($resolvedMap->primaryTable());
        $statements = $this->statistics($resolvedMap, $table);
        $indexes = $resolvedMap->primaryCurrentModel->indexes === [] ? [] : $this->plannerIndexes($resolvedMap);

        foreach ($resolvedMap->primaryCurrentModel->indexes as $index) {
            $name = $resolvedMap->indexName($index);
            $existing = $indexes[$name] ?? null;

            if ($existing === null) {
                $filter = $resolvedMap->indexFilter($index);
                $statements[] = 'CREATE INDEX '.($concurrently ? 'CONCURRENTLY ' : '').'IF NOT EXISTS '.SqlFragments::identifier($name)
                    .' ON '.$table.' ('.implode(', ', $resolvedMap->indexColumns($index)).')'.($filter === null ? '' : ' WHERE '.$filter);
            } elseif (! $existing->valid) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "index {$existing->relation} exists but is invalid, as a failed or unfinished CREATE INDEX CONCURRENTLY leaves it, so the planner cannot use it; DROP INDEX CONCURRENTLY {$existing->relation}, then prepare again");
            }
        }

        return $statements === [] ? [] : [...$statements, 'ANALYZE '.$table];
    }

    /** @return list<string> */
    private function statistics(ResolvedMap $resolvedMap, string $table): array
    {
        $expressions = $resolvedMap->statisticsExpressions();

        if ($expressions === []) {
            return [];
        }

        $name = $resolvedMap->statisticsName();
        $declared = implode(', ', array_map(fn (string $expression) => '('.$expression.')', $expressions));
        $existing = $this->connection->selectOne(
            "select quote_ident(statistics_namespace.nspname) || '.' || quote_ident(statistics.stxname) as name, obj_description(statistics.oid, 'pg_statistic_ext') as expressions
             from pg_statistic_ext statistics join pg_namespace statistics_namespace on statistics_namespace.oid = statistics.stxnamespace
             where statistics.stxname = ? and statistics.stxnamespace = to_regnamespace(quote_ident(current_schema()))",
            [$name],
        );

        if ($existing !== null && $existing->expressions === $declared) {
            return [];
        }

        return [
            ...($existing === null ? [] : ['DROP STATISTICS IF EXISTS '.$existing->name]),
            'CREATE STATISTICS IF NOT EXISTS '.SqlFragments::identifier($name).' ON '.$declared.' FROM '.$table,
            'COMMENT ON STATISTICS '.SqlFragments::identifier($name).' IS '.SqlFragments::literal($declared),
        ];
    }

    /** @return array<string, object{relation: string, valid: bool}> */
    private function plannerIndexes(ResolvedMap $resolvedMap): array
    {
        $indexes = [];
        $rows = $this->connection->select(
            'select index_relation.relname as name, index_relation.oid::regclass::text as relation, planner_index.indisvalid as valid
             from pg_index planner_index join pg_class index_relation on index_relation.oid = planner_index.indexrelid
             where index_relation.relname like '.SqlFragments::like($resolvedMap->objectPrefix()).' and index_relation.relnamespace = to_regnamespace(quote_ident(current_schema()))',
        );

        foreach ($rows as $row) {
            $indexes[(string) $row->name] = (object) ['relation' => (string) $row->relation, 'valid' => $row->valid === true];
        }

        return $indexes;
    }

    private function view(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $primary = (string) $resolvedMap->primaryCurrentModel->alias;
        $columns = array_map(
            fn (Attribute $attribute) => $this->read($resolvedMap, $attribute).' AS '.SqlFragments::identifier($attribute->column),
            $resolvedMap->attributes,
        );
        $hidden = array_map(
            fn (UnrepresentableState $state) => 'NOT COALESCE(('.$state->checkCondition.'), false)',
            $resolvedMap->unrepresentableStates(UnrepresentableStateKind::Hide),
        );

        return 'CREATE OR REPLACE VIEW '.SqlFragments::identifier($previous->view)." AS SELECT\n  ".implode(",\n  ", $columns)
            ."\nFROM ".SqlFragments::identifier($resolvedMap->table($primary)).' '.$primary
            ."\nJOIN ".SqlFragments::identifier((string) $previous->table).' '.$previous->alias
            .' ON '.$previous->alias.'.'.SqlFragments::identifier((string) $previous->primaryForeignKey).' = '.$primary.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primary))
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
            : '(SELECT '.$attribute->readAs.' '.$resolvedMap->locate($dependent, $primary.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primary)), (string) $resolvedMap->previousModel->alias).')';

        return 'CAST('.$expression.' AS '.$attribute->type.')';
    }

    /** @return list<string> */
    private function defaults(ResolvedMap $resolvedMap): array
    {
        $statements = [];

        foreach ($resolvedMap->attributes as $attribute) {
            $default = $attribute->defaultsTo;

            if ($default === null && $attribute->storedAs !== null && $attribute->alias !== null && $resolvedMap->dependent($attribute->alias) === null) {
                $default = $this->storedDefault($resolvedMap, $resolvedMap->table($attribute->alias), $attribute->storedAs);
                $default = $default === null || $attribute->timeZone === null ? $default : SqlFragments::atTimeZone('('.$default.')', $attribute->timeZone);
            }

            $statements[] = 'ALTER VIEW '.SqlFragments::identifier($resolvedMap->previousModel->view)
                .' ALTER COLUMN '.SqlFragments::identifier($attribute->column).($default === null ? ' DROP DEFAULT' : ' SET DEFAULT '.$default);
        }

        return $statements;
    }

    private function storedDefault(ResolvedMap $resolvedMap, string $table, string $name): null|string
    {
        $column = $this->columnCatalog->of($table)[$name] ?? null;

        if ($column?->defaultReferencesSource === true) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "the default of {$table}.{$name} references the retained table by its name before the rename, {$this->columnCatalog->source((string) $resolvedMap->previousModel->table)}; compile the compatibility map after the rename");
        }

        return $column?->default;
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
            if ($attribute->retained && ! ($this->columnCatalog->of((string) $previous->table)[$attribute->column]->alwaysGenerated ?? false)) {
                $previousColumns[$attribute->column] = $this->value($resolvedMap, $previousAlias, new TargetColumn($previousAlias, $attribute->column, 'NEW.'.SqlFragments::identifier($attribute->column)));
            }
        }

        $previousColumns = [
            ...$previousColumns,
            ...$this->insertValues($resolvedMap, $previousAlias, $this->insertAssignmentsTo($resolvedMap, $previousAlias), [], true),
            (string) $previous->primaryForeignKey => $primaryKey,
        ];

        $body = [
            ...$this->notNullChecks($resolvedMap, true),
            '  '.$this->insert($resolvedMap->table($primaryAlias), $this->insertValues($resolvedMap, $primaryAlias, $this->insertAssignmentsTo($resolvedMap, $primaryAlias), $primary->fillWhenCreatedFromPrevious), $resolvedMap->keyColumn($primaryAlias), $primaryKey),
            '  '.$this->insert((string) $previous->table, $previousColumns, '*', 'previous_row'),
        ];

        foreach ($resolvedMap->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;
            $assignments = $this->insertAssignmentsTo($resolvedMap, $alias);

            if ($assignments === [] && $dependent->fillWhenCreatedFromPrevious === []) {
                continue;
            }

            $body[] = '  IF ('.$this->createdWhen($dependent, $assignments).") THEN\n    "
                .$this->insert($resolvedMap->table($alias), $this->dependentValues($resolvedMap, $dependent, $assignments, $primaryKey), $resolvedMap->keyColumn($alias), 'key_'.$alias)
                .$this->designate($resolvedMap, $dependent, 'previous_row.'.SqlFragments::identifier((string) $previous->keyColumn), "\n    ")
                ."\n  END IF;";
        }

        $body[] = '  SELECT '.implode(', ', array_map(fn (Attribute $attribute) => $this->read($resolvedMap, $attribute), $resolvedMap->attributes))
            ."\n    INTO ".implode(', ', array_map(fn (Attribute $attribute) => 'NEW.'.SqlFragments::identifier($attribute->column), $resolvedMap->attributes))
            ."\n    FROM ".SqlFragments::identifier((string) $previous->table).' '.$previousAlias
            .' JOIN '.SqlFragments::identifier($resolvedMap->table($primaryAlias)).' '.$primaryAlias
            .' ON '.$primaryAlias.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primaryAlias)).' = '.$previousAlias.'.'.SqlFragments::identifier((string) $previous->primaryForeignKey)
            .' WHERE '.$previousAlias.'.'.SqlFragments::identifier((string) $previous->keyColumn).' = previous_row.'.SqlFragments::identifier((string) $previous->keyColumn).';';
        $body[] = '  PERFORM pg_catalog.set_config('.SqlFragments::literal(Setting::Created->for($resolvedMap)).', previous_row.'.SqlFragments::identifier((string) $previous->keyColumn).'::text, true);';
        $body[] = '  RETURN NEW;';

        return $this->function($resolvedMap, $resolvedMap->functionName(TriggerFunction::Insert), $previous->view, $this->variables($resolvedMap), $body, true);
    }

    private function updateFunction(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $primaryKey = 'key_'.$resolvedMap->primaryCurrentModel->alias;
        $record = ' || OLD.'.SqlFragments::identifier((string) $previous->keyColumn).'::text';
        $body = [];

        foreach ($resolvedMap->readOnlyAttributes() as $attribute) {
            $body[] = '  IF '.$this->compared($resolvedMap, $attribute, 'NEW.'.SqlFragments::identifier($attribute->column)).' IS DISTINCT FROM '.$this->compared($resolvedMap, $attribute, 'OLD.'.SqlFragments::identifier($attribute->column))." THEN\n    "
                .SqlFragments::refuse(
                    SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused a change to {$previous->view}.{$attribute->column} on record ").$record,
                    (string) $attribute->readOnlyBecause,
                    "Leave {$attribute->column} unchanged in writes through {$previous->view}.",
                )."\n  END IF;";
        }

        array_push($body, ...$this->notNullChecks($resolvedMap, false));
        $body[] = $this->lockPrimary($resolvedMap);
        $body[] = $this->loadPrevious($resolvedMap);
        $body[] = '  '.$primaryKey.' := previous_row.'.SqlFragments::identifier((string) $previous->primaryForeignKey).';';

        foreach ($resolvedMap->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->previousUpdateCondition !== null) {
                $body[] = '  IF COALESCE(('.$state->previousUpdateCondition.'), false) THEN'."\n    "
                    .SqlFragments::refuse(
                        SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused {$state->description} on {$previous->view} record ").$record,
                        $state->because,
                        $state->instead,
                    )."\n  END IF;";
            }
        }

        $body[] = $this->conflict($resolvedMap);
        $body[] = $this->updateTable($resolvedMap, (string) $resolvedMap->primaryCurrentModel->alias, $primaryKey, true);
        $body[] = $this->updateTable($resolvedMap, (string) $previous->alias, 'OLD.'.SqlFragments::identifier((string) $previous->keyColumn), false);

        foreach ($resolvedMap->dependentCurrentModels as $dependent) {
            $body[] = $this->updateDependent($resolvedMap, $dependent, $primaryKey);
        }

        $body[] = '  RETURN NEW;';

        return $this->function($resolvedMap, $resolvedMap->functionName(TriggerFunction::Update), $previous->view, $this->variables($resolvedMap), array_values(array_filter($body, fn (string $line) => $line !== '')), true);
    }

    private function deleteFunction(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $primaryAlias = (string) $resolvedMap->primaryCurrentModel->alias;
        $primaryKey = 'previous_row.'.SqlFragments::identifier((string) $previous->primaryForeignKey);
        $body = [
            $this->lockPrimary($resolvedMap),
            $this->loadPrevious($resolvedMap),
            '  DELETE FROM '.SqlFragments::identifier((string) $previous->table).' WHERE '.SqlFragments::identifier((string) $previous->keyColumn).' = OLD.'.SqlFragments::identifier((string) $previous->keyColumn).';',
        ];

        foreach (array_reverse($resolvedMap->dependentCurrentModels) as $dependent) {
            $body[] = '  DELETE FROM '.SqlFragments::identifier($resolvedMap->table((string) $dependent->alias))
                .' WHERE '.SqlFragments::identifier($resolvedMap->primaryForeignKey($dependent)).' = '.$primaryKey.';';
        }

        $body[] = '  DELETE FROM '.SqlFragments::identifier($resolvedMap->table($primaryAlias))
            .' WHERE '.SqlFragments::identifier($resolvedMap->keyColumn($primaryAlias)).' = '.$primaryKey.';';
        $body[] = '  RETURN OLD;';

        return $this->function($resolvedMap, $resolvedMap->functionName(TriggerFunction::Delete), $previous->view, $this->variables($resolvedMap), $body, true);
    }

    private function primaryInsertFunction(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $previousAlias = (string) $previous->alias;
        $values = [(string) $previous->primaryForeignKey => 'NEW.'.SqlFragments::identifier($resolvedMap->keyColumn((string) $resolvedMap->primaryCurrentModel->alias))];

        foreach ($previous->fillWhenCreatedFromCurrent as $column => $expression) {
            $values[$column] = $this->value($resolvedMap, $previousAlias, new TargetColumn($previousAlias, $column, $expression));
        }

        return $this->function($resolvedMap, $resolvedMap->functionName(TriggerFunction::PrimaryInsert), $resolvedMap->table((string) $resolvedMap->primaryCurrentModel->alias), '  created_key '.SqlFragments::identifier((string) $previous->table).'.'.SqlFragments::identifier((string) $previous->keyColumn)."%TYPE;\n", [
            $this->skipWhileApplying($resolvedMap, 'NEW'),
            '  '.$this->insert((string) $previous->table, $values, (string) $previous->keyColumn, 'created_key'),
            '  PERFORM pg_catalog.set_config('.SqlFragments::literal(Setting::Created->for($resolvedMap)).', created_key::text, true);',
            '  RETURN NEW;',
        ]);
    }

    private function designateFunction(ResolvedMap $resolvedMap, DependentCurrentModel $dependent): string
    {
        $alias = (string) $dependent->alias;
        $previous = $resolvedMap->previousModel;
        $designation = SqlFragments::identifier((string) $dependent->designationColumn);

        return $this->function($resolvedMap, $resolvedMap->designateFunctionName($dependent), $resolvedMap->table($alias), '', [
            $this->skipWhileApplying($resolvedMap, 'NEW'),
            '  IF EXISTS (SELECT 1 FROM (SELECT NEW.*) AS '.$alias.' WHERE COALESCE(('.$dependent->readableWhen.'), false)) THEN'."\n    "
                .'UPDATE '.SqlFragments::identifier((string) $previous->table).' SET '.$designation.' = NEW.'.SqlFragments::identifier($resolvedMap->keyColumn($alias))
                .' WHERE '.SqlFragments::identifier((string) $previous->primaryForeignKey).' = NEW.'.SqlFragments::identifier($resolvedMap->primaryForeignKey($dependent)).' AND '.$designation." IS NULL;\n  END IF;",
            '  RETURN NEW;',
        ]);
    }

    private function primaryDeleteFunction(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;

        return $this->function($resolvedMap, $resolvedMap->functionName(TriggerFunction::PrimaryDelete), $resolvedMap->table((string) $resolvedMap->primaryCurrentModel->alias), '', [
            $this->skipWhileApplying($resolvedMap, 'OLD'),
            '  DELETE FROM '.SqlFragments::identifier((string) $previous->table)
                .' WHERE '.SqlFragments::identifier((string) $previous->primaryForeignKey).' = OLD.'.SqlFragments::identifier($resolvedMap->keyColumn((string) $resolvedMap->primaryCurrentModel->alias)).';',
            '  RETURN OLD;',
        ]);
    }

    private function refuseFunction(ResolvedMap $resolvedMap, UnrepresentableState $state): string
    {
        $alias = (string) $state->checkedOn;
        $dependent = $resolvedMap->dependent($alias);
        $primaryForeignKey = match (true) {
            $dependent !== null => $resolvedMap->primaryForeignKey($dependent),
            $alias === $resolvedMap->previousModel->alias => (string) $resolvedMap->previousModel->primaryForeignKey,
            default => null,
        };
        $primary = (string) $resolvedMap->primaryCurrentModel->alias;
        $serialize = $primaryForeignKey === null ? [] : [
            '  PERFORM 1 FROM '.SqlFragments::identifier($resolvedMap->table($primary))
                .' WHERE '.SqlFragments::identifier($resolvedMap->keyColumn($primary)).' = NEW.'.SqlFragments::identifier($primaryForeignKey).' FOR KEY SHARE;',
            '  PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(pg_catalog.concat('.SqlFragments::literal($resolvedMap->refuseFunctionName($state).':')
                .', NEW.'.SqlFragments::identifier($primaryForeignKey).'), 0));',
        ];

        return $this->function($resolvedMap, $resolvedMap->refuseFunctionName($state), $resolvedMap->table($alias), '', [
            ...$serialize,
            '  IF EXISTS (SELECT 1 FROM (SELECT NEW.*) AS '.$alias.' WHERE COALESCE(('.$state->checkCondition.'), false)) THEN'."\n    "
                .SqlFragments::refuse(
                    SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused {$state->description} in ".$resolvedMap->table($alias).' record ')
                        .' || NEW.'.SqlFragments::identifier($resolvedMap->keyColumn($alias)).'::text',
                    $state->because,
                    $state->instead,
                )."\n  END IF;",
            '  RETURN NEW;',
        ]);
    }

    /** @return list<string> */
    private function triggers(ResolvedMap $resolvedMap): array
    {
        $view = SqlFragments::identifier($resolvedMap->previousModel->view);
        $primary = SqlFragments::identifier($resolvedMap->table((string) $resolvedMap->primaryCurrentModel->alias));
        $trigger = fn (string $function, string $when) => 'CREATE TRIGGER '.SqlFragments::identifier($function).' '.$when
            .' FOR EACH ROW EXECUTE FUNCTION '.SqlFragments::identifier($function).'()';

        $triggers = [
            $trigger($resolvedMap->functionName(TriggerFunction::Insert), 'INSTEAD OF INSERT ON '.$view),
            $trigger($resolvedMap->functionName(TriggerFunction::Update), 'INSTEAD OF UPDATE ON '.$view),
            $trigger($resolvedMap->functionName(TriggerFunction::Delete), 'INSTEAD OF DELETE ON '.$view),
            $trigger($resolvedMap->functionName(TriggerFunction::PrimaryInsert), 'AFTER INSERT ON '.$primary),
            $trigger($resolvedMap->functionName(TriggerFunction::PrimaryDelete), 'BEFORE DELETE ON '.$primary),
        ];

        foreach ($resolvedMap->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->checkedOn !== null) {
                $triggers[] = $trigger($resolvedMap->refuseFunctionName($state), 'BEFORE INSERT OR UPDATE ON '.SqlFragments::identifier($resolvedMap->table($state->checkedOn)));
            }
        }

        foreach ($resolvedMap->designated() as $dependent) {
            $triggers[] = $trigger($resolvedMap->designateFunctionName($dependent), 'AFTER INSERT OR UPDATE ON '.SqlFragments::identifier($resolvedMap->table((string) $dependent->alias)));
        }

        return $triggers;
    }

    private function updateTable(ResolvedMap $resolvedMap, string $alias, string $key, bool $mustExist): string
    {
        $assignments = $resolvedMap->assignmentsTo($alias);

        if ($assignments === []) {
            return '';
        }

        $statement = '  IF '.SqlFragments::changed($this->attributeColumns($assignments), $this->comparedAsText($resolvedMap, $assignments))." THEN\n    "
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
            .' || OLD.'.SqlFragments::identifier((string) $previous->keyColumn).'::text';
        $cases = ['EXISTS (SELECT 1 '.$resolvedMap->locate($dependent, $primaryKey, 'previous_row').')' => $this->concurrentChange($resolvedMap)];

        if ($dependent->designationColumn !== null) {
            $designation = $previous->table.'.'.$dependent->designationColumn;
            $cases['EXISTS (SELECT 1 FROM '.SqlFragments::identifier($table).' WHERE '.SqlFragments::identifier($resolvedMap->keyColumn($alias)).' = previous_row.'.SqlFragments::identifier($dependent->designationColumn).')'] = SqlFragments::refuse(
                $refused.' || '.SqlFragments::literal(": its designated {$table} row is not one the previous model reads"),
                "The record reads only the {$table} row that {$designation} names, and that row no longer matches ({$dependent->readableWhen}) or belongs to another record.",
                "Make that {$table} row match again through its own model, or repair {$designation} by hand.",
            );
        }

        $cases['('.$this->createdWhen($dependent, $assignments).')'] = $this->insert($table, $this->dependentValues($resolvedMap, $dependent, $assignments, $primaryKey), $resolvedMap->keyColumn($alias), 'key_'.$alias)
            .$this->designate($resolvedMap, $dependent, 'OLD.'.SqlFragments::identifier((string) $previous->keyColumn), "\n        ");
        $branches = '';

        foreach ($cases as $condition => $statement) {
            $branches .= ($branches === '' ? '      IF ' : "\n      ELSIF ").$condition." THEN\n        ".$statement;
        }

        return '  IF '.SqlFragments::changed($columns, $this->comparedAsText($resolvedMap, $assignments))." THEN\n"
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
        if ($dependent->designationColumn === null) {
            return '';
        }

        $previous = $resolvedMap->previousModel;

        return $indent.'UPDATE '.SqlFragments::identifier((string) $previous->table).' SET '.SqlFragments::identifier($dependent->designationColumn).' = key_'.$dependent->alias
            .' WHERE '.SqlFragments::identifier((string) $previous->keyColumn).' = '.$previousKey.';';
    }

    /**
     * @param  list<Assignment>  $assignments
     */
    private function update(ResolvedMap $resolvedMap, string $alias, array $assignments, string $key): string
    {
        $sets = array_map(
            fn (Assignment $assignment) => SqlFragments::identifier($assignment->targetColumn->column).' = CASE WHEN '.$this->compared($resolvedMap, $assignment->attribute, 'NEW.'.SqlFragments::identifier($assignment->attribute->column))
                .' IS DISTINCT FROM '.$this->compared($resolvedMap, $assignment->attribute, 'OLD.'.SqlFragments::identifier($assignment->attribute->column))
                .' THEN '.$this->value($resolvedMap, $alias, $assignment->targetColumn)
                .' ELSE '.$alias.'.'.SqlFragments::identifier($assignment->targetColumn->column).' END',
            $assignments,
        );

        return 'UPDATE '.SqlFragments::identifier($resolvedMap->table($alias)).' AS '.$alias.' SET '.implode(', ', $sets)
            .' WHERE '.$alias.'.'.SqlFragments::identifier($resolvedMap->keyColumn($alias)).' = '.$key.';';
    }

    /**
     * @param  array<string, string>  $values
     */
    private function insert(string $table, array $values, string $returning, string $into): string
    {
        $returning = $returning === '*' ? '*' : SqlFragments::identifier($returning);

        if ($values === []) {
            return 'INSERT INTO '.SqlFragments::identifier($table).' DEFAULT VALUES RETURNING '.$returning.' INTO '.$into.';';
        }

        return 'INSERT INTO '.SqlFragments::identifier($table).' ('.implode(', ', array_map(SqlFragments::identifier(...), array_keys($values))).')'
            .' VALUES ('.implode(', ', $values).') RETURNING '.$returning.' INTO '.$into.';';
    }

    /**
     * @return list<Assignment>
     */
    private function insertAssignmentsTo(ResolvedMap $resolvedMap, string $alias): array
    {
        $assignments = $resolvedMap->assignmentsTo($alias);

        foreach ($resolvedMap->readOnlyAttributes() as $attribute) {
            if (! $attribute->retained && $attribute->alias === $alias && $attribute->storedAs !== null && $attribute->writesTo === []
                && ! $resolvedMap->writes($alias, $attribute->storedAs)
                && ! ($this->columnCatalog->of($resolvedMap->table($alias))[$attribute->storedAs]->alwaysGenerated ?? false)) {
                $assignments[] = new Assignment($attribute, new TargetColumn($alias, $attribute->storedAs, SqlFragments::atTimeZone('NEW.'.SqlFragments::identifier($attribute->column), $attribute->timeZone)));
            }
        }

        return $assignments;
    }

    /**
     * @param  list<Assignment>  $assignments
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function insertValues(ResolvedMap $resolvedMap, string $alias, array $assignments, array $extra, bool $mappedOnly = false): array
    {
        $values = [];

        foreach ($assignments as $assignment) {
            if (! $mappedOnly || ! $assignment->attribute->retained) {
                $value = $this->value($resolvedMap, $alias, $assignment->targetColumn);
                $table = $resolvedMap->table($alias);
                $column = $this->columnCatalog->of($table)[$assignment->targetColumn->column];
                $default = $column->notNull ? $this->storedDefault($resolvedMap, $table, $assignment->targetColumn->column) : null;
                $values[$assignment->targetColumn->column] = match (true) {
                    $default === null => $value,
                    $column->baseType === null => 'COALESCE('.$value.', '.$default.')',
                    default => 'CAST((COALESCE(CAST(('.$assignment->targetColumn->expression.') AS '.$column->baseType.'), '.$default.')) AS '.$column->type.')',
                };
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
     * @param  list<Assignment>  $assignments
     * @return array<string, string>
     */
    private function dependentValues(ResolvedMap $resolvedMap, DependentCurrentModel $dependent, array $assignments, string $primaryKey): array
    {
        return [$resolvedMap->primaryForeignKey($dependent) => $primaryKey, ...$this->insertValues($resolvedMap, (string) $dependent->alias, $assignments, $dependent->fillWhenCreatedFromPrevious)];
    }

    /**
     * @param  list<Assignment>  $assignments
     */
    private function createdWhen(DependentCurrentModel $dependent, array $assignments): string
    {
        if ($dependent->createdWhen !== null) {
            return $dependent->createdWhen;
        }

        $columns = $this->attributeColumns($assignments);

        return $columns === []
            ? 'true'
            : implode(' OR ', array_map(fn (string $column) => 'NEW.'.SqlFragments::identifier($column).' IS NOT NULL', $columns));
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
        $column = $this->columnCatalog->of($table)[$targetColumn->column] ?? null;

        if ($column === null) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "{$table} has no column {$targetColumn->column}");
        }

        if ($column->typeReferencesSource) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "the type of {$table}.{$targetColumn->column} references the retained table by its name before the rename, {$this->columnCatalog->source((string) $resolvedMap->previousModel->table)}; compile the compatibility map after the rename");
        }

        return 'CAST(('.$targetColumn->expression.') AS '.$column->type.')';
    }

    /** @return list<string> */
    private function notNullChecks(ResolvedMap $resolvedMap, bool $insert): array
    {
        $previous = $resolvedMap->previousModel;
        $record = $insert ? SqlFragments::literal('a new record') : SqlFragments::literal('record ').' || OLD.'.SqlFragments::identifier((string) $previous->keyColumn).'::text';
        $checks = [];

        foreach ($resolvedMap->attributes as $attribute) {
            if ($attribute->retained || ! $this->columnCatalog->notNull((string) $previous->table, $attribute->column)) {
                continue;
            }

            $column = SqlFragments::identifier($attribute->column);
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

        return '  PERFORM 1 FROM '.SqlFragments::identifier($resolvedMap->table($primary))
            .' WHERE '.SqlFragments::identifier($resolvedMap->keyColumn($primary)).' = OLD.'.SqlFragments::identifier((string) $resolvedMap->previousModel->primaryForeignKey).' FOR UPDATE;';
    }

    private function conflict(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $primary = (string) $resolvedMap->primaryCurrentModel->alias;
        $locks = [];
        $joins = '';

        foreach ($resolvedMap->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;
            $key = $alias.'.'.SqlFragments::identifier($resolvedMap->keyColumn($alias));
            $locks[] = '  SELECT '.$key.' INTO key_'.$alias.' '.$resolvedMap->locate($dependent, 'key_'.$primary, 'previous_row').' FOR UPDATE;';
            $joins .= ' LEFT JOIN '.SqlFragments::identifier($resolvedMap->table($alias)).' '.$alias.' ON '.$key.' = key_'.$alias;
        }

        $read = function (Attribute $attribute) use ($resolvedMap): string {
            $dependent = $resolvedMap->dependent((string) $attribute->alias);
            $expression = $dependent === null
                ? $this->read($resolvedMap, $attribute)
                : 'CASE WHEN key_'.$dependent->alias.' IS NULL THEN OLD.'.SqlFragments::identifier($attribute->column).' ELSE CAST(('.$attribute->readAs.') AS '.$attribute->type.') END';

            return $this->compared($resolvedMap, $attribute, $expression);
        };

        return implode("\n", $locks).($locks === [] ? '' : "\n")
            .'  IF EXISTS (SELECT 1 FROM '.SqlFragments::identifier((string) $previous->table).' '.$previous->alias
            .' JOIN '.SqlFragments::identifier($resolvedMap->table($primary)).' '.$primary
            .' ON '.$primary.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primary)).' = '.$previous->alias.'.'.SqlFragments::identifier((string) $previous->primaryForeignKey)
            .$joins
            .' WHERE '.$previous->alias.'.'.SqlFragments::identifier((string) $previous->keyColumn).' = OLD.'.SqlFragments::identifier((string) $previous->keyColumn)
            .' AND ROW('.implode(', ', array_map($read, $resolvedMap->attributes)).')'
            .' IS DISTINCT FROM ROW('.implode(', ', array_map(fn (Attribute $attribute) => $this->compared($resolvedMap, $attribute, 'OLD.'.SqlFragments::identifier($attribute->column)), $resolvedMap->attributes)).")) THEN\n    "
            .$this->concurrentChange($resolvedMap)."\n  END IF;";
    }

    private function concurrentChange(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;

        return SqlFragments::raise(
            SqlState::SerializationFailure,
            SqlFragments::literal("Compatibility map '{$resolvedMap->name}' found a concurrent change to {$previous->view} record ")
                .' || OLD.'.SqlFragments::identifier((string) $previous->keyColumn).'::text || '.SqlFragments::literal(" since this statement's snapshot"),
            "An INSTEAD OF trigger receives OLD from the statement's snapshot and cannot re-read the row, so writing would lose that change.",
            "Retry the transaction, or write through the {$previous->model} model: the event bridge locks the record before its statement.",
        );
    }

    private function loadPrevious(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;

        return '  SELECT * INTO previous_row FROM '.SqlFragments::identifier((string) $previous->table)
            .' WHERE '.SqlFragments::identifier((string) $previous->keyColumn).' = OLD.'.SqlFragments::identifier((string) $previous->keyColumn)." FOR UPDATE;\n"
            ."  IF NOT FOUND THEN\n    ".$this->missing($resolvedMap, (string) $previous->table)."\n  END IF;";
    }

    private function missing(ResolvedMap $resolvedMap, string $table): string
    {
        $previous = $resolvedMap->previousModel;

        return SqlFragments::refuse(
            SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused a write to {$previous->view} record ")
                .' || OLD.'.SqlFragments::identifier((string) $previous->keyColumn).'::text || '.SqlFragments::literal(": its {$table} row is missing"),
            'The record has nowhere to store the change, so the previous and current models have diverged.',
            "Run php artisan compatibility:check --map={$resolvedMap->name} and repair the record it names.",
        );
    }

    private function skipWhileApplying(ResolvedMap $resolvedMap, string $row): string
    {
        return '  IF pg_catalog.current_setting('.SqlFragments::literal(Setting::Applying->for($resolvedMap)).', true) = '.SqlFragments::literal($resolvedMap->name)." THEN\n    RETURN {$row};\n  END IF;";
    }

    private function variables(ResolvedMap $resolvedMap): string
    {
        $lines = ['  previous_row '.SqlFragments::identifier((string) $resolvedMap->previousModel->table).'%ROWTYPE;', '  affected_rows integer;'];

        foreach ([$resolvedMap->primaryCurrentModel, ...$resolvedMap->dependentCurrentModels] as $current) {
            $alias = (string) $current->alias;
            $lines[] = '  key_'.$alias.' '.SqlFragments::identifier($resolvedMap->table($alias))
                .'.'.SqlFragments::identifier($resolvedMap->keyColumn($alias)).'%TYPE;';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $body
     */
    private function function(ResolvedMap $resolvedMap, string $name, string $relation, string $variables, array $body, bool $applying = false): string
    {
        $owner = SqlFragments::identifier($this->schema).'.'.SqlFragments::identifier($relation);
        $guard = '  IF TG_RELID <> '.SqlFragments::literal($owner).'::regclass AND pg_catalog.pg_partition_root(TG_RELID) IS DISTINCT FROM '.SqlFragments::literal($owner)."::regclass THEN\n    "
            .SqlFragments::refuse(
                SqlFragments::literal("Compatibility map '{$resolvedMap->name}' refused to run {$name} on ").' || TG_TABLE_SCHEMA || '.SqlFragments::literal('.').' || TG_TABLE_NAME',
                "{$name} belongs to {$this->schema}.{$relation}, and only the compatibility map's own trigger on it may run it.",
                "Attach no trigger of your own to {$name}.",
            )."\n  END IF;";
        $definition = ($variables === '' ? '' : "DECLARE\n".$variables)."BEGIN\n".implode("\n", [$guard, ...$body])."\nEND;\n";

        if (str_contains($definition, '$compatibility$')) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "the SQL of trigger function {$name} contains \$compatibility\$, which would end its body early; remove it from the map's expressions");
        }

        return 'CREATE OR REPLACE FUNCTION '.SqlFragments::identifier($name).'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = '.$this->searchPath()
            .($applying ? ' SET '.Setting::Applying->for($resolvedMap).' = '.SqlFragments::literal($resolvedMap->name) : '')
            ." AS \$compatibility\$\n".$definition.'$compatibility$';
    }

    private function searchPath(): string
    {
        return SqlFragments::identifier($this->schema).', pg_temp';
    }

    private function compared(ResolvedMap $resolvedMap, Attribute $attribute, string $expression): string
    {
        return $this->comparesAsText($resolvedMap, $attribute) ? '('.$expression.')::text' : $expression;
    }

    private function comparesAsText(ResolvedMap $resolvedMap, Attribute $attribute): bool
    {
        $type = $attribute->retained ? $this->columnCatalog->type((string) $resolvedMap->previousModel->table, $attribute->column) : $attribute->type;

        return $type !== null && ! $this->columnCatalog->equatable($type);
    }

    /**
     * @param  list<Assignment>  $assignments
     * @return list<string>
     */
    private function comparedAsText(ResolvedMap $resolvedMap, array $assignments): array
    {
        $assignments = array_filter($assignments, fn (Assignment $assignment) => $this->comparesAsText($resolvedMap, $assignment->attribute));

        return $this->attributeColumns(array_values($assignments));
    }

    private function validateColumns(ResolvedMap $resolvedMap): void
    {
        $previous = $resolvedMap->previousModel;
        $table = (string) $previous->table;
        $columns = $this->columnCatalog->of($table);

        if ($columns === []) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "the retained table {$this->columnCatalog->source($table)} does not exist");
        }

        foreach (array_unique(array_map($resolvedMap->table(...), array_values($resolvedMap->currentModels()))) as $currentTable) {
            if ($this->columnCatalog->of($currentTable) === []) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "the current table {$currentTable} does not exist");
            }
        }

        $designations = array_map(fn (DependentCurrentModel $dependent) => (string) $dependent->designationColumn, $resolvedMap->designated());

        foreach ([(string) $previous->keyColumn, (string) $previous->primaryForeignKey, ...$designations] as $column) {
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

                $targetTable = $resolvedMap->table($targetColumn->alias);

                if ($this->columnCatalog->of($targetTable)[$targetColumn->column]->alwaysGenerated ?? false) {
                    throw InvalidCompatibilityMap::because($resolvedMap->name, "attribute {$attribute->column} writes {$targetTable}.{$targetColumn->column}, which the database always generates; declare it read-only");
                }
            }
        }

        foreach ($resolvedMap->dependentCurrentModels as $dependent) {
            $dependentTable = $resolvedMap->table((string) $dependent->alias);

            if ($this->columnCatalog->type($dependentTable, $resolvedMap->primaryForeignKey($dependent)) === null) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, $dependentTable.' has no column '.$resolvedMap->primaryForeignKey($dependent));
            }
        }

        foreach ($resolvedMap->attributes as $attribute) {
            $this->validateTimeZone($resolvedMap, $attribute);
        }
    }

    private function validateTimeZone(ResolvedMap $resolvedMap, Attribute $attribute): void
    {
        if ($attribute->timeZone === null || $attribute->alias === null || $attribute->storedAs === null) {
            return;
        }

        $column = $this->storedColumn($resolvedMap, $attribute);
        $table = $resolvedMap->table($attribute->alias);

        if (preg_match(self::TIMESTAMP_WITH_TIME_ZONE, $column->baseType ?? $column->type) !== 1) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "attribute {$attribute->column} says inTimeZone(), which needs {$table}.{$attribute->storedAs} to be a timestamp with time zone, not {$column->type}");
        }

        $known = $this->timeZones[$attribute->timeZone] ??= $this->connection->scalar(
            'select exists (select 1 from pg_timezone_names where lower(name) = lower(?)) or exists (select 1 from pg_timezone_abbrevs where lower(abbrev) = lower(?))',
            [$attribute->timeZone, $attribute->timeZone],
        ) === true;

        if (! $known) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "attribute {$attribute->column} says inTimeZone('{$attribute->timeZone}'), which neither pg_timezone_names nor pg_timezone_abbrevs holds");
        }
    }

    private function storedColumn(ResolvedMap $resolvedMap, Attribute $attribute): Column
    {
        $table = $resolvedMap->table((string) $attribute->alias);
        $column = $this->columnCatalog->of($table)[(string) $attribute->storedAs] ?? null;

        if ($column === null) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "{$table} has no column {$attribute->storedAs}");
        }

        return $column;
    }

    private function validateFills(ResolvedMap $resolvedMap): void
    {
        $previous = $resolvedMap->previousModel;
        $previousAlias = (string) $previous->alias;
        $primary = $resolvedMap->primaryCurrentModel;
        $primaryTable = $resolvedMap->table((string) $primary->alias);
        $throughView = "an insert through {$previous->view} leaves it empty; ";
        $targets = fn (string $alias) => array_map(fn (Assignment $assignment) => $assignment->targetColumn->column, $this->insertAssignmentsTo($resolvedMap, $alias));
        $retained = array_map(fn (Attribute $attribute) => $attribute->column, array_filter($resolvedMap->attributes, fn (Attribute $attribute) => $attribute->retained));

        $this->refuseEmpty($resolvedMap, (string) $previous->table, [(string) $previous->primaryForeignKey, ...array_keys($previous->fillWhenCreatedFromCurrent)], "a {$primaryTable} row created through its own model leaves it empty; fill it with fillWhenCreatedFromCurrent()");
        $this->refuseEmpty($resolvedMap, (string) $previous->table, [(string) $previous->primaryForeignKey, ...$retained, ...$targets($previousAlias)], $throughView.'declare it as a retained attribute');
        $this->refuseEmpty($resolvedMap, $primaryTable, [...$targets((string) $primary->alias), ...array_keys($primary->fillWhenCreatedFromPrevious)], $throughView.'map an attribute to it or fill it with fillWhenCreatedFromPrevious()');

        foreach ($resolvedMap->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;

            if ($this->insertAssignmentsTo($resolvedMap, $alias) !== [] || $dependent->fillWhenCreatedFromPrevious !== []) {
                $this->refuseEmpty($resolvedMap, $resolvedMap->table($alias), [$resolvedMap->primaryForeignKey($dependent), ...$targets($alias), ...array_keys($dependent->fillWhenCreatedFromPrevious)], $throughView.'map an attribute to it or fill it with fillWhenCreatedFromPrevious()');
            }
        }
    }

    /**
     * @param  list<string>  $filled
     */
    private function refuseEmpty(ResolvedMap $resolvedMap, string $table, array $filled, string $problem): void
    {
        foreach ($this->columnCatalog->of($table) as $name => $column) {
            if ($column->needsAValue() && ! in_array($name, $filled, true)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$table}.{$name} is NOT NULL without a default, and {$problem}");
            }
        }
    }
}
