<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\Declaration;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableStateKind;
use Support\Database\Eloquent\Compatibility\Mapping\Write;

final class Compiler
{
    private readonly Columns $columns;

    private readonly string $schema;

    public function __construct(ConnectionInterface $connection)
    {
        $this->columns = new Columns($connection);
        $this->schema = (string) $connection->scalar('select current_schema()');
    }

    public function compile(CompatibilityMap|Declaration $map): Compiled
    {
        $declaration = $map instanceof Declaration ? $map : Declaration::of($map);
        $this->validateColumns($declaration);

        $functions = [
            'insert' => $this->insertFunction($declaration),
            'update' => $this->updateFunction($declaration),
            'delete' => $this->deleteFunction($declaration),
            'primary_insert' => $this->primaryInsertFunction($declaration),
            'primary_delete' => $this->primaryDeleteFunction($declaration),
        ];

        foreach ($declaration->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->alias !== null) {
                $functions['refuse_'.$state->name] = $this->refuseFunction($declaration, $state);
            }
        }

        foreach ($declaration->designated() as $dependent) {
            $functions['designate_'.$dependent->alias] = $this->designateFunction($declaration, $dependent);
        }

        return new Compiled(
            [$this->view($declaration), ...$this->defaults($declaration)],
            array_values($functions),
            $this->triggers($declaration),
        );
    }

    private function view(Declaration $declaration): string
    {
        $previous = $declaration->previousModel;
        $primary = (string) $declaration->primaryCurrentModel->alias;
        $columns = array_map(
            fn (Attribute $attribute) => $this->read($declaration, $attribute).' AS '.Sql::id($attribute->column),
            $declaration->attributes,
        );
        $hidden = array_map(
            fn (UnrepresentableState $state) => 'NOT COALESCE(('.$state->condition.'), false)',
            $declaration->unrepresentableStates(UnrepresentableStateKind::Hide),
        );

        return 'CREATE OR REPLACE VIEW '.Sql::id($previous->view)." AS SELECT\n  ".implode(",\n  ", $columns)
            ."\nFROM ".Sql::id((string) $previous->table).' '.$previous->alias
            ."\nJOIN ".Sql::id($declaration->table($primary)).' '.$primary
            .' ON '.$primary.'.'.Sql::id($declaration->key($primary)).' = '.$previous->alias.'.'.Sql::id((string) $previous->primaryForeignKey)
            .($hidden === [] ? '' : "\nWHERE ".implode("\n  AND ", $hidden));
    }

    private function read(Declaration $declaration, Attribute $attribute): string
    {
        if ($attribute->retained) {
            return (string) $attribute->readAs;
        }

        $dependent = $declaration->dependent((string) $attribute->alias);
        $primary = (string) $declaration->primaryCurrentModel->alias;
        $expression = $dependent === null
            ? '('.$attribute->readAs.')'
            : '(SELECT '.$attribute->readAs.' '.$declaration->locate($dependent, $primary.'.'.Sql::id($declaration->key($primary)), (string) $declaration->previousModel->alias).')';

        return 'CAST('.$expression.' AS '.$attribute->type.')';
    }

    /** @return list<string> */
    private function defaults(Declaration $declaration): array
    {
        $statements = [];

        foreach ($declaration->attributes as $attribute) {
            $default = $attribute->defaultsTo;

            if ($default === null && $attribute->storedAs !== null && $attribute->alias !== null && $declaration->dependent($attribute->alias) === null) {
                $default = $this->columns->of($declaration->table($attribute->alias))[$attribute->storedAs]['default'] ?? null;
            }

            if ($default !== null) {
                $statements[] = 'ALTER VIEW '.Sql::id($declaration->previousModel->view)
                    .' ALTER COLUMN '.Sql::id($attribute->column).' SET DEFAULT '.$default;
            }
        }

        return $statements;
    }

    private function insertFunction(Declaration $declaration): string
    {
        $previous = $declaration->previousModel;
        $previousAlias = (string) $previous->alias;
        $primary = $declaration->primaryCurrentModel;
        $primaryAlias = (string) $primary->alias;
        $primaryKey = 'key_'.$primaryAlias;
        $previousColumns = [];

        foreach ($declaration->attributes as $attribute) {
            if ($attribute->retained) {
                $previousColumns[$attribute->column] = $this->value($declaration, $previousAlias, new Write($previousAlias, $attribute->column, 'NEW.'.Sql::id($attribute->column)));
            }
        }

        $previousColumns = [
            ...$previousColumns,
            ...$this->insertValues($declaration, $previousAlias, [], true),
            (string) $previous->primaryForeignKey => $primaryKey,
        ];

        $body = [
            ...$this->notNullChecks($declaration, true),
            $this->applying($declaration),
            '  '.$this->insert($declaration->table($primaryAlias), $this->insertValues($declaration, $primaryAlias, $primary->fillWhenCreatedFromPrevious), $declaration->key($primaryAlias), $primaryKey),
            '  '.$this->insert((string) $previous->table, $previousColumns, '*', 'previous_row'),
            '  NEW.'.Sql::id((string) $previous->key).' := previous_row.'.Sql::id((string) $previous->key).';',
        ];

        foreach ($declaration->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;

            if ($declaration->writesTo($alias) === [] && $dependent->fillWhenCreatedFromPrevious === []) {
                continue;
            }

            $body[] = '  IF ('.$this->createdWhen($declaration, $dependent).") THEN\n    "
                .$this->insert($declaration->table($alias), $this->dependentValues($declaration, $dependent, $primaryKey), $declaration->key($alias), 'key_'.$alias)
                .$this->designate($declaration, $dependent, 'previous_row.'.Sql::id((string) $previous->key), "\n    ")
                ."\n  END IF;";
        }

        $body[] = $this->applying($declaration, false);
        $body[] = "  PERFORM set_config('compatibility.created_".$declaration->name."', previous_row.".Sql::id((string) $previous->key).'::text, true);';
        $body[] = '  RETURN NEW;';

        return $this->function($declaration->object('insert'), $this->variables($declaration), $body);
    }

    private function updateFunction(Declaration $declaration): string
    {
        $previous = $declaration->previousModel;
        $primaryKey = 'key_'.$declaration->primaryCurrentModel->alias;
        $record = ' || OLD.'.Sql::id((string) $previous->key).'::text';
        $body = [];

        foreach ($declaration->readOnlyAttributes() as $attribute) {
            $body[] = '  IF NEW.'.Sql::id($attribute->column).' IS DISTINCT FROM OLD.'.Sql::id($attribute->column)." THEN\n    "
                .Sql::refuse(
                    Sql::literal("Compatibility map '{$declaration->name}' refused a change to {$previous->view}.{$attribute->column} on record ").$record,
                    (string) $attribute->readOnlyBecause,
                    "Leave {$attribute->column} unchanged in writes through {$previous->view}.",
                )."\n  END IF;";
        }

        array_push($body, ...$this->notNullChecks($declaration, false));
        $body[] = $this->lockPrimary($declaration);
        $body[] = $this->loadPrevious($declaration);
        $body[] = '  '.$primaryKey.' := previous_row.'.Sql::id((string) $previous->primaryForeignKey).';';

        foreach ($declaration->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->previousUpdate !== null) {
                $body[] = '  IF COALESCE(('.$state->previousUpdate.'), false) THEN'."\n    "
                    .Sql::refuse(
                        Sql::literal("Compatibility map '{$declaration->name}' refused {$state->state} on {$previous->view} record ").$record,
                        $state->because,
                        $state->instead,
                    )."\n  END IF;";
            }
        }

        $body[] = $this->conflict($declaration);
        $body[] = $this->applying($declaration);
        $body[] = $this->updateTable($declaration, (string) $declaration->primaryCurrentModel->alias, $primaryKey, true);
        $body[] = $this->updateTable($declaration, (string) $previous->alias, 'OLD.'.Sql::id((string) $previous->key), false);

        foreach ($declaration->dependentCurrentModels as $dependent) {
            $body[] = $this->updateDependent($declaration, $dependent, $primaryKey);
        }

        $body[] = $this->applying($declaration, false);
        $body[] = '  RETURN NEW;';

        return $this->function($declaration->object('update'), $this->variables($declaration), array_values(array_filter($body, fn (string $line) => $line !== '')));
    }

    private function deleteFunction(Declaration $declaration): string
    {
        $previous = $declaration->previousModel;
        $primaryAlias = (string) $declaration->primaryCurrentModel->alias;
        $primaryKey = 'previous_row.'.Sql::id((string) $previous->primaryForeignKey);
        $body = [
            $this->lockPrimary($declaration),
            $this->loadPrevious($declaration),
            $this->applying($declaration),
            '  DELETE FROM '.Sql::id((string) $previous->table).' WHERE '.Sql::id((string) $previous->key).' = OLD.'.Sql::id((string) $previous->key).';',
        ];

        foreach (array_reverse($declaration->dependentCurrentModels) as $dependent) {
            $body[] = '  DELETE FROM '.Sql::id($declaration->table((string) $dependent->alias))
                .' WHERE '.Sql::id($declaration->primaryForeignKey($dependent)).' = '.$primaryKey.';';
        }

        $body[] = '  DELETE FROM '.Sql::id($declaration->table($primaryAlias))
            .' WHERE '.Sql::id($declaration->key($primaryAlias)).' = '.$primaryKey.';';
        $body[] = $this->applying($declaration, false);
        $body[] = '  RETURN OLD;';

        return $this->function($declaration->object('delete'), $this->variables($declaration), $body);
    }

    private function primaryInsertFunction(Declaration $declaration): string
    {
        $previous = $declaration->previousModel;
        $previousAlias = (string) $previous->alias;
        $values = [(string) $previous->primaryForeignKey => 'NEW.'.Sql::id($declaration->key((string) $declaration->primaryCurrentModel->alias))];

        foreach ($previous->fillWhenCreatedFromCurrent as $column => $expression) {
            $values[$column] = $this->value($declaration, $previousAlias, new Write($previousAlias, $column, $expression));
        }

        return $this->function($declaration->object('primary_insert'), '  created_key '.Sql::id((string) $previous->table).'.'.Sql::id((string) $previous->key)."%TYPE;\n", [
            $this->skipWhileApplying($declaration, 'NEW'),
            '  '.$this->insert((string) $previous->table, $values, (string) $previous->key, 'created_key'),
            "  PERFORM set_config('compatibility.created_".$declaration->name."', created_key::text, true);",
            '  RETURN NEW;',
        ]);
    }

    private function designateFunction(Declaration $declaration, DependentCurrentModel $dependent): string
    {
        $alias = (string) $dependent->alias;
        $previous = $declaration->previousModel;
        $designation = Sql::id((string) $dependent->designation);

        return $this->function($declaration->object('designate_'.$alias), '', [
            $this->skipWhileApplying($declaration, 'NEW'),
            '  IF EXISTS (SELECT 1 FROM (SELECT NEW.*) AS '.$alias.' WHERE COALESCE(('.$dependent->readableWhen.'), false)) THEN'."\n    "
                .'UPDATE '.Sql::id((string) $previous->table).' SET '.$designation.' = NEW.'.Sql::id($declaration->key($alias))
                .' WHERE '.Sql::id((string) $previous->primaryForeignKey).' = NEW.'.Sql::id($declaration->primaryForeignKey($dependent)).' AND '.$designation." IS NULL;\n  END IF;",
            '  RETURN NEW;',
        ]);
    }

    private function primaryDeleteFunction(Declaration $declaration): string
    {
        $previous = $declaration->previousModel;

        return $this->function($declaration->object('primary_delete'), '', [
            $this->skipWhileApplying($declaration, 'OLD'),
            '  DELETE FROM '.Sql::id((string) $previous->table)
                .' WHERE '.Sql::id((string) $previous->primaryForeignKey).' = OLD.'.Sql::id($declaration->key((string) $declaration->primaryCurrentModel->alias)).';',
            '  RETURN OLD;',
        ]);
    }

    private function refuseFunction(Declaration $declaration, UnrepresentableState $state): string
    {
        $alias = (string) $state->alias;

        return $this->function($declaration->object('refuse_'.$state->name), '', [
            '  IF EXISTS (SELECT 1 FROM (SELECT NEW.*) AS '.$alias.' WHERE COALESCE(('.$state->condition.'), false)) THEN'."\n    "
                .Sql::refuse(
                    Sql::literal("Compatibility map '{$declaration->name}' refused {$state->state} in ".$declaration->table($alias).' record ')
                        .' || NEW.'.Sql::id($declaration->key($alias)).'::text',
                    $state->because,
                    $state->instead,
                )."\n  END IF;",
            '  RETURN NEW;',
        ]);
    }

    /** @return list<string> */
    private function triggers(Declaration $declaration): array
    {
        $view = Sql::id($declaration->previousModel->view);
        $primary = Sql::id($declaration->table((string) $declaration->primaryCurrentModel->alias));
        $trigger = fn (string $name, string $when) => 'CREATE TRIGGER '.Sql::id($declaration->object($name)).' '.$when
            .' FOR EACH ROW EXECUTE FUNCTION '.Sql::id($declaration->object($name)).'()';

        $triggers = [
            $trigger('insert', 'INSTEAD OF INSERT ON '.$view),
            $trigger('update', 'INSTEAD OF UPDATE ON '.$view),
            $trigger('delete', 'INSTEAD OF DELETE ON '.$view),
            $trigger('primary_insert', 'AFTER INSERT ON '.$primary),
            $trigger('primary_delete', 'BEFORE DELETE ON '.$primary),
        ];

        foreach ($declaration->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->alias !== null) {
                $triggers[] = $trigger('refuse_'.$state->name, 'BEFORE INSERT OR UPDATE ON '.Sql::id($declaration->table($state->alias)));
            }
        }

        foreach ($declaration->designated() as $dependent) {
            $triggers[] = $trigger('designate_'.$dependent->alias, 'AFTER INSERT ON '.Sql::id($declaration->table((string) $dependent->alias)));
        }

        return $triggers;
    }

    private function updateTable(Declaration $declaration, string $alias, string $key, bool $mustExist): string
    {
        $writes = $declaration->writesTo($alias);

        if ($writes === []) {
            return '';
        }

        $statement = '  IF '.Sql::changed($this->attributeColumns($writes))." THEN\n    "
            .$this->update($declaration, $alias, $writes, $key);

        if ($mustExist) {
            $statement .= "\n    GET DIAGNOSTICS affected_rows = ROW_COUNT;\n    IF affected_rows <> 1 THEN\n      "
                .$this->missing($declaration, $declaration->table($alias))."\n    END IF;";
        }

        return $statement."\n  END IF;";
    }

    private function updateDependent(Declaration $declaration, DependentCurrentModel $dependent, string $primaryKey): string
    {
        $alias = (string) $dependent->alias;
        $writes = $declaration->writesTo($alias);

        if ($writes === []) {
            return '';
        }

        $columns = $this->attributeColumns($writes);
        $previous = $declaration->previousModel;
        $table = $declaration->table($alias);
        $refused = Sql::literal("Compatibility map '{$declaration->name}' refused a change to ".implode(', ', $columns)." on {$previous->view} record ")
            .' || OLD.'.Sql::id((string) $previous->key).'::text';
        $cases = [];

        if ($dependent->designation !== null) {
            $designation = $previous->table.'.'.$dependent->designation;
            $cases['EXISTS (SELECT 1 FROM '.Sql::id($table).' WHERE '.Sql::id($declaration->key($alias)).' = previous_row.'.Sql::id($dependent->designation).')'] = Sql::refuse(
                $refused.' || '.Sql::literal(": its designated {$table} row is not one the previous model reads"),
                "The record reads only the {$table} row that {$designation} names, and that row no longer matches ({$dependent->readableWhen}) or belongs to another record.",
                "Make that {$table} row match again through its own model, or repair {$designation} by hand.",
            );
        }

        $cases['('.$this->createdWhen($declaration, $dependent).')'] = $this->insert($table, $this->dependentValues($declaration, $dependent, $primaryKey), $declaration->key($alias), 'key_'.$alias)
            .$this->designate($declaration, $dependent, 'OLD.'.Sql::id((string) $previous->key), "\n        ");
        $branches = '';

        foreach ($cases as $condition => $statement) {
            $branches .= ($branches === '' ? '      IF ' : "\n      ELSIF ").$condition." THEN\n        ".$statement;
        }

        return '  IF '.Sql::changed($columns)." THEN\n"
            .'    SELECT '.$alias.'.'.Sql::id($declaration->key($alias)).' INTO key_'.$alias.' '.$declaration->locate($dependent, $primaryKey, 'previous_row').";\n"
            .'    IF key_'.$alias." IS NULL THEN\n"
            .$branches
            ."\n      ELSE\n        "
            .Sql::refuse(
                $refused,
                'The record has no '.$table.' row, and the compatibility map does not create one for this write.',
                'Create the '.$table.' row through its own model first, or declare createdWhen() for this dependent current model.',
            )
            ."\n      END IF;\n    ELSE\n      "
            .$this->update($declaration, $alias, $writes, 'key_'.$alias)
            ."\n    END IF;\n  END IF;";
    }

    private function designate(Declaration $declaration, DependentCurrentModel $dependent, string $previousKey, string $indent): string
    {
        if ($dependent->designation === null) {
            return '';
        }

        $previous = $declaration->previousModel;

        return $indent.'UPDATE '.Sql::id((string) $previous->table).' SET '.Sql::id($dependent->designation).' = key_'.$dependent->alias
            .' WHERE '.Sql::id((string) $previous->key).' = '.$previousKey.';';
    }

    /**
     * @param  list<array{attribute: Attribute, write: Write}>  $writes
     */
    private function update(Declaration $declaration, string $alias, array $writes, string $key): string
    {
        $sets = array_map(
            fn (array $write) => Sql::id($write['write']->column).' = CASE WHEN NEW.'.Sql::id($write['attribute']->column)
                .' IS DISTINCT FROM OLD.'.Sql::id($write['attribute']->column)
                .' THEN '.$this->value($declaration, $alias, $write['write'])
                .' ELSE '.$alias.'.'.Sql::id($write['write']->column).' END',
            $writes,
        );

        return 'UPDATE '.Sql::id($declaration->table($alias)).' AS '.$alias.' SET '.implode(', ', $sets)
            .' WHERE '.$alias.'.'.Sql::id($declaration->key($alias)).' = '.$key.';';
    }

    /**
     * @param  array<string, string>  $values
     */
    private function insert(string $table, array $values, string $returning, string $into): string
    {
        $returning = $returning === '*' ? '*' : Sql::id($returning);

        if ($values === []) {
            return 'INSERT INTO '.Sql::id($table).' DEFAULT VALUES RETURNING '.$returning.' INTO '.$into.';';
        }

        return 'INSERT INTO '.Sql::id($table).' ('.implode(', ', array_map(Sql::id(...), array_keys($values))).')'
            .' VALUES ('.implode(', ', $values).') RETURNING '.$returning.' INTO '.$into.';';
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function insertValues(Declaration $declaration, string $alias, array $extra, bool $mappedOnly = false): array
    {
        $values = [];

        foreach ($declaration->writesTo($alias) as $write) {
            if (! $mappedOnly || ! $write['attribute']->retained) {
                $values[$write['write']->column] = $this->value($declaration, $alias, $write['write']);
            }
        }

        foreach ($extra as $column => $expression) {
            if (array_key_exists($column, $values)) {
                throw InvalidCompatibilityMap::because($declaration->name, "{$alias}.{$column} is set both by an attribute and by fillWhenCreatedFromPrevious()");
            }

            $values[$column] = $this->value($declaration, $alias, new Write($alias, $column, $expression));
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function dependentValues(Declaration $declaration, DependentCurrentModel $dependent, string $primaryKey): array
    {
        return [$declaration->primaryForeignKey($dependent) => $primaryKey, ...$this->insertValues($declaration, (string) $dependent->alias, $dependent->fillWhenCreatedFromPrevious)];
    }

    private function createdWhen(Declaration $declaration, DependentCurrentModel $dependent): string
    {
        if ($dependent->createdWhen !== null) {
            return $dependent->createdWhen;
        }

        $columns = $this->attributeColumns($declaration->writesTo((string) $dependent->alias));

        return $columns === []
            ? 'true'
            : implode(' OR ', array_map(fn (string $column) => 'NEW.'.Sql::id($column).' IS NOT NULL', $columns));
    }

    /**
     * @param  list<array{attribute: Attribute, write: Write}>  $writes
     * @return list<string>
     */
    private function attributeColumns(array $writes): array
    {
        return array_values(array_unique(array_map(fn (array $write) => $write['attribute']->column, $writes)));
    }

    private function value(Declaration $declaration, string $alias, Write $write): string
    {
        $table = $declaration->table($alias);
        $type = $this->columns->type($table, $write->column);

        if ($type === null) {
            throw InvalidCompatibilityMap::because($declaration->name, "{$table} has no column {$write->column}");
        }

        return 'CAST(('.$write->expression.') AS '.$type.')';
    }

    /** @return list<string> */
    private function notNullChecks(Declaration $declaration, bool $insert): array
    {
        $previous = $declaration->previousModel;
        $record = $insert ? Sql::literal('a new record') : Sql::literal('record ').' || OLD.'.Sql::id((string) $previous->key).'::text';
        $checks = [];

        foreach ($declaration->attributes as $attribute) {
            if ($attribute->retained || ! $this->columns->notNull((string) $previous->table, $attribute->column)) {
                continue;
            }

            $column = Sql::id($attribute->column);
            $checks[] = '  IF NEW.'.$column.' IS NULL'.($insert ? '' : ' AND OLD.'.$column.' IS NOT NULL')." THEN\n    "
                .Sql::refuse(
                    Sql::literal("Compatibility map '{$declaration->name}' refused a null {$previous->view}.{$attribute->column} on ").' || '.$record,
                    "{$previous->table}.{$attribute->column} is NOT NULL, so the previous model never stores null.",
                    "Write a value to {$attribute->column}, or omit it from an insert to take its default.",
                )."\n  END IF;";
        }

        return $checks;
    }

    private function lockPrimary(Declaration $declaration): string
    {
        $primary = (string) $declaration->primaryCurrentModel->alias;

        return '  PERFORM 1 FROM '.Sql::id($declaration->table($primary))
            .' WHERE '.Sql::id($declaration->key($primary)).' = OLD.'.Sql::id((string) $declaration->previousModel->primaryForeignKey).' FOR UPDATE;';
    }

    private function conflict(Declaration $declaration): string
    {
        $previous = $declaration->previousModel;
        $primary = (string) $declaration->primaryCurrentModel->alias;
        $attributes = array_values(array_filter($declaration->attributes, fn (Attribute $attribute) => $declaration->dependent((string) $attribute->alias) === null));

        return '  IF EXISTS (SELECT 1 FROM '.Sql::id((string) $previous->table).' '.$previous->alias
            .' JOIN '.Sql::id($declaration->table($primary)).' '.$primary
            .' ON '.$primary.'.'.Sql::id($declaration->key($primary)).' = '.$previous->alias.'.'.Sql::id((string) $previous->primaryForeignKey)
            .' WHERE '.$previous->alias.'.'.Sql::id((string) $previous->key).' = OLD.'.Sql::id((string) $previous->key)
            .' AND ROW('.implode(', ', array_map(fn (Attribute $attribute) => $this->read($declaration, $attribute), $attributes)).')'
            .' IS DISTINCT FROM ROW('.implode(', ', array_map(fn (Attribute $attribute) => 'OLD.'.Sql::id($attribute->column), $attributes)).")) THEN\n    "
            .Sql::raise(
                '40001',
                Sql::literal("Compatibility map '{$declaration->name}' found a concurrent change to {$previous->view} record ")
                    .' || OLD.'.Sql::id((string) $previous->key).'::text || '.Sql::literal(" since this statement's snapshot"),
                "An INSTEAD OF trigger receives OLD from the statement's snapshot and cannot re-read the row, so writing would lose that change.",
                "Retry the transaction, or write through the {$previous->model} model: the event bridge locks the record before its statement.",
            )."\n  END IF;";
    }

    private function loadPrevious(Declaration $declaration): string
    {
        $previous = $declaration->previousModel;

        return '  SELECT * INTO previous_row FROM '.Sql::id((string) $previous->table)
            .' WHERE '.Sql::id((string) $previous->key).' = OLD.'.Sql::id((string) $previous->key)." FOR UPDATE;\n"
            ."  IF NOT FOUND THEN\n    ".$this->missing($declaration, (string) $previous->table)."\n  END IF;";
    }

    private function missing(Declaration $declaration, string $table): string
    {
        $previous = $declaration->previousModel;

        return Sql::refuse(
            Sql::literal("Compatibility map '{$declaration->name}' refused a write to {$previous->view} record ")
                .' || OLD.'.Sql::id((string) $previous->key).'::text || '.Sql::literal(": its {$table} row is missing"),
            'The record has nowhere to store the change, so the previous and current models have diverged.',
            "Run php artisan compatibility:check --map={$declaration->name} and repair the record it names.",
        );
    }

    private function applying(Declaration $declaration, bool $on = true): string
    {
        return "  PERFORM set_config('compatibility.applying', ".Sql::literal($on ? $declaration->name : '').', true);';
    }

    private function skipWhileApplying(Declaration $declaration, string $row): string
    {
        return "  IF current_setting('compatibility.applying', true) = ".Sql::literal($declaration->name)." THEN\n    RETURN {$row};\n  END IF;";
    }

    private function variables(Declaration $declaration): string
    {
        $lines = ['  previous_row '.Sql::id((string) $declaration->previousModel->table).'%ROWTYPE;', '  affected_rows integer;'];

        foreach ([$declaration->primaryCurrentModel, ...$declaration->dependentCurrentModels] as $current) {
            $alias = (string) $current->alias;
            $lines[] = '  key_'.$alias.' '.Sql::id($declaration->table($alias))
                .'.'.Sql::id($declaration->key($alias)).'%TYPE;';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $body
     */
    private function function(string $name, string $variables, array $body): string
    {
        return 'CREATE OR REPLACE FUNCTION '.Sql::id($name).'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = '.Sql::id($this->schema)
            .", pg_temp AS \$compatibility\$\n"
            .($variables === '' ? '' : "DECLARE\n".$variables)
            ."BEGIN\n".implode("\n", $body)."\nEND;\n\$compatibility\$";
    }

    private function validateColumns(Declaration $declaration): void
    {
        $previous = $declaration->previousModel;
        $table = (string) $previous->table;
        $columns = $this->columns->of($table);

        if ($columns === []) {
            throw InvalidCompatibilityMap::because($declaration->name, "the retained table {$table} does not exist");
        }

        $designations = array_map(fn (DependentCurrentModel $dependent) => (string) $dependent->designation, $declaration->designated());

        foreach ([(string) $previous->key, (string) $previous->primaryForeignKey, ...$designations] as $column) {
            if (! array_key_exists($column, $columns)) {
                throw InvalidCompatibilityMap::because($declaration->name, "{$table} has no column {$column}");
            }
        }

        foreach ($declaration->attributes as $attribute) {
            if ($attribute->retained && ! array_key_exists($attribute->column, $columns)) {
                throw InvalidCompatibilityMap::because($declaration->name, "retained attribute {$attribute->column} is not a column of {$table}");
            }

            foreach ($attribute->writesTo as $write) {
                if ($write->alias === $previous->alias && $write->column === $previous->primaryForeignKey) {
                    throw InvalidCompatibilityMap::because($declaration->name, "attribute {$attribute->column} writes the primary foreign key {$write->column}; declare it read-only");
                }

                if ($write->alias === $previous->alias && in_array($write->column, $designations, true)) {
                    throw InvalidCompatibilityMap::because($declaration->name, "attribute {$attribute->column} writes the designation column {$write->column}; the compatibility map maintains it");
                }
            }
        }

        foreach ($declaration->dependentCurrentModels as $dependent) {
            $dependentTable = $declaration->table((string) $dependent->alias);

            if ($this->columns->type($dependentTable, $declaration->primaryForeignKey($dependent)) === null) {
                throw InvalidCompatibilityMap::because($declaration->name, $dependentTable.' has no column '.$declaration->primaryForeignKey($dependent));
            }
        }
    }
}
