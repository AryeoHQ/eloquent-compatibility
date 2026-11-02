<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression as RawExpression;
use Illuminate\Support\Str;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class RecordWriter
{
    /**
     * @param  list<string>  $external
     */
    public function __construct(private ResolvedMap $map, private Connection $connection, private array $external = []) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(Model $legacy, array $values): int|string
    {
        if ($this->map->branches !== []) {
            $this->refuseRaw($values);
            $writer = new MassWriter($this->map, $this->connection);

            return $writer->transaction(function () use ($writer, $legacy, $values): int|string {
                $values = $this->evaluateRetained($legacy, null, $this->withRetainedDefaults($values));
                $branch = $this->map->branchFor(self::probe($legacy, $values));
                $writer->claim($values, $branch);
                $key = $this->through($branch)->insert($legacy, $values);
                $writer->claim($this->allocated($legacy, $branch, $key), $branch);

                return $key;
            });
        }

        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy, $values): int|string {
            [$stored, $retained, $states, $external] = $this->split($legacy, $values, null);
            $primary = $this->successor($this->map->primary->model);
            $table = $this->map->primary->table;

            if ($this->map->membership !== null) {
                $stored[$table][$this->map->membership->column] = $this->map->membership->alias;
            }

            $stored = $this->transition($legacy, null, [$table => $primary], array_intersect_key($states, [$table => true]), $stored);
            $this->assign($primary, $stored[$table] ?? []);
            $this->writeExternal($primary, $external);
            $this->save($legacy, $primary, fn () => $primary->save());
            $key = $this->identify($legacy, $primary);
            [$models, $existing, $missing] = $this->locate($primary, $stored + $states);
            $stored = $this->transition($legacy, $key, $models, array_diff_key($states, [$table => true]), $stored);
            $this->assignEach($models, $stored);
            $this->saveEach($legacy, $existing);
            $this->create($legacy, $key, $models, $missing, $stored, $states);
            (new RetainedRows($this->map, $this->connection))->upsert($this->map->branch ? $key : self::keyOf($primary), $retained);
            $this->readBack($legacy, $key, array_keys($values));
            $this->readBackExternal($legacy, $key, array_keys($external));

            return $key;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(Model $legacy, array $values, bool $deleting = false, bool $restoring = false): int
    {
        if ($this->map->branches !== []) {
            return (new MassWriter($this->map, $this->connection))->transaction(fn (): int => $this->updateBranch($legacy, $values, $deleting, $restoring));
        }

        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy, $values, $deleting, $restoring): int {
            $key = self::keyOf($legacy);
            $primary = $this->lock($legacy, $key);

            if ($primary === null) {
                return 0;
            }

            $original = $primary->getRawOriginal();
            [$stored, $retained, $states, $external] = $this->split($legacy, $values, $key);
            $retained = $this->guard($legacy, $primary, $key, $values, [], $retained);
            $this->retain($key, $primary, $retained, []);
            [$models, $existing, $missing] = $this->locate($primary, $stored + $states);
            $stored = $this->transition($legacy, $key, $models, $states, $stored);
            $this->assignEach($models, $stored);
            $touched = $this->writeExternal($primary, $external);
            $this->savePrimary($legacy, $primary, $original, $stored[$this->map->primary->table] ?? [], $deleting, $restoring, $touched);
            $this->saveEach($legacy, $existing);
            $this->create($legacy, $key, $models, $missing, $stored, $states);
            $after = $this->rekey($legacy, $primary, $key, $values);
            $this->readBack($legacy, $after, array_keys($values));
            $this->readBackExternal($legacy, $after, array_keys($external));
            self::rebind($legacy, $key, $after);

            return 1;
        });
    }

    /**
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $extra
     */
    public function increment(Model $legacy, array $amounts, array $extra): int
    {
        if ($this->map->branches !== []) {
            return (new MassWriter($this->map, $this->connection))->transaction(fn (): int => $this->incrementBranch($legacy, $amounts, $extra));
        }

        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy, $amounts, $extra): int {
            $key = self::keyOf($legacy);
            $primary = $this->lock($legacy, $key);

            if ($primary === null) {
                return 0;
            }

            $original = $primary->getRawOriginal();
            [$stored, $retained, $states, $external] = $this->split($legacy, $extra, $key);
            [$added, $retainedAmounts] = (new MassWriter($this->map, $this->connection))->amounts($amounts, $key);
            $retained = $this->guard($legacy, $primary, $key, $extra, $amounts, $retained);
            $this->retain($key, $primary, $retained, array_diff_key($retainedAmounts, $retained));
            [$models, $existing, $missing] = $this->locate($primary, $stored + $states + array_fill_keys(array_keys($added), []));
            $stored = $this->transition($legacy, $key, $models, $states, $stored);
            $this->assignEach($models, $stored);
            $this->addEach($models, $added, $stored);
            $touched = $this->writeExternal($primary, $external);
            $this->savePrimary($legacy, $primary, $original, $stored[$this->map->primary->table] ?? [], false, false, $touched);
            $this->saveEach($legacy, $existing);
            $this->create($legacy, $key, $models, $missing, $stored, $states);
            $after = $this->rekey($legacy, $primary, $key, $extra + $amounts);
            $this->readBack($legacy, $after, [...array_keys($amounts), ...array_keys($extra)]);
            $this->readBackExternal($legacy, $after, array_keys($external));
            self::rebind($legacy, $key, $after);

            return 1;
        });
    }

    public function delete(Model $legacy): int
    {
        if ($this->map->branches !== []) {
            return (new MassWriter($this->map, $this->connection))->transaction(fn (): int => $this->deleteBranch($legacy));
        }

        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy): int {
            $primary = $this->lock($legacy, self::keyOf($legacy));

            if ($primary === null) {
                return 0;
            }

            ExternalAttributes::resolveAll($legacy);
            $this->save($legacy, $primary, fn () => $primary->forceDelete());

            return 1;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateBranch(Model $legacy, array $values, bool $deleting, bool $restoring = false): int
    {
        $this->refuseRaw($values);
        $key = self::keyOf($legacy);
        [$current, $primary] = $this->holding($legacy, $key);

        if ($current === null || $primary === null) {
            return 0;
        }

        $values = $this->evaluateRetained($legacy, $key, $values);
        $before = $this->before($legacy, $key);
        $target = $this->map->branchFor(self::probe($legacy, array_replace($before, $values)));

        if ($target === $current) {
            (new MassWriter($this->map, $this->connection))->claim(array_intersect_key($values, $this->map->identifiers), $current);
            $updated = $this->through($current)->update($legacy, $values, $deleting, $restoring);
            $this->settle($legacy, $current, $primary, $key, $values, array_keys($values));

            return $updated;
        }

        $this->through($target)->split($legacy, $values, $key);
        $values = $this->through($current)->guard($legacy, $primary, $key, $values, [], $values);
        $moved = $this->rekey($legacy, null, $key, $values);
        $stored = (new BranchMove($this->map, $this->connection))->run($legacy, $primary, $target, $before, $values);
        $this->confirm($legacy, $target, $key, $values, $this->allocated($legacy, $target, $stored));
        $this->readBack($legacy, $moved, array_keys(array_intersect_key($before, $legacy->getAttributes() + $values)));
        $this->readBackExternal($legacy, $moved, [...array_keys($legacy->getAttributes()), ...array_keys($values)]);
        ExternalAttributes::refreshed($legacy, $moved);

        return 1;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function rekey(Model $legacy, null|Model $primary, int|string $key, array $values): int|string
    {
        $name = $legacy->getKeyName();
        $moved = match (true) {
            ! in_array($name, array_map(fn (int|string $column) => Str::afterLast((string) $column, '.'), array_keys($values)), true) => $key,
            $primary !== null => $this->identify($legacy, $primary),
            default => self::keyAfter($legacy, $key, $values),
        };

        if ($moved === $key) {
            return $key;
        }

        if ($this->map->branches !== [] && $this->map->retainedTable !== null) {
            $this->connection->table($this->map->retainedTable)->where((string) $this->map->retainedKey, $key)->update([(string) $this->map->retainedKey => $moved]);
        }

        return $moved;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function keyAfter(Model $legacy, int|string $key, array $values): int|string
    {
        $moved = $values[$legacy->getKeyName()] ?? null;

        return (is_int($moved) || is_string($moved)) && (string) $moved !== (string) $key ? $moved : $key;
    }

    private static function rebind(Model $legacy, int|string $key, int|string $after): void
    {
        if ($after !== $key) {
            ExternalAttributes::refreshed($legacy, $after);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function evaluateRetained(Model $legacy, null|int|string $key, array $values): array
    {
        return array_replace($values, (new MassWriter($this->map, $this->connection))->typed(array_diff_key($values, $this->map->identifiers, $this->map->attributes), $key));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withRetainedDefaults(array $values): array
    {
        if ($this->map->retainedTable === null) {
            return $values;
        }

        $defaults = [];

        foreach ((new ColumnCatalog($this->connection, throughSearchPath: true))->of($this->map->retainedTable) as $name => $column) {
            if ($column->default !== null && $name !== $this->map->retainedKey && ! array_key_exists($name, $values) && $this->map->legacyOnly([$name]) !== []) {
                $defaults[$name] = new RawExpression('CAST(('.$column->default.') AS '.$column->type.')');
            }
        }

        return $values + $defaults;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function refuseRaw(array $values): void
    {
        foreach ($values as $name => $value) {
            $name = (string) $name;

            if (array_key_exists($name, $this->map->identifiers) || (array_key_exists($name, $this->map->attributes) && ! $this->map->attributes[$name]->isExternal())) {
                MassWriter::refuseRaw($this->map, $name, $value);
            }
        }
    }

    /**
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $extra
     */
    private function incrementBranch(Model $legacy, array $amounts, array $extra): int
    {
        $this->refuseRaw($extra);
        MassWriter::refuseIdentifierAmounts($this->map, $amounts);
        $key = self::keyOf($legacy);
        [$current, $primary] = $this->holding($legacy, $key);

        if ($current === null || $primary === null) {
            return 0;
        }

        $extra = $this->evaluateRetained($legacy, $key, $extra);
        $added = array_diff_key($amounts, $extra);
        $sums = [];

        foreach (array_diff_key($added, $this->map->attributes) as $name => $amount) {
            $sums[$name] = new RawExpression(SqlFragments::identifier($this->map->table).'.'.SqlFragments::identifier($name).' + '.($amount + 0));
        }

        $exact = count($sums) === count($added);

        if ($exact) {
            $this->refuseIncrementMove($legacy, $key, $current, array_replace($this->before($legacy, $key), $extra, (new MassWriter($this->map, $this->connection))->typed($sums, $key)));
        }

        (new MassWriter($this->map, $this->connection))->claim(array_intersect_key($extra, $this->map->identifiers), $current);
        $incremented = $this->through($current)->increment($legacy, $amounts, $extra);
        $moved = $this->settle($legacy, $current, $primary, $key, $extra, [...array_keys($amounts), ...array_keys($extra)]);
        $after = $exact ? null : $this->connection->table($this->map->table)->where($legacy->getKeyName(), $moved)->first();

        if ($after !== null) {
            $this->refuseIncrementMove($legacy, $key, $current, (array) $after);
        }

        return $incremented;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function refuseIncrementMove(Model $legacy, int|string $key, ResolvedMap $current, array $after): void
    {
        $target = $this->map->branchFor(self::probe($legacy, $after));

        if ($target !== $current) {
            throw CompatibilityWriteRefused::unsupported($legacy::class, 'an increment that moves record '.$key.' to '.class_basename($target->primary->model), 'An increment adds to the values its record stores, and a move recreates the record through another successor model.');
        }
    }

    private function deleteBranch(Model $legacy): int
    {
        $key = self::keyOf($legacy);
        [$current] = $this->holding($legacy, $key);

        if ($current === null) {
            return 0;
        }

        $deleted = $this->through($current)->delete($legacy);

        if ($this->map->retainedTable !== null) {
            $this->connection->table($this->map->retainedTable)->where((string) $this->map->retainedKey, $key)->delete();
        }

        return $deleted;
    }

    /**
     * @return array{null|ResolvedMap, null|Model}
     */
    private function holding(Model $legacy, int|string $key): array
    {
        (new MassWriter($this->map, $this->connection))->lockIdentity($legacy->getKeyName(), $key);

        foreach ($this->map->branches as $branch) {
            $primary = $this->through($branch)->lock($legacy, $key);

            if ($primary !== null) {
                (new MassWriter($branch, $this->connection))->lockDependentsOf(self::keyOf($primary));

                return [$branch, $primary];
            }
        }

        return [null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function before(Model $legacy, int|string $key): array
    {
        $row = $this->connection->table($this->map->table)->where($legacy->getKeyName(), $key)->first();

        return $row === null ? throw new LogicException("{$this->map->table} serves no row {$key} for the successor row the write locked.") : (array) $row;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<int|string>  $names
     */
    private function settle(Model $legacy, ResolvedMap $branch, Model $primary, int|string $key, array $values, array $names): int|string
    {
        $this->confirm($legacy, $branch, $key, $values, $this->identifiersWhere($branch, $branch->primary->key, self::keyOf($primary)));
        $moved = $this->rekey($legacy, null, $key, $values);

        if ($moved !== $key) {
            $this->readBack($legacy, $moved, $names);
        }

        return $moved;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $stored
     */
    private function confirm(Model $legacy, ResolvedMap $branch, int|string $key, array $values, array $stored): void
    {
        (new MassWriter($this->map, $this->connection))->claim($stored, $branch);
        $written = $values[$legacy->getKeyName()] ?? $key;
        $kept = $stored[$legacy->getKeyName()] ?? null;

        if ((is_int($written) || is_string($written)) && (is_int($kept) || is_string($kept)) && $this->sameIdentity($branch, $legacy->getKeyName(), $written, $kept)) {
            return;
        }

        throw CompatibilityWriteRefused::unsupported($legacy::class, 'a successor listener that changes the id of record '.$key, class_basename($branch->primary->model).' stored id '.(is_scalar($kept) ? (string) $kept : 'none').' where the write gives '.(is_scalar($written) ? (string) $written : '').', and the retained values and the instance follow the id the write gives.');
    }

    private function sameIdentity(ResolvedMap $branch, string $name, int|string $written, int|string $kept): bool
    {
        if ((string) $written === (string) $kept) {
            return true;
        }

        $type = (new ColumnCatalog($this->connection, throughSearchPath: true))->type($branch->primary->table, (string) $branch->identifiers[$name]->column()) ?? 'text';

        return $this->connection->scalar("select cast(? as {$type}) = cast(? as {$type})", [(string) $written, (string) $kept]) === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function allocated(Model $legacy, ResolvedMap $branch, int|string $key): array
    {
        return $this->identifiersWhere($branch, (string) $branch->identifiers[$legacy->getKeyName()]->column(), $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function identifiersWhere(ResolvedMap $branch, string $column, int|string $value): array
    {
        $table = $branch->primary->table;
        $columns = array_map(fn (Identifier $identifier) => (string) $identifier->column(), $branch->identifiers);
        $row = (array) $this->connection->table($table)
            ->where($table.'.'.(string) $branch->membership?->column, $branch->membership?->alias)
            ->where($table.'.'.$column, $value)
            ->first(array_values($columns));

        return array_map(fn (string $column) => $row[$column] ?? null, $columns);
    }

    private function through(ResolvedMap $branch): self
    {
        return new self($branch, $this->connection, $this->map->externals());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function probe(Model $legacy, array $attributes): Model
    {
        return (clone $legacy)->setRawAttributes($attributes);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{array<string, array<string, mixed>>, array<string, mixed>, array<string, array<string, array{string, mixed}>>, array<string, mixed>}
     */
    private function split(Model $legacy, array $values, null|int|string $key): array
    {
        $stored = [];
        $retained = [];
        $states = [];
        $external = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;
            $attribute = $this->map->attributes[$name] ?? null;

            if (array_key_exists($name, $this->map->identifiers)) {
                MassWriter::refuseRaw($this->map, $name, $value);
                $stored[$this->map->primary->table][(string) $this->map->identifiers[$name]->column()] = $value;

                continue;
            }

            if ($attribute === null) {
                $retained[$name] = $value;

                continue;
            }

            if ($attribute->isExternal()) {
                $declined = $attribute->declinedBecause();

                if ($declined !== null) {
                    throw CompatibilityWriteRefused::declined($legacy::class, $name, $declined);
                }

                if ($value instanceof Expression || $value instanceof Closure) {
                    throw CompatibilityWriteRefused::rawExpression($legacy::class, $name, 'It is declared external(), so its value lives outside the database, and no SQL computes it.');
                }

                $external[$name] = $value;

                continue;
            }

            $readOnly = $attribute->readOnlyBecause();

            if ($key !== null && $readOnly !== null) {
                throw CompatibilityWriteRefused::readOnly($legacy::class, $name, $key, $readOnly);
            }

            MassWriter::refuseRaw($this->map, $name, $value);

            try {
                $value = $attribute->write($value);
            } catch (LogicException $exception) {
                throw CompatibilityWriteRefused::unstorable($legacy::class, $name, $exception->getMessage());
            }

            $column = $attribute->column();

            if ($column !== null && $attribute->runsTransitions()) {
                $states[$this->map->owners[$name]->table][$column] = [$name, $value];
            } elseif ($column !== null) {
                $stored[$this->map->owners[$name]->table][$column] = $value;
            }
        }

        return [$stored, $retained, $states, $external];
    }

    /**
     * @param  array<string, mixed>  $external
     */
    private function writeExternal(Model $primary, array $external): bool
    {
        foreach ($external as $name => $value) {
            $this->map->attributes[$name]->writeExternally($primary, $value);
        }

        return $external !== [];
    }

    /**
     * @param  array<string, Model>  $models
     * @param  array<string, array<string, array{string, mixed}>>  $states
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<string, array<string, mixed>>
     */
    private function transition(Model $legacy, null|int|string $key, array $models, array $states, array $stored): array
    {
        $transitions = new Transitions($this->connection);

        foreach ($states as $table => $columns) {
            $model = $models[$table] ?? $this->successor($this->ownerOf($table));

            if ($key !== null && $model->exists && $model->usesTimestamps()) {
                $this->assign($model, array_intersect_key($stored[$table] ?? [], [(string) $model->getUpdatedAtColumn() => true]));
            }

            foreach ($columns as $column => [$name, $value]) {
                if ($key !== null && $model->exists) {
                    $transitions->run($legacy, $key, $name, $model, $column, $value);

                    continue;
                }

                $transitions->initial($legacy, $name, $model, $column, $value);
                $stored[$table][$column] = $value;
            }
        }

        return $stored;
    }

    /**
     * @return class-string<Model>
     */
    private function ownerOf(string $table): string
    {
        foreach ($this->map->related as $related) {
            if ($related->table === $table) {
                return $related->model;
            }
        }

        return $this->map->primary->model;
    }

    /**
     * @param  array<string, Model>  $models
     * @param  array<string, array<string, mixed>>  $stored
     */
    private function assignEach(array $models, array $stored): void
    {
        foreach ($models as $table => $model) {
            if ($table !== $this->map->primary->table) {
                $this->assign($model, $stored[$table] ?? []);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $retained
     * @return array<string, mixed>
     */
    private function guard(Model $legacy, Model $primary, int|string $key, array $values, array $amounts, array $retained): array
    {
        if ($this->map->guards === []) {
            return $retained;
        }

        (new MassWriter($this->map, $this->connection))->lockDependentsOf(self::keyOf($primary));
        $before = $legacy->newModelQuery()->where($legacy->getKeyName(), $key)->first();

        if ($before === null) {
            return $retained;
        }

        $evaluated = $this->evaluate($legacy, $key, array_filter($retained, fn (mixed $value) => $value instanceof Expression));
        $values = array_replace($values, $evaluated);
        $retained = array_replace($retained, $evaluated);

        foreach ($amounts as $name => $amount) {
            $current = $before->getAttributes()[$name] ?? null;

            if (is_numeric($current) && ! array_key_exists($name, $values)) {
                $values[$name] = $current + $amount;
            }
        }

        $after = clone $before;
        $after->setRawAttributes(array_replace($before->getAttributes(), $values));

        foreach ($this->map->guards as $guard) {
            if ($guard->refuses($after, $before)) {
                throw CompatibilityWriteRefused::byGuard($legacy::class, $guard, $key);
            }
        }

        return $retained;
    }

    /**
     * @param  array<string, Expression>  $expressions
     * @return array<string, mixed>
     */
    private function evaluate(Model $legacy, null|int|string $key, array $expressions): array
    {
        if ($expressions === []) {
            return [];
        }

        $grammar = $this->connection->getQueryGrammar();
        $selects = [];

        foreach (array_values($expressions) as $index => $expression) {
            $selects[] = '('.$expression->getValue($grammar).') as '.$grammar->wrap('value_'.$index);
        }

        $row = $key === null
            ? (array) $this->connection->selectOne('select '.implode(', ', $selects))
            : (array) $this->connection->table($legacy->getTable())->where($legacy->getKeyName(), $key)->selectRaw(implode(', ', $selects))->first();

        return array_combine(array_keys($expressions), array_values($row));
    }

    /**
     * @param  list<int|string>  $names
     */
    private function readBack(Model $legacy, int|string $key, array $names): void
    {
        $names = array_values(array_filter($names, fn (int|string $name) => ! in_array((string) $name, $this->external, true) && ! $this->map->isExternal((string) $name)));

        if ($names === []) {
            return;
        }

        $row = (array) $this->connection->table($legacy->getTable())->where($legacy->getKeyName(), $key)->first(array_map(fn (int|string $name) => (string) $name, $names));

        if ($row !== []) {
            $legacy->setRawAttributes(array_replace($legacy->getAttributes(), $row));
        }
    }

    /**
     * @param  list<int|string>  $names
     */
    private function readBackExternal(Model $legacy, int|string $key, array $names): void
    {
        $read = [];

        foreach (array_intersect($this->map->externals(), $names) as $name) {
            $read[$name] = ExternalAttributes::values($this->map, $name, [$key], $this->connection->getName(), true)[$key] ?? null;
        }

        if ($read !== []) {
            $legacy->setRawAttributes(array_replace($legacy->getAttributes(), $read));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    private function retain(int|string $key, Model $primary, array $values, array $amounts): void
    {
        $retained = new RetainedRows($this->map, $this->connection);

        if ($amounts === [] && array_filter($values, fn (mixed $value) => $value instanceof Expression) === []) {
            $retained->upsert($this->map->branch ? $key : self::keyOf($primary), $values);

            return;
        }

        if ($this->map->branch) {
            $legacyKey = (new $this->map->model)->getKeyName();
            (new MassWriter($this->map, $this->connection))->retainByIdentity($this->connection->table($this->map->table)->where($legacyKey, $key)->select($legacyKey), $values, $amounts);

            return;
        }

        $key = $this->map->primary->table.'.'.$this->map->primary->key;
        $retained->upsertAll($this->connection->table($this->map->primary->table)->where($key, self::keyOf($primary))->select($key), $values, $amounts);
    }

    /**
     * @param  array<string, Model>  $models
     * @param  array<string, array<string, float|int>>  $added
     * @param  array<string, array<string, mixed>>  $stored
     */
    private function addEach(array $models, array $added, array $stored): void
    {
        $catalog = new ColumnCatalog($this->connection, throughSearchPath: true);
        $grammar = $this->connection->getQueryGrammar();
        $sums = [];
        $bindings = [];
        $targets = [];

        foreach ($added as $table => $columns) {
            foreach ($columns as $column => $amount) {
                $current = ($models[$table] ?? null)?->getAttributes()[$column] ?? null;

                if ($current === null || array_key_exists($column, $stored[$table] ?? [])) {
                    continue;
                }

                if (is_int($current) && is_int($amount)) {
                    $this->assign($models[$table], [$column => $current + $amount]);

                    continue;
                }

                $type = $catalog->type($table, $column) ?? 'numeric';
                $sums[] = 'cast(cast(? as '.$type.') + cast(? as numeric) as '.$type.') as '.$grammar->wrap('sum_'.count($targets));
                array_push($bindings, $current, $amount);
                $targets[] = [$table, $column];
            }
        }

        if ($targets === []) {
            return;
        }

        $sum = (array) $this->connection->selectOne('select '.implode(', ', $sums), $bindings);

        foreach ($targets as $index => [$table, $column]) {
            $this->assign($models[$table], [$column => $sum['sum_'.$index]]);
        }
    }

    private function lock(Model $legacy, int|string $key): null|Model
    {
        $query = $this->successor($this->map->primary->model)->newModelQuery()->where((string) $this->map->identifiers[$legacy->getKeyName()]->column(), $key);

        if ($this->map->membership !== null) {
            $query->where($this->map->membership->column, $this->map->membership->alias);
        }

        foreach (ViewCompiler::hidden($this->map) as $condition) {
            $query->whereRaw('NOT COALESCE(('.$condition.'), false)');
        }

        return $query->lockForUpdate()->first();
    }

    private function identify(Model $legacy, Model $primary): int|string
    {
        $columns = [];

        foreach ($this->map->identifiers as $name => $identifier) {
            $columns[$name] = (string) $identifier->column();
        }

        $identity = (array) $primary->newModelQuery()->whereKey($primary->getKey())->toBase()->first(array_values($columns));
        $primary->setRawAttributes(array_replace($primary->getAttributes(), $identity), true);
        $key = $identity[$columns[$legacy->getKeyName()]] ?? null;

        if (! is_int($key) && ! is_string($key)) {
            throw new LogicException("{$this->map->primary->table}.{$columns[$legacy->getKeyName()]} read back no key for the new ".class_basename($legacy).'.');
        }

        Applying::identified($legacy, $key);

        return $key;
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @return array{array<string, Model>, list<Model>, list<Related>}
     */
    private function locate(Model $primary, array $stored): array
    {
        $parents = [];
        $reached = [];
        $models = [$this->map->primary->table => $primary];
        $existing = [];
        $missing = [];

        foreach ($this->map->related as $related) {
            $parents[$related->table] = $related->parentTable;
        }

        foreach (array_keys($stored) as $table) {
            for ($reach = $table; array_key_exists($reach, $parents); $reach = $parents[$reach]) {
                $reached[$reach] = true;
            }
        }

        foreach ($this->map->related as $related) {
            if (! array_key_exists($related->table, $reached)) {
                continue;
            }

            $parent = $models[$related->parentTable] ?? null;
            $model = $parent === null ? null : $this->relation($related, $parent)->getQuery()->lockForUpdate()->first();

            if ($model === null) {
                $missing[] = $related;

                continue;
            }

            $models[$related->table] = $model;
            $existing[] = $model;
        }

        return [$models, $existing, $missing];
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $values
     */
    private function savePrimary(Model $legacy, Model $primary, array $original, array $values, bool $deleting, bool $restoring, bool $touched): void
    {
        $deletedAt = method_exists($primary, 'getDeletedAtColumn') ? (string) $primary->getDeletedAtColumn() : null;
        $trashed = $deletedAt !== null && ($primary->getAttributes()[$deletedAt] ?? null) !== null;
        $moves = method_exists($primary, 'restore') && $deletedAt !== null && array_key_exists($deletedAt, $values)
            && ($values[$deletedAt] === null ? $restoring || $trashed : $deleting || ! $trashed);

        if (! $moves) {
            $this->assign($primary, $values);

            if ($primary->isDirty()) {
                $this->keepTimestamp($primary, $original, $values);
            }

            $this->saveEach($legacy, [$primary], $touched);

            return;
        }

        if ($values[$deletedAt] === null) {
            $this->assign($primary, array_diff_key($values, [$deletedAt => true]));
            $this->keepTimestamp($primary, $original, $values);
            $this->save($legacy, $primary, fn () => $primary->restore());

            return;
        }

        $updatedAt = $primary->usesTimestamps() ? $primary->getUpdatedAtColumn() : null;

        if (array_diff_key($values, array_flip(array_filter([$deletedAt, $updatedAt], fn (null|string $column) => $column !== null))) !== [] || $touched) {
            $this->assign($primary, array_diff_key($values, [$deletedAt => true]));
            $this->saveEach($legacy, [$primary], $touched);
        }

        $this->save($legacy, $primary, fn () => $primary->delete());
        $stamped = array_filter(
            array_intersect_key($values, array_flip(array_filter([$deletedAt, $updatedAt], fn (null|string $column) => $column !== null))),
            fn (mixed $value, string $column) => $value !== ($primary->getAttributes()[$column] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($stamped !== []) {
            $primary->newQueryWithoutScopes()->toBase()->where($primary->getKeyName(), $primary->getKey())->update($stamped);
            $primary->setRawAttributes(array_replace($primary->getAttributes(), $stamped));
            $primary->syncOriginalAttributes(array_keys($stamped));
        }
    }

    /**
     * @param  array<string, Model>  $models
     * @param  list<Related>  $missing
     * @param  array<string, array<string, mixed>>  $stored
     * @param  array<string, array<string, array{string, mixed}>>  $states
     */
    private function create(Model $legacy, int|string $key, array $models, array $missing, array $stored, array $states): void
    {
        foreach ($missing as $related) {
            $values = $stored[$related->table] ?? [];

            if (array_filter($values, fn (mixed $value) => $value !== null) === []) {
                continue;
            }

            $parent = $models[$related->parentTable] ?? throw CompatibilityWriteRefused::noParentRow($legacy::class, $this->written($related, $values), $related->table, $related->parentTable);
            $relation = $this->relation($related, $parent);
            $created = $relation->getQuery()->lockForUpdate()->first();

            if ($created !== null) {
                $transitioned = array_intersect_key($states, [$related->table => true]);
                $this->transition($legacy, $key, [$related->table => $created], $transitioned, []);
                $this->assign($created, array_diff_key($values, $transitioned[$related->table] ?? []));
                $this->saveEach($legacy, [$created]);
                $models[$related->table] = $created;

                continue;
            }

            if (! $relation instanceof HasOneOrMany) {
                throw CompatibilityWriteRefused::noRelatedRow($legacy::class, $this->written($related, $values), $related->table, $related->parentTable);
            }

            $model = $relation->make();
            $this->assign($model, $values);
            $this->save($legacy, $model, fn () => $model->save());
            $models[$related->table] = $model;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function written(Related $related, array $values): array
    {
        return array_keys(array_filter($related->successor->attributes(), fn (Attribute $attribute) => array_key_exists((string) $attribute->column(), $values)));
    }

    /**
     * @param  list<Model>  $models
     */
    private function saveEach(Model $legacy, array $models, bool $touched = false): void
    {
        foreach ($models as $model) {
            if ($model->isDirty() || $touched) {
                $this->save($legacy, $model, fn () => $model->save());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $values
     */
    private function keepTimestamp(Model $model, array $original, array $values): void
    {
        $column = $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;

        if ($column === null || ! array_key_exists($column, $values) || $model->isDirty($column)) {
            return;
        }

        $model->setRawAttributes(array_replace($model->getAttributes(), [$column => $original[$column] ?? null]));
        $model->syncOriginalAttribute($column);
        $this->assign($model, [$column => $values[$column]]);
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function save(Model $legacy, Model $successor, Closure $write): void
    {
        if (Applying::run($legacy, $successor, $write) === false) {
            throw CompatibilityWriteRefused::bySuccessorListener($legacy::class, $successor);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function assign(Model $model, array $values): void
    {
        if ($values !== []) {
            $model->setRawAttributes(array_replace($model->getAttributes(), $values));
        }
    }

    /**
     * @return Relation<Model, Model, mixed>
     */
    private function relation(Related $related, Model $parent): Relation
    {
        $relation = ($related->relation)($parent);

        if (! $relation instanceof Relation) {
            throw new LogicException('The related() relation from '.class_basename($related->parent).' to '.class_basename($related->model).' returns no relation.');
        }

        $relation->getRelated()->setConnection($this->connection->getName());
        $query = $relation->getBaseQuery();
        $query->connection = $this->connection;
        $query->grammar = $this->connection->getQueryGrammar();
        $query->processor = $this->connection->getPostProcessor();

        return $relation;
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function successor(string $class): Model
    {
        return (new $class)->setConnection($this->connection->getName());
    }

    private static function keyOf(Model $model): int|string
    {
        $key = $model->getRawOriginal($model->getKeyName()) ?? $model->getKey();

        return is_int($key) || is_string($key) ? $key : throw new LogicException(class_basename($model).' has no key to write by.');
    }
}
