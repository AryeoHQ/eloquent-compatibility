<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;
use LogicException;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\IsNotNull;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\ReadAs;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class Backfiller
{
    public const int CHUNK = 1000;

    private const string MARKER = 'compatibility backfill';

    private const int BINDINGS = 65535;

    private const string SOURCE = 'compatibility_source';

    private const string TARGET = 'compatibility_target';

    private const string KEY = 'compatibility_key';

    private ColumnCatalog $catalog;

    private string $key;

    private null|string $connectionName;

    public function __construct(private ResolvedMap $map, private ConnectionInterface $connection)
    {
        $legacy = new $map->model;
        $this->catalog = new ColumnCatalog($connection);
        $this->key = $legacy->getKeyName();
        $this->connectionName = $legacy->getConnectionName();
    }

    /**
     * @param  null|Closure(list<int|string>, int): void  $chunked
     * @return array{read: int, changed: int, watermark: array{key: null|int|string, updatedAt: string}}
     */
    public function run(int $chunk = self::CHUNK, null|string $since = null, null|Closure $chunked = null): array
    {
        $this->refuseUnwalkable($chunk);
        $previous = $this->remembered();

        if ($since !== null && $previous !== null && new DateTimeImmutable($since) > new DateTimeImmutable($previous['updatedAt'])) {
            throw new LogicException("--since takes a watermark no later than {$previous['updatedAt']}, which the last backfill of {$this->map->table} remembers, not ".(new DateTimeImmutable($since))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z').'; a later one skips the legacy rows updated in between.');
        }

        $updatedAt = $this->now('backfill');
        $this->track();
        [$read, $changed, $last] = $this->walk($chunk, $since === null ? null : $this->updatedSince(self::watermark($since)), $chunked);
        $watermark = ['key' => $since === null ? $last : $previous['key'] ?? null, 'updatedAt' => $updatedAt];
        $this->remember($watermark);

        return ['read' => $read, 'changed' => $changed, 'watermark' => $watermark];
    }

    /**
     * @param  array{key: null|int|string, updatedAt: string}  $watermark
     * @return array{int, int, array{key: null|int|string, updatedAt: string}}
     */
    public function catchUp(array $watermark): array
    {
        $this->refuseUnwalkable(self::CHUNK);
        $updatedAt = $this->now('install');
        $this->track();
        $updated = $this->updatedAt() === null ? null : $this->updatedSince($watermark['updatedAt']);
        $scope = $watermark['key'] === null || $updated === null ? null : fn (QueryBuilder $query) => $query->where($this->key, '>', $watermark['key'])->orWhere($updated);
        [$read, $changed, $last] = $this->walk(self::CHUNK, $scope, null);

        return [$changed + $this->deleteGone(), $read, ['key' => self::greater($watermark['key'], $last), 'updatedAt' => $updatedAt]];
    }

    /**
     * @param  array{key: null|int|string, updatedAt: string}  $watermark
     */
    public function refuseUnconvergeable(array $watermark): void
    {
        if ($this->updatedAt() === null) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'install', "{$this->map->table} has no updated_at column its legacy model writes, so install() cannot limit its catch-up to the rows written since the backfill, and would walk every legacy row while it holds the lock");
        }

        if ($watermark['key'] === null && $this->connection->table($this->map->table)->useWritePdo()->exists()) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'install', 'the watermark of the last backfill records no legacy key it walked, so install() would walk every legacy row while it holds the lock. Run php artisan compatibility:backfill '.$this->map->model.' without --since, then install()');
        }
    }

    public function refuseMisplaced(): void
    {
        $type = (string) $this->catalog->type($this->map->table, $this->key);
        $misplaced = [];
        $last = null;

        do {
            $query = $this->connection->table($this->map->table)->useWritePdo()->orderBy($this->key)->limit(self::CHUNK);

            if ($last !== null) {
                $query->where($this->key, '>', $last);
            }

            $rows = array_map(fn (object $row) => (array) $row, $query->get()->all());
            $chosen = [];

            foreach ($rows as $row) {
                $chosen[$this->chosen($row)][] = self::keyOf($row[$this->key] ?? null);
            }

            foreach ($chosen as $model => $keys) {
                $branch = $this->map->branches[$model] ?? $this->map->morphs[$model];
                $primary = SqlFragments::identifier($branch->primary->table);
                $target = $branch->target === null ? '' : ' AND EXISTS (SELECT FROM '.SqlFragments::identifier($branch->target->table).' WHERE '.implode(' AND ', ViewCompiler::conditions($branch->target, true)).')';
                $misplaced = [...$misplaced, ...array_column($this->connection->select(
                    "SELECT CAST(chosen.key AS {$type}) AS key FROM jsonb_array_elements_text(CAST(? AS jsonb)) AS chosen (key)"
                        ." WHERE NOT EXISTS (SELECT FROM {$primary} WHERE {$primary}.".SqlFragments::identifier((string) $branch->identifiers[$this->key]->column())." = CAST(chosen.key AS {$type})"
                        .($branch->membership === null ? '' : ' AND '.$branch->membership->of($branch->primary->table)).$target.')',
                    [json_encode($keys, JSON_THROW_ON_ERROR)],
                    false,
                ), 'key')];
            }

            $last = $rows === [] ? $last : self::keyOf(end($rows)[$this->key] ?? null);
        } while (count($rows) === self::CHUNK);

        if ($misplaced !== []) {
            sort($misplaced);

            throw InvalidCompatibilityMap::cannot($this->map->table, 'install', count($misplaced)." {$this->map->table} rows ".($this->map->morphs === [] ? 'sit in a successor their servedFrom() selector does not choose' : 'point at no target their relatedMorph() choose: closure picks')."; the first are {$this->key} ".implode(', ', array_slice($misplaced, 0, 5)).'. Run php artisan compatibility:backfill '.$this->map->model.', then install()');
        }
    }

    public static function rememberNow(string $table, null|string $comment, null|int|string $key): string
    {
        return "DO \$compatibility\$\nDECLARE\n  watermark text;\n  hidden bigint;\nBEGIN\n"
            ."  SELECT to_char(least(now(), min(xact_start) FILTER (WHERE backend_type = 'client backend')) AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'), count(*) FILTER (WHERE state IS NULL AND backend_type IS NULL)\n"
            ."    INTO watermark, hidden FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid();\n"
            ."  IF hidden > 0 THEN\n"
            ."    RAISE EXCEPTION 'pg_stat_activity hides from % whether % other sessions of this database hold a transaction open, so no watermark can start before a write they have not committed yet. Grant pg_read_all_stats to %, then uninstall again.', current_user, hidden, current_user;\n"
            ."  END IF;\n"
            ."  EXECUTE format('COMMENT ON TABLE %s IS %L', ".SqlFragments::literal($table).', CAST(json_build_object('
            .SqlFragments::literal('backfilled').', '.SqlFragments::literal(self::MARKER).', '
            .SqlFragments::literal('comment').', CAST('.($comment === null ? 'NULL' : SqlFragments::literal($comment)).' AS text), '
            .SqlFragments::literal('key').', CAST('.SqlFragments::literal(json_encode($key, JSON_THROW_ON_ERROR)).' AS json), '
            .SqlFragments::literal('updatedAt').", watermark) AS text));\n"
            ."END\n\$compatibility\$";
    }

    /**
     * @return null|array{key: null|int|string, updatedAt: string}
     */
    public function remembered(): null|array
    {
        $remembered = self::decoded(self::commentOn($this->connection, $this->map->table));

        if ($remembered === null || ! is_string($remembered['updatedAt'] ?? null)) {
            return null;
        }

        $key = $remembered['key'] ?? null;

        return ['key' => is_int($key) || is_string($key) ? $key : null, 'updatedAt' => $remembered['updatedAt']];
    }

    /**
     * @param  array{key: null|int|string, updatedAt: string}  $watermark
     */
    public function remember(array $watermark): void
    {
        $this->connection->statement('COMMENT ON TABLE '.SqlFragments::identifier($this->map->table).' IS '.SqlFragments::literal(json_encode([
            'backfilled' => self::MARKER,
            'comment' => self::comment($this->connection, $this->map->table),
            'key' => $watermark['key'],
            'updatedAt' => $watermark['updatedAt'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }

    /**
     * @return list<string>
     */
    public static function refill(string $table, string $key, string $type): array
    {
        $column = SqlFragments::identifier($key);

        return [
            self::create($table, $key, $type),
            'INSERT INTO '.SqlFragments::identifier(self::backfilledTable($table))." ({$column}) SELECT {$column} FROM ".SqlFragments::identifier($table).' ON CONFLICT DO NOTHING',
        ];
    }

    public function forget(): void
    {
        if ($this->tracked()) {
            $this->connection->statement('DROP TABLE '.SqlFragments::identifier(self::backfilledTable($this->map->table)));
        }
    }

    public static function comment(ConnectionInterface $connection, string $table): null|string
    {
        $comment = self::commentOn($connection, $table);
        $remembered = self::decoded($comment);

        if ($remembered !== null) {
            return is_string($remembered['comment'] ?? null) ? $remembered['comment'] : null;
        }

        return $comment;
    }

    public static function watermark(string $since): string
    {
        try {
            return (new DateTimeImmutable($since))->format('Y-m-d\TH:i:s.uP');
        } catch (Exception) {
            throw new LogicException("--since takes the watermark a backfill printed, such as 2026-11-02T13:00:00Z, not {$since}.");
        }
    }

    /**
     * @param  null|Closure(QueryBuilder): mixed  $scope
     * @param  null|Closure(list<int|string>, int): void  $chunked
     * @return array{int, int, null|int|string}
     */
    private function walk(int $chunk, null|Closure $scope, null|Closure $chunked): array
    {
        $read = 0;
        $changed = 0;
        $last = null;

        do {
            $rows = $this->connection->transaction(function () use ($chunk, $scope, $chunked, $last, &$changed): array {
                $zone = $this->inApplicationZone();
                $query = $this->connection->table($this->map->table)->useWritePdo()->orderBy($this->key)->limit($chunk);

                if ($scope !== null) {
                    $query->where($scope);
                }

                if ($last !== null) {
                    $query->where($this->key, '>', $last);
                }

                $rows = array_map(fn (object $row) => (array) $row, $query->get()->all());
                $keys = array_map(fn (array $row) => self::keyOf($row[$this->key] ?? null), $rows);

                if ($rows !== []) {
                    $written = $this->write($rows);
                    $changed += $written;
                    $this->record($keys);

                    if ($chunked !== null) {
                        $chunked($keys, $written);
                    }
                }

                $this->restoreZone($zone);

                return $rows;
            });

            $read += count($rows);
            $last = $rows === [] ? $last : self::keyOf(end($rows)[$this->key] ?? null);
        } while (count($rows) === $chunk);

        return [$read, $changed, $last];
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     */
    private function write(array $rows): int
    {
        if ($this->map->morphs !== []) {
            return $this->writeMorphs($rows);
        }

        if ($this->map->branches === []) {
            return $this->writeBranch($this->map, $rows);
        }

        $routed = [];

        foreach ($rows as $row) {
            $routed[$this->map->branchFor($this->probe($row))->primary->model][] = $row;
        }

        $changed = 0;

        foreach ($this->map->branches as $model => $branch) {
            $elsewhere = array_merge(...array_values(array_diff_key($routed, [$model => true])));

            if ($elsewhere !== []) {
                $changed += $this->moveOut($branch, $elsewhere);
            }
        }

        foreach ($this->map->branches as $model => $branch) {
            if (array_key_exists($model, $routed)) {
                $changed += $this->writeBranch($branch, $routed[$model]);
            }
        }

        return $changed;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     */
    private function writeMorphs(array $rows): int
    {
        $routed = [];

        foreach ($rows as $row) {
            $routed[$this->chosen($row)][] = $row;
        }

        $changed = 0;

        foreach ($this->map->morphs as $target => $variant) {
            if (array_key_exists($target, $routed)) {
                $changed += $this->writeMorph($variant, $routed[$target]);
            }
        }

        return $changed;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     */
    private function writeMorph(ResolvedMap $variant, array $rows): int
    {
        $morph = $variant->morph ?? throw new LogicException("Compatibility map '{$this->map->table}' backfills a relatedMorph() target of no relatedMorph().");
        $related = $variant->target ?? throw new LogicException("Compatibility map '{$this->map->table}' backfills no relatedMorph() target.");
        $primaryTable = $variant->primary->table;
        $stored = [];
        $external = [];

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            [$stored[$key], $external[$key]] = $this->split($variant, $row, $key);
        }

        $changed = $this->rekey($variant, $stored);
        $existing = $this->held($variant, array_keys($stored))->keyBy((string) $variant->identifiers[$this->key]->column());
        $targets = $this->targets($related, $morph->typeColumn, $morph->idColumn, $existing);
        $read = [];

        foreach ($variant->externals() as $name) {
            $owner = $variant->owners[$name];
            $owned = match ($owner) {
                $variant->primary => $existing,
                $related->successor => $targets,
                default => null,
            };

            if ($owned !== null) {
                $read[$name] = $owned->isEmpty() ? [] : $variant->attributes[$name]->readExternally($owned);
            }
        }

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            $primary = $existing[$key] ?? (new $variant->primary->model)->setConnection($this->connectionName);
            $type = $primary->getAttributes()[$morph->typeColumn] ?? null;
            $held = $primary->getAttributes()[$morph->idColumn] ?? null;
            $target = $type === $related->morphAlias ? $targets[is_scalar($held) ? (string) $held : ''] ?? null : null;
            $old = $primary->exists && $target === null ? $this->map->morphHolding($type) : null;
            $target ??= (new $related->model)->setConnection($this->connectionName);
            $target->setRawAttributes(array_replace($target->getAttributes(), $this->present($variant, $target, $stored[$key][$related->table])));
            $changed += $this->saveSuccessor($variant, $target, $external[$key], $read, $key, array_keys($stored[$key][$related->table]));
            $primary->setRawAttributes(array_replace($primary->getAttributes(), $this->present($variant, $primary, $stored[$key][$primaryTable]), [$morph->typeColumn => $related->morphAlias, $morph->idColumn => $target->getKey()]));
            $changed += $this->saveSuccessor($variant, $primary, $external[$key], $read, $key, array_keys($stored[$key][$primaryTable]));

            if ($old !== null && $old->target !== null) {
                $changed += $this->deleteTarget($old->target, $held, $key);
            }
        }

        foreach ($variant->related as $dependent) {
            if ($dependent !== $related) {
                $changed += $this->writeRelated($variant, $dependent, $stored);
            }
        }

        return $changed + $this->writeRelatedExternals($variant, $stored, $external);
    }

    /**
     * @param  array<int|string, array<string, array<string, mixed>>>  $stored
     * @param  array<int|string, array<string, mixed>>  $external
     */
    private function writeRelatedExternals(ResolvedMap $branch, array $stored, array $external): int
    {
        $owned = [];

        foreach ($branch->externals() as $name) {
            $owner = $branch->owners[$name];

            if ($owner !== $branch->primary && $owner !== $branch->target?->successor) {
                $owned[$owner->table][] = $name;
            }
        }

        if ($owned === []) {
            return 0;
        }

        $parents = [];
        $reached = [];
        $wanted = [];

        foreach ($branch->related as $related) {
            $parents[$related->table] = $related->parentTable;
        }

        foreach ($owned as $table => $names) {
            for ($reach = $table; array_key_exists($reach, $parents); $reach = $parents[$reach]) {
                $reached[$reach] = true;

                foreach ($external as $key => $values) {
                    if (array_filter(array_intersect_key($values, array_flip($names)), fn (mixed $value) => $value !== null) !== []) {
                        $wanted[$key][$reach] = true;
                    }
                }
            }
        }

        $models = [];

        foreach ($this->held($branch, array_keys($stored)) as $primary) {
            $models[self::keyOf($primary->getAttribute((string) $branch->identifiers[$this->key]->column()))] = [$branch->primary->table => $primary];
        }

        foreach ($branch->related as $related) {
            if (! array_key_exists($related->table, $reached)) {
                continue;
            }

            foreach ($models as $key => $held) {
                $parent = $held[$related->parentTable] ?? null;
                $relation = $parent === null ? null : ($related->relation)($parent);

                if ($relation !== null && ! $relation instanceof Relation) {
                    throw new LogicException('The related() relation from '.class_basename($related->parent).' to '.class_basename($related->model).' returns no relation.');
                }

                $model = $relation?->getQuery()->useWritePdo()->first();
                $wants = $wanted[$key][$related->table] ?? false;

                if ($model === null && $wants && $relation instanceof HasOneOrMany && array_key_exists($related->table, $owned)) {
                    $model = $relation->make();
                }

                if ($model === null && $wants) {
                    throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', "{$this->map->table} row {$this->key} {$key} holds external() values for {$related->table}, and the related() relation from {$related->parentTable} reads no {$related->table} row the backfill can create for it; its parent row is missing, or it is a BelongsTo");
                }

                if ($model instanceof Model) {
                    $models[$key][$related->table] = $model;
                }
            }
        }

        $changed = 0;

        foreach ($owned as $table => $names) {
            $located = array_filter(array_map(fn (array $held) => $held[$table] ?? null, $models));
            $first = reset($located);

            if ($first === false) {
                continue;
            }

            $existing = $first->newCollection(array_values(array_filter($located, fn (Model $model) => $model->exists)));
            $read = [];

            foreach ($names as $name) {
                $read[$name] = $existing->isEmpty() ? [] : $branch->attributes[$name]->readExternally($existing);
            }

            foreach ($located as $key => $model) {
                $changed += $this->saveExternals($branch, $model, array_intersect_key($external[$key] ?? [], array_flip($names)), $read, $key, array_keys($stored[$key][$table] ?? []));
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $external
     * @param  array<string, array<array-key, mixed>>  $read
     * @param  list<string>  $mapped
     */
    private function saveExternals(ResolvedMap $branch, Model $successor, array $external, array $read, int|string $key, array $mapped): int
    {
        $written = false;

        foreach ($external as $name => $value) {
            if (! $successor->exists || ! ExternalAttributes::same($value, $read[$name][$successor->getKey()] ?? null)) {
                $branch->attributes[$name]->writeExternally($successor, $value);
                $written = true;
            }
        }

        if (! $written && ! $successor->isDirty()) {
            return 0;
        }

        $this->stamp($successor, $mapped);

        if ($successor->save() === false) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', 'a '.class_basename($successor).' listener refused the save of the successor of '.$this->map->table.' row '.$this->key.' '.$key);
        }

        return 1;
    }

    /**
     * @param  Collection<array-key, Model>  $primaries
     * @return Collection<array-key, Model>
     */
    private function targets(Related $related, string $type, string $id, Collection $primaries): Collection
    {
        $keys = array_values(array_filter($primaries->map(fn (Model $primary) => $primary->getAttributes()[$type] === $related->morphAlias ? $primary->getAttributes()[$id] : null)->all(), fn (mixed $key) => $key !== null));
        $targets = (new $related->model)->newCollection();

        foreach (array_chunk($keys, self::BINDINGS - 2) as $batch) {
            $targets = $targets->concat((new $related->model)->setConnection($this->connectionName)->newQuery()->useWritePdo()->whereKey($batch)->get());
        }

        return $targets->keyBy(fn (Model $target) => self::keyOf($target->getKey()));
    }

    /**
     * @param  array<string, mixed>  $external
     * @param  array<string, array<array-key, mixed>>  $read
     * @param  list<string>  $mapped
     */
    private function saveSuccessor(ResolvedMap $variant, Model $successor, array $external, array $read, int|string $key, array $mapped): int
    {
        $written = false;

        foreach ($external as $name => $value) {
            if ($variant->owners[$name]->table === $successor->getTable() && (! $successor->exists || ! ExternalAttributes::same($value, $read[$name][$successor->getKey()] ?? null))) {
                $variant->attributes[$name]->writeExternally($successor, $value);
                $written = true;
            }
        }

        if ($successor->exists && ! $written && ! $successor->isDirty()) {
            return 0;
        }

        $this->stamp($successor, $mapped);

        if ($successor->save() === false) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', 'a '.class_basename($successor).' listener refused the save of the successor of '.$this->map->table.' row '.$this->key.' '.$key);
        }

        return 1;
    }

    private function deleteTarget(Related $old, mixed $id, int|string $key): int
    {
        if ($id === null || $this->connection->table($old->parentTable)->useWritePdo()->where((string) $old->morphType, $old->morphAlias)->where($old->parentKey, $id)->exists()) {
            return 0;
        }

        $target = (new $old->model)->setConnection($this->connectionName)->newQuery()->useWritePdo()->whereKey($id)->first();

        if ($target === null) {
            return 0;
        }

        if ($target->forceDelete() === false) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', 'a '.class_basename($target).' listener refused the delete that moves '.$this->map->table.' row '.$this->key.' '.$key.' to another relatedMorph() target');
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function chosen(array $row): string
    {
        return $this->map->morphs === [] ? $this->map->branchFor($this->probe($row))->primary->model : (string) $this->map->morphFor($this->probe($row))->target?->model;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     */
    private function writeBranch(ResolvedMap $branch, array $rows): int
    {
        $stored = [];
        $external = [];

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            [$stored[$key], $external[$key]] = $this->split($branch, $row, $key);
        }

        $changed = $this->rekey($branch, $stored);
        $changed += array_filter($branch->attributes, fn ($attribute, string $name) => $attribute->isExternal() && $branch->owners[$name] === $branch->primary, ARRAY_FILTER_USE_BOTH) === []
            ? $this->upsert($branch, $stored)
            : $this->saveEach($branch, $rows, $stored, $external);

        foreach ($branch->related as $related) {
            $changed += $this->writeRelated($branch, $related, $stored);
        }

        return $changed + $this->writeRelatedExternals($branch, $stored, $external);
    }

    /**
     * @param  non-empty-array<int|string, array<string, array<string, mixed>>>  $stored
     */
    private function rekey(ResolvedMap $branch, array $stored): int
    {
        $table = $branch->primary->table;
        $keyColumn = (string) $branch->identifiers[$this->key]->column();
        $target = SqlFragments::identifier(self::TARGET);
        $source = SqlFragments::identifier(self::SOURCE);
        $legacy = SqlFragments::identifier($this->map->table);
        $changed = 0;

        foreach ($branch->identifiers as $name => $identifier) {
            $other = $identifier->column();

            if ($name === $this->key || $other === null) {
                continue;
            }

            $types = $this->typesOf($table, [$keyColumn, $other]);
            $pairs = array_values(array_filter(array_map(fn (array $values) => [$values[$table][$keyColumn] ?? null, $values[$table][$other] ?? null], $stored), fn (array $pair) => $pair[0] !== null && $pair[1] !== null));

            foreach ($this->batches($pairs, 2) as $batch) {
                $changed += $this->connection->affectingStatement(
                    'UPDATE '.SqlFragments::identifier($table)." AS {$target} SET ".SqlFragments::identifier($keyColumn)." = {$source}.compatibility_key"
                        ."\nFROM (VALUES ".implode(', ', array_fill(0, count($batch), '(CAST(? AS '.$types[$keyColumn].'), CAST(? AS '.$types[$other].'))')).") AS {$source} (compatibility_key, compatibility_other)"
                        ."\nWHERE {$target}.".SqlFragments::identifier($other)." = {$source}.compatibility_other AND {$target}.".SqlFragments::identifier($keyColumn)." IS DISTINCT FROM {$source}.compatibility_key"
                        .($branch->membership === null ? '' : ' AND '.$branch->membership->of(self::TARGET))
                        ." AND NOT EXISTS (SELECT FROM {$legacy} WHERE {$legacy}.".SqlFragments::identifier($this->key)." = {$target}.".SqlFragments::identifier($keyColumn).')',
                    array_merge(...$batch),
                );
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{array<string, array<string, mixed>>, array<string, mixed>}
     */
    private function split(ResolvedMap $branch, array $row, int|string $key): array
    {
        $stored = [$branch->primary->table => []];
        $external = [];

        foreach ($branch->identifiers as $name => $identifier) {
            $stored[$branch->primary->table][(string) $identifier->column()] = $row[$name] ?? null;
        }

        foreach ($branch->related as $related) {
            $stored[$related->table] = [];
        }

        foreach ($branch->attributes as $name => $attribute) {
            if ($attribute->isExternal()) {
                $external[$name] = $row[$name] ?? null;

                continue;
            }

            $column = $attribute->column();
            $conversion = $attribute->conversions()[0] ?? null;

            if ($conversion instanceof ReadAs && $conversion->inverse === null) {
                continue;
            }

            try {
                $value = $attribute->write($row[$name] ?? null);
            } catch (LogicException $exception) {
                throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', "{$this->map->table} row {$this->key} {$key} holds a {$name} that attribute '{$name}' cannot store: {$exception->getMessage()}");
            }

            if ($column !== null) {
                $stored[$branch->owners[$name]->table][$column] = $value;
            }
        }

        if ($branch->membership !== null) {
            $stored[$branch->primary->table][$branch->membership->column] = $branch->membership->alias;
        }

        return [$stored, $external];
    }

    /**
     * @param  non-empty-array<int|string, array<string, array<string, mixed>>>  $stored
     */
    private function upsert(ResolvedMap $branch, array $stored): int
    {
        $table = $branch->primary->table;
        $successor = new $branch->primary->model;
        $columns = array_keys(reset($stored)[$table]);
        $conflict = [...($branch->membership === null ? [] : [$branch->membership->column]), (string) $branch->identifiers[$this->key]->column()];
        $generated = $successor->usesUniqueIds() ? array_values(array_diff($successor->uniqueIds(), $columns)) : [];
        [$created, $updated] = $this->stamps($successor, $columns);
        $stamp = $created === null && $updated === null ? [] : [$successor->freshTimestampString()];
        $inserted = [...$columns, ...$generated, ...array_filter([$created, $updated], is_string(...))];
        $types = $this->typesOf($table, $inserted);
        $changing = array_values(array_diff($columns, $conflict));
        $presence = $this->presence($branch, $table);
        $target = SqlFragments::identifier(self::TARGET);
        $action = $changing === [] ? 'DO NOTHING' : 'DO UPDATE SET '.implode(', ', [
            ...array_map(fn (string $column) => SqlFragments::identifier($column).' = '.$this->kept($column, $presence, $target, 'excluded'), $changing),
            ...($updated === null ? [] : [SqlFragments::identifier($updated).' = excluded.'.SqlFragments::identifier($updated)]),
        ])
            .' WHERE '.$this->distinct($changing, $types, $target, 'excluded', $presence);
        $rows = [];

        foreach ($stored as $values) {
            $rows[] = [...array_values($values[$table]), ...array_map(fn () => $successor->newUniqueId(), $generated), ...($created === null ? [] : $stamp), ...($updated === null ? [] : $stamp)];
        }

        $changed = 0;

        foreach ($this->batches($rows, count($inserted)) as $batch) {
            $changed += count($this->connection->select(
                'INSERT INTO '.SqlFragments::identifier($table)." AS {$target} (".implode(', ', array_map(SqlFragments::identifier(...), $inserted)).')'
                    .' VALUES '.implode(', ', array_fill(0, count($batch), '('.implode(', ', array_map(fn (string $column) => 'CAST(? AS '.$types[$column].')', $inserted)).')'))
                    .' ON CONFLICT ('.implode(', ', array_map(SqlFragments::identifier(...), $conflict)).') '.$action
                    .' RETURNING 1',
                array_merge(...$batch),
                false,
            ));
        }

        return $changed;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     * @param  array<int|string, array<string, array<string, mixed>>>  $stored
     * @param  array<int|string, array<string, mixed>>  $external
     */
    private function saveEach(ResolvedMap $branch, array $rows, array $stored, array $external): int
    {
        $table = $branch->primary->table;
        $column = (string) $branch->identifiers[$this->key]->column();
        $existing = $this->held($branch, array_keys($stored))->keyBy($column);
        $read = [];

        foreach ($branch->externals() as $name) {
            if ($branch->owners[$name] === $branch->primary) {
                $read[$name] = $existing->isEmpty() ? [] : $branch->attributes[$name]->readExternally($existing);
            }
        }

        $changed = 0;

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            $successor = $existing[$key] ?? (new $branch->primary->model)->setConnection($this->connectionName);
            $successor->setRawAttributes(array_replace($successor->getAttributes(), $this->present($branch, $successor, $stored[$key][$table])));
            $written = false;

            foreach (array_intersect_key($external[$key], $read) as $name => $value) {
                if (! $successor->exists || ! ExternalAttributes::same($value, $read[$name][$successor->getKey()] ?? null)) {
                    $branch->attributes[$name]->writeExternally($successor, $value);
                    $written = true;
                }
            }

            if ($successor->exists && ! $written && ! $successor->isDirty()) {
                continue;
            }

            $this->stamp($successor, array_keys($stored[$key][$table]));

            if ($successor->save() === false) {
                throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', 'a '.class_basename($successor).' listener refused the save of the successor of '.$this->map->table.' row '.$this->key.' '.$key);
            }

            $changed++;
        }

        return $changed;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows
     */
    private function moveOut(ResolvedMap $branch, array $rows): int
    {
        $column = (string) $branch->identifiers[$this->key]->column();
        $moved = 0;

        foreach ($this->held($branch, array_map(fn (array $row) => self::keyOf($row[$this->key] ?? null), $rows)) as $successor) {
            if ($successor->forceDelete() === false) {
                throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', 'a '.class_basename($successor).' listener refused the delete that moves '.$this->map->table.' row '.$this->key.' '.self::keyOf($successor->getAttribute($column)).' to another successor');
            }

            $moved++;
        }

        return $moved;
    }

    /**
     * @param  array<int|string, array<string, array<string, mixed>>>  $stored
     */
    private function writeRelated(ResolvedMap $branch, Related $related, array $stored): int
    {
        $table = $related->table;
        $columns = array_keys(reset($stored)[$table] ?? []);

        if ($columns === []) {
            return 0;
        }

        $successor = new $related->model;
        $alias = SqlFragments::identifier($table);
        $source = SqlFragments::identifier(self::SOURCE);
        $target = SqlFragments::identifier(self::TARGET);
        $relatedKey = SqlFragments::identifier($successor->getKeyName());
        $wrapped = array_map(SqlFragments::identifier(...), $columns);
        $presence = $this->presence($branch, $table);
        $fixed = array_diff_key($related->attributes, array_flip([...$columns, $related->relatedKey]));
        [$created, $updated] = $this->stamps($successor, [...$columns, ...array_map(strval(...), array_keys($fixed))]);
        $generated = ! $related->belongsTo && $successor->usesUniqueIds() ? array_values(array_diff($successor->uniqueIds(), $columns, array_keys($fixed), [$related->relatedKey])) : [];
        $identifier = (string) $branch->identifiers[$this->key]->column();
        $types = $this->typesOf($table, [$related->relatedKey, ...array_keys($fixed), ...$columns, ...$generated, ...array_filter([$created, $updated], is_string(...))]);
        $sourceColumns = [self::KEY, ...$columns, ...array_map(fn (string $column) => 'compatibility_new_'.$column, $generated)];
        $sourceTypes = [(string) $this->catalog->type($branch->primary->table, $identifier), ...array_map(fn (string $column) => $types[$column], $columns), ...array_map(fn (string $column) => $types[$column], $generated)];
        $set = $wrapped === [] ? 'false' : implode(' OR ', array_map(fn (string $column) => "{$source}.{$column} IS NOT NULL", $wrapped));
        $missing = "{$alias}.{$relatedKey} IS NULL AND ({$set})";
        $stamp = $successor->freshTimestampString();
        $rows = [];

        foreach ($stored as $key => $values) {
            $rows[] = [$key, ...array_values($values[$table]), ...array_map(fn () => $successor->newUniqueId(), $generated)];
        }

        $changed = 0;

        foreach ($this->batches($rows, count($sourceColumns) + 2) as $batch) {
            $from = '(VALUES '.implode(', ', array_fill(0, count($batch), '('.implode(', ', array_map(fn (string $type) => "CAST(? AS {$type})", $sourceTypes)).')')).") AS {$source} (".implode(', ', array_map(SqlFragments::identifier(...), $sourceColumns)).')'
                ."\nJOIN ".SqlFragments::identifier($branch->primary->table).' ON '.SqlFragments::identifier($branch->primary->table).'.'.SqlFragments::identifier($identifier).' = '.$source.'.'.SqlFragments::identifier(self::KEY)
                .($branch->membership === null ? '' : ' AND '.$branch->membership->of($branch->primary->table))
                .implode('', array_map(fn (string $lateral) => "\n".$lateral, ViewCompiler::laterals($branch, $table)));
            $bindings = array_merge(...$batch);

            $changed += $this->connection->affectingStatement(
                'UPDATE '.SqlFragments::identifier($table)." AS {$target} SET ".implode(', ', [...array_map(fn (string $column) => SqlFragments::identifier($column).' = '.$this->kept($column, $presence, $target, $source), $columns), ...($updated === null ? [] : [SqlFragments::identifier($updated).' = CAST(? AS '.$types[$updated].')'])])
                    ."\nFROM {$from}"
                    ."\nWHERE {$target}.{$relatedKey} = {$alias}.{$relatedKey} AND ".$this->distinct($columns, $types, $target, $source, $presence),
                [...($updated === null ? [] : [$stamp]), ...$bindings],
            );

            if (! $related->belongsTo) {
                $parent = SqlFragments::identifier($related->parentTable).'.'.SqlFragments::identifier($related->parentKey);
                $inserted = [$related->relatedKey, ...array_keys($fixed), ...$columns, ...$generated, ...array_filter([$created, $updated], is_string(...))];
                $selected = [
                    $parent,
                    ...array_map(fn (string $column) => 'CAST(? AS '.$types[$column].')', array_keys($fixed)),
                    ...array_map(fn (string $column) => "{$source}.{$column}", $wrapped),
                    ...array_map(fn (string $column) => $source.'.'.SqlFragments::identifier('compatibility_new_'.$column), $generated),
                    ...array_map(fn (string $column) => 'CAST(? AS '.$types[$column].')', array_filter([$created, $updated], is_string(...))),
                ];

                $changed += $this->connection->affectingStatement(
                    'INSERT INTO '.SqlFragments::identifier($table).' ('.implode(', ', array_map(SqlFragments::identifier(...), $inserted)).')'
                        ."\nSELECT ".implode(', ', $selected)
                        ."\nFROM {$from}"
                        ."\nWHERE {$missing} AND {$parent} IS NOT NULL",
                    [...array_values($fixed), ...array_fill(0, count(array_filter([$created, $updated], is_string(...))), $stamp), ...$bindings],
                );
            }

            $unreached = array_column($this->connection->select('SELECT '.$source.'.'.SqlFragments::identifier(self::KEY)." AS key FROM {$from}\nWHERE {$missing} ORDER BY 1 LIMIT 5", $bindings, false), 'key');

            if ($unreached !== []) {
                throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', "{$this->map->table} rows {$this->key} ".implode(', ', $unreached)." hold values for {$table}, and ".($related->belongsTo
                    ? "the related() relation from {$related->parentTable} reaches {$table} through a BelongsTo, which the backfill does not create"
                    : "the related() relation from {$related->parentTable} reads no {$table} row the backfill can create for them; its parent row is missing, or its conditions need withAttributes()"));
            }
        }

        return $changed;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $types
     * @param  list<string>  $presence
     */
    private function distinct(array $columns, array $types, string $before, string $after, array $presence = []): string
    {
        $side = fn (string $alias) => implode(', ', array_map(fn (string $column) => match (true) {
            in_array($column, $presence, true) => '('.$alias.'.'.SqlFragments::identifier($column).' IS NOT NULL)',
            $this->catalog->equatable($types[$column]) => $alias.'.'.SqlFragments::identifier($column),
            default => 'CAST('.$alias.'.'.SqlFragments::identifier($column).' AS text)',
        }, $columns));

        return '('.$side($before).') IS DISTINCT FROM ('.$side($after).')';
    }

    /**
     * @return list<string>
     */
    private function presence(ResolvedMap $branch, string $table): array
    {
        $columns = [];

        foreach ($branch->attributes as $name => $attribute) {
            $column = $attribute->column();

            if ($column !== null && ! $attribute->isExternal() && ($branch->owners[$name] ?? null)?->table === $table && ($attribute->conversions()[0] ?? null) instanceof IsNotNull) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function present(ResolvedMap $branch, Model $successor, array $values): array
    {
        foreach ($successor->exists ? $this->presence($branch, $successor->getTable()) : [] as $present) {
            if (($values[$present] ?? null) !== null && $successor->getRawOriginal($present) !== null) {
                $values[$present] = $successor->getRawOriginal($present);
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $mapped
     */
    private function stamp(Model $successor, array $mapped): void
    {
        [$created, $updated] = $this->stamps($successor, $mapped);
        $successor->timestamps = false;
        $now = $successor->freshTimestamp();

        if ($updated !== null && ! $successor->isDirty($updated)) {
            $successor->setAttribute($updated, $now);
        }

        if ($created !== null && ! $successor->exists && ! $successor->isDirty($created)) {
            $successor->setAttribute($created, $now);
        }
    }

    /**
     * @param  list<string>  $presence
     */
    private function kept(string $column, array $presence, string $before, string $after): string
    {
        $wrapped = SqlFragments::identifier($column);

        return in_array($column, $presence, true)
            ? "CASE WHEN {$before}.{$wrapped} IS NOT NULL AND {$after}.{$wrapped} IS NOT NULL THEN {$before}.{$wrapped} ELSE {$after}.{$wrapped} END"
            : "{$after}.{$wrapped}";
    }

    private function deleteGone(): int
    {
        $legacy = SqlFragments::identifier($this->map->table);
        $backfilled = SqlFragments::identifier(self::backfilledTable($this->map->table));
        $key = SqlFragments::identifier($this->key);
        $deletes = [];

        foreach (array_values($this->map->branches === [] ? [$this->map] : $this->map->branches) as $index => $branch) {
            $primary = SqlFragments::identifier($branch->primary->table);
            $deletes[] = "compatibility_deleted_{$index} AS (DELETE FROM {$primary} USING compatibility_gone WHERE {$primary}.".SqlFragments::identifier((string) $branch->identifiers[$this->key]->column())." = compatibility_gone.{$key}"
                .($branch->membership === null ? '' : ' AND '.$branch->membership->of($branch->primary->table))
                .implode('', array_map(fn (string $condition) => ' AND NOT COALESCE(('.$condition.'), false)', ViewCompiler::hidden($branch)))
                .' RETURNING 1)';
        }

        $deleted = $this->connection->scalar(
            "WITH compatibility_gone AS (SELECT backfilled.{$key} FROM {$backfilled} AS backfilled WHERE NOT EXISTS (SELECT FROM {$legacy} WHERE {$legacy}.{$key} = backfilled.{$key})),\n"
                .implode(",\n", $deletes).",\n"
                ."compatibility_forgotten AS (DELETE FROM {$backfilled} AS backfilled USING compatibility_gone WHERE backfilled.{$key} = compatibility_gone.{$key})\n"
                .'SELECT '.implode(' + ', array_map(fn (int $index) => "(SELECT count(*) FROM compatibility_deleted_{$index})", array_keys($deletes))),
            [],
            false,
        );

        return is_int($deleted) ? $deleted : 0;
    }

    private function track(): void
    {
        if (! $this->tracked()) {
            $this->connection->statement(self::create($this->map->table, $this->key, $this->catalog->type($this->map->table, $this->key) ?? throw InvalidCompatibilityMap::because($this->map->table, "{$this->map->table} has no column {$this->key}")));
        }
    }

    private function tracked(): bool
    {
        return $this->connection->scalar("select to_regclass(quote_ident(current_schema()) || '.' || ?) is not null", [SqlFragments::identifier(self::backfilledTable($this->map->table))], false) === true;
    }

    /**
     * @param  list<int|string>  $keys
     */
    private function record(array $keys): void
    {
        $type = (string) $this->catalog->type($this->map->table, $this->key);

        foreach ($this->batches(array_map(fn (int|string $key) => [$key], $keys), 1) as $batch) {
            $this->connection->insert('INSERT INTO '.SqlFragments::identifier(self::backfilledTable($this->map->table)).' VALUES '.implode(', ', array_fill(0, count($batch), "(CAST(? AS {$type}))")).' ON CONFLICT DO NOTHING', array_merge(...$batch));
        }
    }

    private static function backfilledTable(string $table): string
    {
        return SqlFragments::objectName($table, $table.'_backfilled');
    }

    private static function create(string $table, string $key, string $type): string
    {
        return 'CREATE TABLE IF NOT EXISTS '.SqlFragments::identifier(self::backfilledTable($table)).' ('.SqlFragments::identifier($key)." {$type} PRIMARY KEY)";
    }

    /**
     * @param  list<int|string>  $keys
     * @return Collection<int, Model>
     */
    private function held(ResolvedMap $branch, array $keys): Collection
    {
        $held = (new $branch->primary->model)->newCollection();

        foreach (array_chunk($keys, self::BINDINGS - 2) as $batch) {
            $query = (new $branch->primary->model)->setConnection($this->connectionName)->newModelQuery()->useWritePdo()->whereIn((string) $branch->identifiers[$this->key]->column(), $batch);

            if ($branch->membership !== null) {
                $query->where($branch->membership->column, $branch->membership->alias);
            }

            $held = $held->concat($query->get());
        }

        return $held;
    }

    /**
     * @param  list<string>  $columns
     * @return array{null|string, null|string}
     */
    private function stamps(Model $successor, array $columns): array
    {
        if (! $successor->usesTimestamps()) {
            return [null, null];
        }

        $created = $successor->getCreatedAtColumn();
        $updated = $successor->getUpdatedAtColumn();

        return [
            $created === null || in_array($created, $columns, true) ? null : $created,
            $updated === null || in_array($updated, $columns, true) ? null : $updated,
        ];
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private function typesOf(string $table, array $columns): array
    {
        $catalog = $this->catalog->of($table);

        if ($catalog === []) {
            throw InvalidCompatibilityMap::because($this->map->table, "the successor table {$table} does not exist");
        }

        $types = [];

        foreach ($columns as $column) {
            $types[$column] = array_key_exists($column, $catalog) ? $catalog[$column]->type : throw InvalidCompatibilityMap::because($this->map->table, "{$table} has no column {$column}, which the backfill writes; Compatibility::prepare() adds the identifier and discriminator columns");
        }

        return $types;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return list<non-empty-list<list<mixed>>>
     */
    private function batches(array $rows, int $width): array
    {
        return array_chunk($rows, max(1, intdiv(self::BINDINGS - 2, max(1, $width))));
    }

    private function refuseUnwalkable(int $chunk): void
    {
        if ($chunk < 1) {
            throw new LogicException("A backfill reads at least one row per chunk, not {$chunk}.");
        }

        $legacy = $this->catalog->of($this->map->table);

        if ($legacy === []) {
            throw InvalidCompatibilityMap::because($this->map->table, "the legacy table {$this->map->table} does not exist");
        }

        if (LegacyBuilder::serves(new $this->map->model)) {
            throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', "{$this->map->table} is already installed as a view, and the backfill walks the legacy table while it is still a table");
        }

        foreach ($this->map->branches === [] ? ($this->map->morphs === [] ? [$this->map] : $this->map->morphs) : $this->map->branches as $branch) {
            foreach ([...array_keys($branch->identifiers), ...array_keys(array_filter($branch->attributes, fn ($attribute) => $attribute->column() !== null || $attribute->isExternal()))] as $name) {
                if (! array_key_exists($name, $legacy)) {
                    throw InvalidCompatibilityMap::because($this->map->table, "{$this->map->table} has no column {$name}, so the backfill has no value to write for it");
                }
            }
        }
    }

    private function updatedAt(): null|string
    {
        $legacy = new $this->map->model;
        $column = $legacy->usesTimestamps() ? $legacy->getUpdatedAtColumn() : null;

        return $column !== null && array_key_exists($column, $this->catalog->of($this->map->table)) ? $column : null;
    }

    /**
     * @return Closure(QueryBuilder): mixed
     */
    private function updatedSince(string $watermark): Closure
    {
        $column = $this->updatedAt() ?? throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', "{$this->map->table} has no updated_at column of ".class_basename($this->map->model).', so no watermark limits a catch-up run');
        $bound = SqlFragments::isNaive($this->catalog->type($this->map->table, $column)) ? SqlFragments::inApplicationZone('CAST(? AS timestamptz)') : 'CAST(? AS timestamptz)';

        return fn (QueryBuilder $query) => $query->whereRaw(SqlFragments::identifier($column).' >= '.$bound, [$watermark]);
    }

    private function now(string $operation): string
    {
        $now = (array) $this->connection->selectOne(
            "select to_char(least(now(), min(xact_start) filter (where backend_type = 'client backend')) at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') as watermark,
                    count(*) filter (where state is null and backend_type is null) as hidden,
                    current_user as role
             from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid()",
            [],
            false,
        );
        $hidden = (int) ($now['hidden'] ?? 0);

        if ($hidden > 0) {
            $role = (string) ($now['role'] ?? '');

            throw InvalidCompatibilityMap::cannot($this->map->table, $operation, "pg_stat_activity hides from {$role} whether {$hidden} other sessions of this database hold a transaction open, so no watermark can start before a write they have not committed yet. Grant pg_read_all_stats to {$role}, then {$operation} again");
        }

        return (string) ($now['watermark'] ?? '');
    }

    private function inApplicationZone(): null|string
    {
        $session = $this->connection->transactionLevel() > 1 ? $this->connection->scalar("select current_setting('TimeZone')", [], false) : null;
        $this->connection->select("select set_config('TimeZone', ?, true)", [Config::string('app.timezone', 'UTC')], false);

        return is_string($session) ? $session : null;
    }

    private function restoreZone(null|string $session): void
    {
        if ($session !== null) {
            $this->connection->select("select set_config('TimeZone', ?, true)", [$session], false);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function probe(array $row): Model
    {
        $legacy = (new $this->map->model)->setConnection($this->connectionName);
        $legacy->setRawAttributes($row, true);

        return $legacy;
    }

    private static function commentOn(ConnectionInterface $connection, string $table): null|string
    {
        $comment = $connection->scalar("select obj_description(to_regclass(quote_ident(current_schema()) || '.' || ?), 'pg_class')", [SqlFragments::identifier($table)], false);

        return is_string($comment) ? $comment : null;
    }

    /**
     * @return null|array<array-key, mixed>
     */
    private static function decoded(null|string $comment): null|array
    {
        $decoded = $comment === null ? null : json_decode($comment, true);

        return is_array($decoded) && ($decoded['backfilled'] ?? null) === self::MARKER ? $decoded : null;
    }

    private static function greater(null|int|string $remembered, null|int|string $last): null|int|string
    {
        return match (true) {
            $remembered === null => $last,
            $last === null => $remembered,
            is_int($remembered) && is_int($last) => max($remembered, $last),
            default => strcmp((string) $remembered, (string) $last) >= 0 ? $remembered : $last,
        };
    }

    private static function keyOf(mixed $key): int|string
    {
        return is_int($key) || is_string($key) ? $key : throw new LogicException('A legacy row has no key to backfill it by.');
    }
}
