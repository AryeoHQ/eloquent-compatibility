<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression as RawExpression;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class RecordWriter
{
    private UnitOfWork $unit;

    public function __construct(private ResolvedMap $map, private Connection $connection)
    {
        $this->unit = new UnitOfWork(
            map: $map,
            connection: $connection,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(Model $legacy, array $values): int|string
    {
        Applying::unidentified($legacy);

        return $this->unit->transaction(function () use ($legacy, $values): int|string {
            [$mapped, $retained] = $this->split($legacy, $values, null);
            $supplied = $retained[$this->map->successorKey] ?? null;
            unset($retained[$this->map->successorKey]);
            $variant = $this->map->morphs === [] ? $this->map : $this->map->morphFor(self::probe($legacy, $this->evaluateRetained($legacy, null, $this->withRetainedDefaults($values))));
            $root = $this->unit->successor($variant->primary->model);

            if ($supplied !== null) {
                $this->refuseNamedRoot(
                    legacy: $legacy,
                    root: $supplied,
                );
                $root->setAttribute(
                    key: $variant->primary->key,
                    value: $supplied,
                );
            }

            $graph = new Staging(
                map: $variant,
                root: $root,
                models: [],
            );

            $touched = $this->unit->scopes() ? $this->unit->touched([$this->unit->links(
                graph: $graph,
                values: $mapped,
                lock: false,
            )]) : [];

            return $this->unit->scoped($touched, function () use ($legacy, $values, $mapped, $retained, $variant, $root, $graph, $touched): int|string {
                $graph = $this->unit->links(
                    graph: $graph,
                    values: $mapped,
                    lock: true,
                );
                $this->unit->refuseMoved(
                    scoped: $touched,
                    graphs: [$graph],
                );
                $original = $legacy->newInstance()->setRawAttributes(
                    attributes: [],
                    sync: true,
                );

                if ($variant->target !== null) {
                    $graph->relatedOrNew($variant->target->model);
                }

                $this->unit->assign(
                    graph: $graph,
                    record: $legacy,
                    original: $original,
                    values: $mapped,
                    amounts: [],
                    origin: Origin::Live,
                    creating: true,
                );
                $this->unit->initialize(
                    graph: $graph,
                    record: $legacy,
                    origin: Origin::Live,
                );
                $this->unit->save($graph, $legacy);
                $key = $this->identify(
                    legacy: $legacy,
                    root: $root,
                    retained: $retained,
                );
                $this->unit->act($graph, $legacy);
                $this->refuseRepointed($legacy, $graph, null);
                $served = (new RoundTrip($this->map, $this->connection))->check(
                    graph: $graph,
                    legacy: $legacy,
                    original: $original,
                    key: $key,
                    written: $mapped,
                    added: [],
                    origin: Origin::Live,
                );
                $this->refuseStoredMorph($legacy, null, $served, $variant, $graph);
                $this->readBack($legacy, $key, $served, array_keys($values));
                ExternalAttributes::refreshed($legacy, $key);

                return $key;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(Model $legacy, array $values, bool $deleting = false, bool $restoring = false, bool $touched = false): int
    {
        return $this->unit->transaction(function () use ($legacy, $values, $deleting, $restoring, $touched): int {
            $key = UnitOfWork::keyOf($legacy);
            $values = $this->untouched($legacy, $values, $touched);
            [$mapped, $retained] = $this->split($legacy, $values, $key);

            return $this->written($legacy, $key, $mapped, [], function (Staging $graph, Model $original, ResolvedMap $chosen) use ($legacy, $key, $values, $mapped, $retained, $deleting, $restoring): int {
                $retained = $this->guard($legacy, $key, $values, [], $retained, $chosen === $graph->map ? null : $chosen);
                $this->retain($key, $graph->root(), $retained, []);

                return $this->apply(
                    legacy: $legacy,
                    key: $key,
                    graph: $graph,
                    original: $original,
                    chosen: $chosen,
                    values: $values,
                    mapped: $mapped,
                    deleting: $deleting,
                    restoring: $restoring,
                );
            });
        });
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $mapped
     */
    public function apply(Model $legacy, int|string $key, Staging $graph, Model $original, ResolvedMap $chosen, array $values, array $mapped, bool $deleting = false, bool $restoring = false): int
    {
        [$graph, $mapped] = $this->moved($graph, $chosen, $original, $mapped);
        $this->unit->assign(
            graph: $graph,
            record: $legacy,
            original: $original,
            values: $mapped,
            amounts: [],
            origin: Origin::Live,
            creating: false,
            deleting: $deleting,
            restoring: $restoring,
        );

        return $this->finish($legacy, $key, $graph, $original, $values, $mapped, []);
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $mapped
     */
    public function catchUp(Model $legacy, int|string $key, Staging $graph, Model $original, array $mapped, RoundTrip $roundTrip): bool
    {
        $chosen = $this->chosen(
            legacy: $legacy,
            original: $original,
            graph: $graph,
            mapped: $mapped,
            added: [],
            byHeldTarget: true,
        );
        [$graph, $mapped] = $this->moved($graph, $chosen, $original, $mapped);
        $this->unit->assign(
            graph: $graph,
            record: $legacy,
            original: $original,
            values: $mapped,
            amounts: [],
            origin: Origin::Backfill,
            creating: false,
        );
        $changed = $graph->pending();
        $this->finish(
            legacy: $legacy,
            key: $key,
            graph: $graph,
            original: $original,
            values: $mapped,
            mapped: $mapped,
            added: [],
            origin: Origin::Backfill,
            roundTrip: $roundTrip,
        );

        return $changed;
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $mapped
     * @param  Closure(Model): void  $identify
     */
    public function backfill(Model $legacy, int|string $key, Staging $graph, array $mapped, RoundTrip $roundTrip, Closure $identify): int|string
    {
        $variant = $graph->map;
        $original = $legacy->newInstance()->setRawAttributes(
            attributes: [],
            sync: true,
        );

        if ($variant->target !== null) {
            $graph->relatedOrNew($variant->target->model);
        }

        $this->unit->assign(
            graph: $graph,
            record: $legacy,
            original: $original,
            values: $mapped,
            amounts: [],
            origin: Origin::Backfill,
            creating: true,
        );
        $this->unit->initialize(
            graph: $graph,
            record: $legacy,
            origin: Origin::Backfill,
        );
        $this->unit->save(
            graph: $graph,
            legacy: $legacy,
        );
        $root = $graph->root();
        $identify($root);
        $this->unit->act(
            graph: $graph,
            legacy: $legacy,
        );
        $this->refuseRepointed(
            legacy: $legacy,
            graph: $graph,
            key: $key,
        );
        $served = $roundTrip->check(
            graph: $graph,
            legacy: $legacy,
            original: $original,
            key: $key,
            written: $mapped,
            added: [],
            origin: Origin::Backfill,
        );
        $this->refuseStoredMorph(
            legacy: $legacy,
            key: $key,
            served: $served,
            held: $variant,
            graph: $graph,
        );

        return UnitOfWork::keyOf($root);
    }

    /**
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $extra
     */
    public function increment(Model $legacy, array $amounts, array $extra): int
    {
        return $this->unit->transaction(function () use ($legacy, $amounts, $extra): int {
            $key = UnitOfWork::keyOf($legacy);
            [$mapped, $retained] = $this->split($legacy, $extra, $key);
            [$added, $retainedAmounts] = $this->amounts($amounts);
            $added = array_diff_key($added, $mapped);

            return $this->written($legacy, $key, $mapped, $added, function (Staging $graph, Model $original) use ($legacy, $key, $amounts, $extra, $mapped, $retained, $added, $retainedAmounts): int {
                $retained = $this->guard($legacy, $key, $extra, $amounts, $retained);
                $this->retain($key, $graph->root(), $retained, array_diff_key($retainedAmounts, $retained));
                $this->unit->assign(
                    graph: $graph,
                    record: $legacy,
                    original: $original,
                    values: $mapped,
                    amounts: $added,
                    origin: Origin::Live,
                    creating: false,
                );

                return $this->finish(
                    legacy: $legacy,
                    key: $key,
                    graph: $graph,
                    original: $original,
                    values: array_key_exists(
                        key: $legacy->getKeyName(),
                        array: $amounts,
                    ) && is_int($key) ? [...$extra, $legacy->getKeyName() => $key + (int) $amounts[$legacy->getKeyName()]] : $extra,
                    mapped: $mapped,
                    added: $added,
                );
            });
        });
    }

    public function delete(Model $legacy): int
    {
        return $this->unit->transaction(function () use ($legacy): int {
            return $this->written($legacy, UnitOfWork::keyOf($legacy), [], [], function (Staging $graph) use ($legacy): int {
                ExternalAttributes::resolveAll($legacy);
                $root = $graph->root();
                $this->unit->run($legacy, $root, fn () => $root->forceDelete());

                return 1;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, float|int>  $added
     * @param  Closure(Staging<Model>, Model, ResolvedMap): int  $write
     */
    private function written(Model $legacy, int|string $key, array $mapped, array $added, Closure $write): int
    {
        $rootKey = $this->rootKeyOf($legacy, $key);

        if ($rootKey === null) {
            return 0;
        }

        $touched = [];

        if ($this->unit->scopes()) {
            $root = $this->unit->roots([$rootKey], lock: false)[$rootKey] ?? null;

            if ($root === null) {
                return 0;
            }

            $touched = $this->unit->touched($this->unit->graphs([[$this->variantOf($root), $root]], lock: false));
        }

        return $this->unit->scoped($touched, function () use ($legacy, $key, $rootKey, $mapped, $added, $touched, $write): int {
            $root = $this->unit->roots([$rootKey], lock: true)[$rootKey] ?? null;

            if ($root === null) {
                return 0;
            }

            $current = $this->variantOf($root);
            $graph = $this->unit->graphs([[$current, $root]], lock: true)[0];
            $this->unit->refuseMoved($touched, [$graph]);
            $original = $this->original($legacy, $key);

            if ($original === null) {
                return 0;
            }

            return $write($graph, $original, $this->chosen(
                legacy: $legacy,
                original: $original,
                graph: $graph,
                mapped: $mapped,
                added: $added,
            ));
        });
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $mapped
     * @param  array<string, float|int>  $added
     */
    public function finish(Model $legacy, int|string $key, Staging $graph, Model $original, array $values, array $mapped, array $added, Origin $origin = Origin::Live, null|RoundTrip $roundTrip = null): int
    {
        $this->unit->save($graph, $legacy);
        $this->unit->act($graph, $legacy);
        $this->refuseRepointed($legacy, $graph, $key);
        $after = self::keyAfter(
            legacy: $legacy,
            key: $key,
            values: $values,
        );
        $served = ($roundTrip ?? new RoundTrip($this->map, $this->connection))->check(
            graph: $graph,
            legacy: $legacy,
            original: $original,
            key: $after,
            written: $mapped,
            added: $added,
            origin: $origin,
        );
        $this->refuseStoredMorph($legacy, $key, $served, $graph->map, $graph, $original);

        if ($origin === Origin::Backfill) {
            return 1;
        }

        $this->readBack($legacy, $after, $served, [...array_keys($values), ...array_keys($added), ...($this->map->morphs === [] ? [] : array_values(array_filter(array_keys($legacy->getAttributes()), fn (string $name) => ! $this->map->isExternal($name))))]);

        if ($after !== $key) {
            ExternalAttributes::refreshed($legacy, $after);
        }

        return 1;
    }

    private function original(Model $legacy, int|string $key): null|Model
    {
        $row = $this->connection->table($this->map->table)->useWritePdo()->where($legacy->getKeyName(), $key)->first();

        if ($row === null) {
            return null;
        }

        $original = $legacy->newInstance([], true)->setRawAttributes((array) $row, true);
        ExternalAttributes::gather([$original], true);

        return $original;
    }

    private function rootKeyOf(Model $legacy, int|string $key): null|int|string
    {
        $retained = ViewCompiler::retainedTable($this->map);
        $root = $this->connection->table($retained)->useWritePdo()->where(column: $retained.'.'.$legacy->getKeyName(), operator: '=', value: $key)->value($retained.'.'.$this->map->successorKey);

        return is_int($root) || is_string($root) ? $root : null;
    }

    public function variantOf(Model $root): ResolvedMap
    {
        if ($this->map->morphs === []) {
            return $this->map;
        }

        return $this->map->morphHolding($root->getAttributes()[(string) $this->map->morph?->typeColumn] ?? null) ?? $this->map->morphs[array_key_first($this->map->morphs)];
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $mapped
     * @param  array<string, float|int>  $added
     */
    public function chosen(Model $legacy, Model $original, Staging $graph, array $mapped, array $added, bool $byHeldTarget = false): ResolvedMap
    {
        if ($this->map->morphs === [] || $added !== []) {
            return $graph->map;
        }

        $type = $graph->root()->getAttributes()[(string) $this->map->morph?->typeColumn] ?? null;
        $current = $this->map->morphHolding($type);
        $chosen = $this->map->morphFor(self::probe($legacy, array_replace($original->getAttributes(), $legacy->getAttributes(), $mapped)));
        $holds = $current !== null && $current->target !== null && $graph->model($current->target->table) !== null;

        if (! $this->writesTarget($mapped) && ! $holds) {
            return $graph->map;
        }

        if ($current === null) {
            $this->refuseUnsupplied($legacy, UnitOfWork::keyOf($original), $type, $chosen, $mapped);
        }

        if ($current !== null && ($byHeldTarget ? $current : $this->map->morphFor($original)) === $chosen) {
            return $graph->map;
        }

        return $chosen;
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $mapped
     * @return array{Staging<Model>, array<string, mixed>}
     */
    private function moved(Staging $graph, ResolvedMap $chosen, Model $original, array $mapped): array
    {
        $current = $graph->map;
        $holding = $this->map->morphs === [] ? $current : $this->map->morphHolding($graph->root()->getAttributes()[(string) $this->map->morph?->typeColumn] ?? null);

        if ($chosen === $current && ($holding !== null || ! $this->writesTarget($mapped))) {
            return [$graph, $mapped];
        }

        $models = [];

        foreach ($current->related as $related) {
            if ($related->morphType === null) {
                $models[$related->table] = $graph->model($related->table);
            }
        }

        $old = $current->target === null || $holding === null ? null : $graph->model($current->target->table);
        $moved = new Staging(
            map: $chosen,
            root: $graph->root(),
            models: $models,
        );
        $target = $chosen->target ?? throw new LogicException("Compatibility map '{$this->map->table}' moves a record into no relatedMorph() target.");
        $moved->relatedOrNew($target->model);
        $before = $original->getAttributes();

        foreach ($chosen->attributes as $name => $attribute) {
            if ($chosen->owners[$name] === $target->successor && ! array_key_exists($name, $mapped) && $attribute->isWritable()) {
                $mapped[$name] = $before[$name] ?? null;
            }
        }

        if ($holding !== null && $current !== $chosen) {
            $this->refuseUnfilled($current, $chosen, $mapped, $original);
        }

        if ($old !== null) {
            $moved->afterSave(function () use ($moved, $old, $current, $original): void {
                $root = $moved->root();
                $morph = $this->map->morph ?? throw new LogicException("Compatibility map '{$this->map->table}' declares no relatedMorph().");

                if ($this->connection->table($this->map->primary->table)->where($morph->typeColumn, $current->target->morphAlias)->where($morph->idColumn, $old->getKey())->exists()) {
                    return;
                }

                $pointer = $this->pointer($root);
                $this->unit->run($moved->root(), $old, fn () => $old->forceDelete());
                $deleted = $this->pointer($root);

                if ($deleted !== $pointer) {
                    throw CompatibilityWriteRefused::unsupported($this->map->model, 'a successor listener that repoints record '.UnitOfWork::keyOf($original), class_basename($this->map->primary->model).' stored '.$morph->typeColumn.' '.self::quoted($deleted[$morph->typeColumn] ?? null).' and '.$morph->idColumn.' '.self::quoted($deleted[$morph->idColumn] ?? null).' after the delete of the old '.class_basename($old).', where the move gives '.self::quoted($pointer[$morph->typeColumn] ?? null).' and '.self::quoted($pointer[$morph->idColumn] ?? null).', so the record would point at a target the move did not store.');
                }
            });
        }

        return [$moved, $mapped];
    }

    /**
     * @return array<string, mixed>
     */
    private function pointer(Model $root): array
    {
        $morph = $this->map->morph ?? throw new LogicException("Compatibility map '{$this->map->table}' declares no relatedMorph().");
        $table = $this->map->primary->table;

        return (array) $this->connection->table($table)->where($table.'.'.$this->map->primary->key, $root->getKey())->first([$morph->typeColumn, $morph->idColumn]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function writesTarget(array $values): bool
    {
        foreach ($values as $name => $value) {
            if ($value !== null && $this->map->isMorphed((string) $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function refuseUnfilled(ResolvedMap $from, ResolvedMap $to, array $values, Model $original): void
    {
        $related = $to->target ?? throw new LogicException("Compatibility map '{$this->map->table}' moves a record into no relatedMorph() target.");
        [$unfilled, $required] = $this->unsupplied(
            variant: $to,
            related: $related,
            values: $values,
        );

        if ($unfilled !== []) {
            throw CompatibilityWriteRefused::unsupported($this->map->model, 'a write that moves record '.UnitOfWork::keyOf($original).' from '.class_basename((string) $from->target?->model).' to '.class_basename($related->model).' without $'.implode(', $', $unfilled), 'The move keeps the values the view serves for the attributes of '.class_basename($related->model).', and '.implode(' and ', $required).' needs a value the write does not supply.');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function refuseUnsupplied(Model $legacy, int|string $key, mixed $type, ResolvedMap $variant, array $values): void
    {
        $related = $variant->target ?? throw new LogicException("Compatibility map '{$this->map->table}' moves a record into no relatedMorph() target.");
        [$unsupplied, $required] = $this->unsupplied(
            variant: $variant,
            related: $related,
            values: $values,
        );

        if ($unsupplied !== []) {
            $column = (string) $this->map->morph?->typeColumn;
            $stores = $type === null ? 'no '.$column : $column.' '.self::quoted($type);

            throw CompatibilityWriteRefused::unsupported($legacy::class, 'a write that moves record '.$key.', whose '.class_basename($this->map->primary->model).' stores '.$stores.', into '.class_basename($related->model).' without $'.implode(', $', $unsupplied), 'targets: declares no target for that '.$column.', so the view serves no value for the move to keep, and '.implode(' and ', $required).' needs a value.');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{list<string>, list<string>}
     */
    private function unsupplied(ResolvedMap $variant, Related $related, array $values): array
    {
        $columns = (new ColumnCatalog($this->connection, throughSearchPath: true))->of($related->table);
        $names = [];
        $required = [];

        foreach ($variant->attributes as $name => $attribute) {
            $column = $attribute->column();

            if ($variant->owners[$name] === $related->successor && $attribute->invertible() && $attribute->readOnlyBecause() === null && $column !== null && ($columns[$column] ?? null)?->needsAValue() === true && ($values[$name] ?? null) === null) {
                $names[] = $name;
                $required[] = $related->table.'.'.$column;
            }
        }

        return [$names, $required];
    }

    /**
     * @param  Staging<Model>  $graph
     */
    private function refuseRepointed(Model $legacy, Staging $graph, null|int|string $key): void
    {
        $morph = $this->map->morph;
        $related = $graph->map->target;

        if ($morph === null || $related === null) {
            return;
        }

        $target = $graph->model($related->table);

        if ($target === null) {
            return;
        }

        $expected = [$related->morphAlias, $target->getKey()];
        $pointer = array_values($this->pointer($graph->root()) + [$morph->typeColumn => null, $morph->idColumn => null]);
        $record = $key === null ? 'a new record' : 'record '.$key;
        $successor = class_basename($this->map->primary->model);

        if (array_map(self::quoted(...), $pointer) !== array_map(self::quoted(...), $expected)) {
            throw CompatibilityWriteRefused::unsupported($legacy::class, 'a successor listener that repoints '.$record, $successor.' stored '.$morph->typeColumn.' '.self::quoted($pointer[0]).' and '.$morph->idColumn.' '.self::quoted($pointer[1]).' where the write gives '.self::quoted($expected[0]).' and '.self::quoted($expected[1]).', so the record would point at a target the write did not store.');
        }

        if (! $this->unit->successor($related->model)->newQuery()->whereKey($target->getKey())->exists()) {
            throw CompatibilityWriteRefused::unsupported($legacy::class, 'a successor listener that removes the target of '.$record, $successor.' names '.class_basename($related->model).' '.self::quoted($target->getKey()).', and no such row remains for the record to read.');
        }
    }

    private static function quoted(mixed $value): string
    {
        return is_scalar($value) ? SqlFragments::literal((string) $value) : 'null';
    }

    /**
     * @param  null|array<string, mixed>  $served
     * @param  Staging<Model>  $graph
     */
    private function refuseStoredMorph(Model $legacy, null|int|string $key, null|array $served, ResolvedMap $held, Staging $graph, null|Model $original = null): void
    {
        if ($this->map->morphs === [] || $served === null || $held->target === null || $graph->model($held->target->table) === null) {
            return;
        }

        $chosen = $this->map->morphFor(self::probe($legacy, $served));

        if ($chosen !== $held && ($original === null || $original->getAttributes() === [] || $this->map->morphFor($original) !== $chosen)) {
            $target = class_basename($chosen->target->model);

            throw CompatibilityWriteRefused::unsupported($legacy::class, 'a write that stores '.($key === null ? 'a new record' : 'record '.$key).' in '.class_basename($held->target->model).' where relatedMorph() chooses '.$target, 'The view serves the record with values that the relatedMorph() choose: closure reads as a '.$target.', so the record would point at a target its choose: closure does not pick.');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function keyAfter(Model $legacy, int|string $key, array $values): int|string
    {
        $moved = $values[$legacy->getKeyName()] ?? null;

        return (is_int($moved) || is_string($moved)) && (string) $moved !== (string) $key ? $moved : $key;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function untouched(Model $legacy, array $values, bool $touched): array
    {
        $updatedAt = $legacy->usesTimestamps() ? $legacy->getUpdatedAtColumn() : null;

        if (! $touched || $updatedAt === null || ! array_key_exists($updatedAt, $values) || $this->map->legacyOnly([$updatedAt]) !== []) {
            return $values;
        }

        $changed = array_map(strval(...), array_keys(array_diff_key($values, [$updatedAt => true])));
        $legacyOnly = array_values(array_diff($this->map->legacyOnly($changed), [$legacy->getKeyName(), $this->map->successorKey]));

        if ($changed === [] || $legacyOnly !== $changed) {
            return $values;
        }

        unset($values[$updatedAt]);
        $legacy->setRawAttributes(array_replace($legacy->getAttributes(), [$updatedAt => $legacy->getRawOriginal($updatedAt)]));

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function evaluateRetained(Model $legacy, null|int|string $key, array $values): array
    {
        return array_replace($values, (new MassWriter(
            map: $this->map,
            connection: $this->connection,
        ))->typed(
            values: array_diff_key($values, $this->map->attributes),
            key: $key,
        ));
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
            if ($column->default !== null && $name !== $this->map->successorKey && ! array_key_exists(
                key: $name,
                array: $values,
            ) && $this->map->legacyOnly([$name]) !== []) {
                $defaults[$name] = new RawExpression('CAST(('.$column->default.') AS '.$column->type.')');
            }
        }

        return $values + $defaults;
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
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function split(Model $legacy, array $values, null|int|string $key): array
    {
        $mapped = [];
        $retained = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;
            $attribute = $this->map->attributes[$name] ?? null;

            if ($attribute === null && $name === $this->map->successorKey && $key !== null) {
                throw CompatibilityWriteRefused::readOnly(
                    model: $legacy::class,
                    attribute: $name,
                    key: $key,
                    because: 'The successor key binds the record to its '.class_basename($this->map->primary->model).', and a write that rebinds it would serve another root under the same legacy identity.',
                );
            }

            if ($attribute === null) {
                $retained[$name] = $value;

                continue;
            }

            if (MassWriter::computesInSql($value)) {
                throw CompatibilityWriteRefused::rawExpression($legacy::class, $name, $attribute->isExternal()
                    ? 'It is declared computedAs(), so its value is computed in PHP, and no SQL computes it.'
                    : ($attribute->conversions() !== [] ? 'Its conversions read stored values, and SQL written for the legacy value does not pass through them.' : 'It is stored in '.$this->map->owners[$name]->table.', and SQL written for the legacy table would read the columns of '.$this->map->owners[$name]->table.' instead.'));
            }

            $mapped[$name] = $value;
        }

        return [$mapped, $retained];
    }

    /**
     * @param  array<string, float|int|numeric-string>  $amounts
     * @return array{array<string, float|int>, array<string, float|int>}
     */
    private function amounts(array $amounts): array
    {
        $mapped = [];
        $retained = [];

        foreach ($amounts as $name => $amount) {
            $amount += 0;

            if ($name === $this->map->successorKey) {
                throw CompatibilityWriteRefused::readOnly(
                    model: $this->map->model,
                    attribute: $name,
                    key: null,
                    because: 'The successor key binds each record to its '.class_basename($this->map->primary->model).', and a write that changes it would serve another root under the same legacy identity.',
                );
            }

            if (array_key_exists(
                key: $name,
                array: $this->map->attributes,
            )) {
                $mapped[$name] = $amount;
            } else {
                $retained[$name] = $amount;
            }
        }

        return [$mapped, $retained];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $retained
     * @return array<string, mixed>
     */
    private function guard(Model $legacy, int|string $key, array $values, array $amounts, array $retained, null|ResolvedMap $target = null): array
    {
        if ($this->map->guards === []) {
            return $retained;
        }

        $before = $legacy->newModelQuery()->useWritePdo()->where($legacy->getKeyName(), $key)->first();

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

        $projected = $target === null ? [] : (new MassWriter(
            map: $target,
            connection: $this->connection,
        ))->served(
            values: $values,
            fixed: true,
        ) ?? [];
        $after = clone $before;
        $after->setRawAttributes(array_replace($before->getAttributes(), $projected, $values));

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
     * @param  null|array<string, mixed>  $served
     * @param  list<int|string>  $names
     */
    private function readBack(Model $legacy, int|string $key, null|array $served, array $names): void
    {
        $sql = [];
        $external = [];

        foreach ($names as $name) {
            $name = (string) $name;

            if ($this->map->isExternal($name)) {
                $external[] = $name;
            } elseif ($served !== null && array_key_exists($name, $served)) {
                $sql[$name] = $served[$name];
            }
        }

        foreach ($external as $name) {
            $sql[$name] = ExternalAttributes::values($this->map, $name, [$key], $this->connection->getName(), true)[$key] ?? null;
        }

        if ($sql !== []) {
            $legacy->setRawAttributes(array_replace($legacy->getAttributes(), $sql));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    private function retain(int|string $key, Model $root, array $values, array $amounts): void
    {
        $retained = new RetainedRows($this->map, $this->connection);

        if ($amounts === [] && array_filter($values, fn (mixed $value) => $value instanceof Expression) === []) {
            $retained->set(
                key: $key,
                values: $values,
            );

            return;
        }

        $primary = $this->map->primary->table.'.'.$this->map->primary->key;
        $retained->update(roots: $this->connection->table($this->map->primary->table)->where(column: $primary, operator: '=', value: UnitOfWork::keyOf($root))->select($primary), values: $values, amounts: $amounts);
    }

    private function refuseNamedRoot(Model $legacy, mixed $root): void
    {
        $table = $this->map->primary->table;

        if ($this->connection->table($table)->where(
            column: $table.'.'.$this->map->primary->key,
            operator: '=',
            value: $root,
        )->exists()) {
            throw CompatibilityWriteRefused::unsupported(
                model: $legacy::class,
                operation: "a create that names the existing {$this->map->primary->table} row ".(is_scalar($root) ? (string) $root : '')." as its {$this->map->successorKey}",
                because: 'A legacy create creates its own '.class_basename($this->map->primary->model).', and a successor key that names an existing one would bind a second record to it.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $retained
     */
    private function identify(Model $legacy, Model $root, array $retained): int|string
    {
        $key = (new RetainedRows(
            map: $this->map,
            connection: $this->connection,
        ))->insert(
            root: UnitOfWork::keyOf($root),
            values: $retained,
        );
        Applying::identified($legacy, $key);

        return $key;
    }
}
