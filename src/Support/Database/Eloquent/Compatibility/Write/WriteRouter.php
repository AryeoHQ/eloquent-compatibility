<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Events\NullDispatcher;
use ReflectionMethod;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use WeakMap;

/**
 * Routes every write of a mapped model's query builder. A write through a previous model is split into writes of
 * its current models; a write of a current model keeps the previous models in step and mirrors their events.
 * The database holds no trigger: a write that bypasses Eloquent reaches the compatibility view, which refuses it,
 * or a current table, where it skips the router.
 */
final class WriteRouter
{
    private const string PARENTAL = 'Tightenco\Parental\HasChildren';

    /** The model methods HasCompatibility overrides, and that no mapped model may declare again. */
    public const array HOOKS = ['setKeysForSaveQuery', 'getAttributesForInsert', 'newEloquentBuilder'];

    private readonly MirrorIndex $mirrorIndex;

    private readonly EventReplayer $eventReplayer;

    /** @var WeakMap<Model, true> */
    private WeakMap $inserting;

    /** @var WeakMap<Model, string> */
    private WeakMap $applying;

    /** @var WeakMap<Connection, Records> */
    private WeakMap $records;

    /** @var array<string, true> */
    private array $registered = [];

    private int $disabled = 0;

    public function __construct(private readonly CompatibilityMaps $compatibilityMaps)
    {
        $this->mirrorIndex = new MirrorIndex;
        $this->eventReplayer = new EventReplayer($this->mirrorIndex);
        $this->inserting = new WeakMap;
        $this->applying = new WeakMap;
        $this->records = new WeakMap;
    }

    /**
     * Registers a compatibility map, so writes of its models route through it. Registration reads no database.
     */
    public function register(EventMirror $mirror): void
    {
        $map = $mirror->compatibilityMap();
        $resolvedMap = ResolvedMap::of($map);

        if (array_key_exists($resolvedMap->name, $this->registered)) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, 'it is registered with the write router twice');
        }

        foreach ([(string) $resolvedMap->previousModel->model, ...array_keys($resolvedMap->currentModels())] as $model) {
            $this->validateModel($resolvedMap, $model);
        }

        $previousModel = (string) $resolvedMap->previousModel->model;

        foreach ($this->mirrorIndex->forModel($previousModel) as $member) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "{$previousModel} is already a model of compatibility map '{$member->map}'; a previous model belongs to one compatibility map and to no other role");
        }

        foreach ($resolvedMap->currentModels() as $model => $alias) {
            if ($this->mirrorIndex->previousOf($model) !== null) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} is the previous model of compatibility map '{$this->mirrorIndex->previousOf($model)->name}', so it cannot also be a current model");
            }

            $primary = $alias === $resolvedMap->primaryAlias();

            foreach ($this->mirrorIndex->forModel($model) as $member) {
                $other = $this->mirrorIndex->resolvedMap($member->map);

                if ($primary ? $other->dependent($member->alias) !== null : $member->alias === $other->primaryAlias()) {
                    [$primaryMap, $dependentMap] = $primary ? [$resolvedMap->name, $member->map] : [$member->map, $resolvedMap->name];

                    throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} is the primary current model of compatibility map '{$primaryMap}' and a dependent current model of compatibility map '{$dependentMap}', so the write router cannot lock its row in one order for both maps, and two writes through them can deadlock; map {$model} in one of those roles only");
                }
            }
        }

        $this->compatibilityMaps->register($map);
        $this->mirrorIndex->add($resolvedMap, $mirror);
        $this->registered[$resolvedMap->name] = true;
    }

    /**
     * Runs $callback without raising the other side's events. Writes are still split and kept in step.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function withoutMirroring(callable $callback): mixed
    {
        $this->disabled++;

        try {
            return $callback();
        } finally {
            $this->disabled--;
        }
    }

    public function mirroring(): bool
    {
        $dispatcher = Model::getEventDispatcher();

        return $this->disabled === 0 && $dispatcher !== null && ! $dispatcher instanceof NullDispatcher;
    }

    /**
     * Whether this builder's writes route through the write router.
     */
    public function routes(Builder $builder): bool
    {
        $model = $builder->getModel();

        return $this->mirrorIndex->previousOf($model::class) !== null || $this->currentMembers($builder) !== [];
    }

    /** @internal HasCompatibility marks a model whose own insert follows. */
    public function markInsert(Model $model): void
    {
        $this->inserting[$model] = true;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int|string> each inserted record's key
     */
    public function insert(Builder $builder, array $rows, bool $ignoreConflicts): array
    {
        $model = $builder->getModel();
        $source = isset($this->inserting[$model]) ? $model : null;
        unset($this->inserting[$model]);
        $connection = $this->connection($builder);
        $resolvedMap = $this->mirrorIndex->previousOf($model::class);

        if ($resolvedMap !== null) {
            $writer = new PreviousWriter($this, $resolvedMap, $this->recordsOf($connection), $source !== null);
            $keyColumn = (string) $resolvedMap->previousModel->keyColumn;

            return $connection->transaction(function () use ($connection, $writer, $keyColumn, $rows, $ignoreConflicts): array {
                $keys = [];

                foreach ($rows as $row) {
                    if ($ignoreConflicts) {
                        try {
                            $key = $connection->transaction(fn () => $writer->insert($row)[$keyColumn]);
                        } catch (UniqueConstraintViolationException) {
                            continue;
                        }
                    } else {
                        $key = $writer->insert($row)[$keyColumn];
                    }

                    $keys[] = is_int($key) || is_string($key) ? $key : (string) $key;
                }

                return $keys;
            });
        }

        $writer = $this->currentWriter($builder, $source);

        return $connection->transaction(fn () => $writer->insert($builder, $rows, $ignoreConflicts));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(Builder $builder, array $values, null|Model $source): int
    {
        $values = $this->unqualified($builder, $values);
        $resolvedMap = $this->mirrorIndex->previousOf($builder->getModel()::class);

        if ($resolvedMap !== null) {
            return $this->eachPrevious($builder, $resolvedMap, $source, fn (PreviousWriter $writer, int|string $key, Closure $matches) => $writer->update($key, fn () => $values, $matches));
        }

        $writer = $this->currentWriter($builder, $source);

        return $this->connection($builder)->transaction(fn () => $writer->update($builder, fn ($query) => $query->update($values), $values));
    }

    /**
     * @param  array<string, float|int>  $amounts  each column's amount, negative to decrement
     * @param  array<string, mixed>  $extra
     */
    public function increment(Builder $builder, array $amounts, array $extra, null|Model $source): int
    {
        $amounts = $this->unqualified($builder, $amounts);
        $extra = $this->unqualified($builder, $extra);
        $resolvedMap = $this->mirrorIndex->previousOf($builder->getModel()::class);

        if ($resolvedMap !== null) {
            return $this->eachPrevious($builder, $resolvedMap, $source, fn (PreviousWriter $writer, int|string $key, Closure $matches) => $writer->update($key, function (array $old) use ($amounts, $extra): array {
                $values = $extra;

                foreach ($amounts as $column => $amount) {
                    $values[$column] = Values::add($old[$column] ?? null, $amount);
                }

                return $values;
            }, $matches));
        }

        $writer = $this->currentWriter($builder, $source);

        return $this->connection($builder)->transaction(fn () => $writer->update($builder, fn ($query) => $query->incrementEach($amounts, $extra), $extra));
    }

    public function delete(Builder $builder, null|Model $source): int
    {
        $resolvedMap = $this->mirrorIndex->previousOf($builder->getModel()::class);

        if ($resolvedMap !== null) {
            return $this->eachPrevious($builder, $resolvedMap, $source, fn (PreviousWriter $writer, int|string $key, Closure $matches) => $writer->delete($key, $matches));
        }

        $writer = $this->currentWriter($builder, $source);

        return $this->connection($builder)->transaction(fn () => $writer->delete($builder));
    }

    /**
     * Refuses a set-based insert the router cannot follow row by row, where a row needs work beyond the statement.
     */
    public function refuseSetInsert(Builder $builder, string $method): void
    {
        $model = $builder->getModel();
        $resolvedMap = $this->mirrorIndex->previousOf($model::class);

        if ($resolvedMap !== null) {
            throw CompatibilityWriteRefused::unsupported($resolvedMap->name, $resolvedMap->previousModel->view, $method);
        }

        $members = $this->currentMembers($builder);

        if ($members !== [] && $this->currentWriter($builder, null)->insertNeedsRows()) {
            throw CompatibilityWriteRefused::unsupported($members[0]->map, $model->getTable(), $method);
        }
    }

    /**
     * @internal the previous writer marks each current model it writes, so that model's own write skips the work
     * the previous writer does itself
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public function applying(Model $model, string $map, Closure $write): mixed
    {
        $outer = $this->applying[$model] ?? null;
        $this->applying[$model] = $map;

        try {
            return $write();
        } finally {
            if ($outer === null) {
                unset($this->applying[$model]);
            } else {
                $this->applying[$model] = $outer;
            }
        }
    }

    public function applyingMap(Model $model): null|string
    {
        return $this->applying[$model] ?? null;
    }

    public function resolvedMap(string $map): ResolvedMap
    {
        return $this->mirrorIndex->resolvedMap($map);
    }

    public function eventReplayer(): EventReplayer
    {
        return $this->eventReplayer;
    }

    public function mirrorIndex(): MirrorIndex
    {
        return $this->mirrorIndex;
    }

    /**
     * Writes each record the builder matches. The writer locks each record before it writes it, and asks the
     * builder again whether the locked record still matches, as PostgreSQL re-checks an UPDATE's condition.
     *
     * @param  Closure(PreviousWriter, int|string, Closure(): bool): bool  $write
     */
    private function eachPrevious(Builder $builder, ResolvedMap $resolvedMap, null|Model $source, Closure $write): int
    {
        $connection = $this->connection($builder);
        $writer = new PreviousWriter($this, $resolvedMap, $this->recordsOf($connection), $source !== null);
        $qualified = $builder->getModel()->qualifyColumn((string) $resolvedMap->previousModel->keyColumn);

        return $connection->transaction(function () use ($builder, $qualified, $writer, $write): int {
            $count = 0;

            foreach ($this->recordsOf($this->connection($builder))->keys($builder->toBase()->clone()->pluck($qualified)->all()) as $key) {
                $matches = function () use ($builder, $qualified, $key): bool {
                    $query = $builder->toBase()->clone();
                    $query->limit = $query->offset = $query->orders = null;

                    return $query->where($qualified, $key)->exists();
                };

                if ($write($writer, $key, $matches)) {
                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * Strips the model's table from each column, as Eloquent qualifies the updated-at column it adds.
     *
     * @template TValue
     *
     * @param  array<string, TValue>  $values
     * @return array<string, TValue>
     */
    private function unqualified(Builder $builder, array $values): array
    {
        $prefix = $builder->getModel()->getTable().'.';
        $unqualified = [];

        foreach ($values as $column => $value) {
            $unqualified[str_starts_with((string) $column, $prefix) ? substr((string) $column, strlen($prefix)) : (string) $column] = $value;
        }

        return $unqualified;
    }

    private function currentWriter(Builder $builder, null|Model $source): CurrentWriter
    {
        return new CurrentWriter($this, $this->recordsOf($this->connection($builder)), $builder->getModel(), $this->currentMembers($builder), $source);
    }

    /**
     * @return list<MapMember>
     */
    private function currentMembers(Builder $builder): array
    {
        $model = $builder->getModel();

        return $this->mirrorIndex->forRelation((string) $this->connection($builder)->getName(), $model->getTable());
    }

    private function connection(Builder $builder): Connection
    {
        $connection = $builder->getConnection();

        if (! $connection instanceof Connection) {
            throw new \LogicException('The write router needs an '.Connection::class.'.');
        }

        return $connection;
    }

    private function recordsOf(Connection $connection): Records
    {
        return $this->records[$connection] ??= new Records($connection, new ColumnCatalog($connection));
    }

    private function validateModel(ResolvedMap $resolvedMap, string $model): void
    {
        if (in_array(self::PARENTAL, class_uses_recursive($model), true)) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} uses ".self::PARENTAL.', so its child classes write under their own class names and the write router would find no compatibility map for them; leave Parental models out of compatibility maps');
        }

        if (in_array(AsPivot::class, class_uses_recursive($model), true)) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} uses ".AsPivot::class.', as every Pivot does, and a relation writes its pivot rows through the query builder of the base query, which the write router never sees; map a plain Model over the pivot table instead');
        }

        if (! is_subclass_of($model, Compatible::class) || ! in_array(HasCompatibility::class, class_uses_recursive($model), true)) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} does not implement ".Compatible::class.' and use '.HasCompatibility::class.', so its writes never reach the write router; implement the interface and use the trait in the model');
        }

        foreach (self::HOOKS as $method) {
            $resolved = new ReflectionMethod($model, $method);
            $marking = new ReflectionMethod(HasCompatibility::class, $method);

            if ($resolved->getFileName() !== $marking->getFileName() || $resolved->getStartLine() !== $marking->getStartLine()) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$model} resolves {$method}() to {$resolved->getDeclaringClass()->getName()}::{$method}(), not to the method of ".HasCompatibility::class.', so the write router would miss its writes; remove that declaration'.($method === 'newEloquentBuilder' ? ', and name a custom builder that uses '.RoutesCompatibleWrites::class.' with the UseEloquentBuilder attribute instead' : ''));
            }
        }
    }
}
