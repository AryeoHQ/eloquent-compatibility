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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;
use LogicException;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
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

    private const string WALKED = 'pg_temp.compatibility_walked';

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
        [$read, $changed, $last] = $this->walk($chunk, $since === null ? null : $this->updatedSince(self::watermark($since)), $chunked, false);
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
        $updated = $this->updatedAt() === null ? null : $this->updatedSince($watermark['updatedAt']);
        $scope = $watermark['key'] === null || $updated === null ? null : fn (QueryBuilder $query) => $query->where($this->key, '>', $watermark['key'])->orWhere($updated);
        [$read, $changed, $last] = $this->walk(self::CHUNK, $scope, null, true);

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
                $chosen[$this->map->branchFor($this->probe($row))->primary->model][] = self::keyOf($row[$this->key] ?? null);
            }

            foreach ($chosen as $model => $keys) {
                $branch = $this->map->branches[$model];
                $primary = SqlFragments::identifier($branch->primary->table);
                $misplaced = [...$misplaced, ...array_column($this->connection->select(
                    "SELECT CAST(chosen.key AS {$type}) AS key FROM jsonb_array_elements_text(CAST(? AS jsonb)) AS chosen (key)"
                        ." WHERE NOT EXISTS (SELECT FROM {$primary} WHERE {$primary}.".SqlFragments::identifier((string) $branch->identifiers[$this->key]->column())." = CAST(chosen.key AS {$type})"
                        .($branch->membership === null ? '' : ' AND '.$branch->membership->of($branch->primary->table)).')',
                    [json_encode($keys, JSON_THROW_ON_ERROR)],
                    false,
                ), 'key')];
            }

            $last = $rows === [] ? $last : self::keyOf(end($rows)[$this->key] ?? null);
        } while (count($rows) === self::CHUNK);

        if ($misplaced !== []) {
            sort($misplaced);

            throw InvalidCompatibilityMap::cannot($this->map->table, 'install', count($misplaced)." {$this->map->table} rows sit in a successor their servedFrom() selector does not choose; the first are {$this->key} ".implode(', ', array_slice($misplaced, 0, 5)).'. Run php artisan compatibility:backfill '.$this->map->model.', then install()');
        }
    }

    public static function rememberNow(string $table, null|string $comment, null|int|string $key): string
    {
        return "DO \$compatibility\$\nBEGIN\n"
            ."  EXECUTE format('COMMENT ON TABLE %s IS %L', ".SqlFragments::literal($table).', CAST(json_build_object('
            .SqlFragments::literal('backfilled').', '.SqlFragments::literal(self::MARKER).', '
            .SqlFragments::literal('comment').', CAST('.($comment === null ? 'NULL' : SqlFragments::literal($comment)).' AS text), '
            .SqlFragments::literal('key').', CAST('.SqlFragments::literal(json_encode($key, JSON_THROW_ON_ERROR)).' AS json), '
            .SqlFragments::literal('updatedAt').", to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')) AS text));\n"
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

    public function track(): void
    {
        $type = $this->catalog->type($this->map->table, $this->key) ?? throw InvalidCompatibilityMap::because($this->map->table, "{$this->map->table} has no column {$this->key}");
        $this->connection->statement('CREATE TEMPORARY TABLE IF NOT EXISTS '.self::WALKED.' ('.SqlFragments::identifier(self::KEY)." {$type} PRIMARY KEY)");
        $this->connection->statement('TRUNCATE '.self::WALKED);
        $this->connection->statement('INSERT INTO '.self::WALKED.' SELECT '.SqlFragments::identifier($this->key).' FROM '.SqlFragments::identifier($this->map->table));
    }

    public function untrack(): void
    {
        $this->connection->statement('DROP TABLE IF EXISTS '.self::WALKED);
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
    private function walk(int $chunk, null|Closure $scope, null|Closure $chunked, bool $track): array
    {
        $read = 0;
        $changed = 0;
        $last = null;

        do {
            $rows = $this->connection->transaction(function () use ($chunk, $scope, $chunked, $track, $last, &$changed): array {
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

                    if ($track) {
                        $this->walked($keys);
                    }

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
    private function writeBranch(ResolvedMap $branch, array $rows): int
    {
        $stored = [];
        $external = [];

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            [$stored[$key], $external[$key]] = $this->split($branch, $row, $key);
        }

        $changed = array_filter($branch->attributes, fn ($attribute) => $attribute->isExternal()) === []
            ? $this->upsert($branch, $stored)
            : $this->saveEach($branch, $rows, $stored, $external);

        foreach ($branch->related as $related) {
            $changed += $this->writeRelated($branch, $related, $stored);
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

            if ($column === null || ($conversion instanceof ReadAs && $conversion->inverse === null)) {
                continue;
            }

            try {
                $stored[$branch->owners[$name]->table][$column] = $attribute->write($row[$name] ?? null);
            } catch (LogicException $exception) {
                throw InvalidCompatibilityMap::cannot($this->map->table, 'backfill', "{$this->map->table} row {$this->key} {$key} holds a {$name} that attribute '{$name}' cannot store: {$exception->getMessage()}");
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
        $wrapped = array_map(SqlFragments::identifier(...), $changing);
        $target = SqlFragments::identifier(self::TARGET);
        $action = $changing === [] ? 'DO NOTHING' : 'DO UPDATE SET '.implode(', ', array_map(fn (string $column) => "{$column} = excluded.{$column}", [...$wrapped, ...($updated === null ? [] : [SqlFragments::identifier($updated)])]))
            .' WHERE '.$this->distinct($changing, $types, $target, 'excluded');
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
            $read[$name] = $existing->isEmpty() ? [] : $branch->attributes[$name]->readExternally($existing);
        }

        $changed = 0;

        foreach ($rows as $row) {
            $key = self::keyOf($row[$this->key] ?? null);
            $successor = $existing[$key] ?? (new $branch->primary->model)->setConnection($this->connectionName);
            $successor->setRawAttributes(array_replace($successor->getAttributes(), $stored[$key][$table]));
            $written = false;

            foreach ($external[$key] as $name => $value) {
                if (! $successor->exists || ! ExternalAttributes::same($value, $read[$name][$successor->getKey()] ?? null)) {
                    $branch->attributes[$name]->writeExternally($successor, $value);
                    $written = true;
                }
            }

            if ($successor->exists && ! $written && ! $successor->isDirty()) {
                continue;
            }

            if ($this->stamps($successor, array_keys($stored[$key][$table])) === [null, null]) {
                $successor->timestamps = false;
            }

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
        [$created, $updated] = $this->stamps($successor, $columns);
        $fixed = array_diff_key($related->attributes, array_flip([...$columns, $related->relatedKey]));
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
                'UPDATE '.SqlFragments::identifier($table)." AS {$target} SET ".implode(', ', [...array_map(fn (string $column) => "{$column} = {$source}.{$column}", $wrapped), ...($updated === null ? [] : [SqlFragments::identifier($updated).' = CAST(? AS '.$types[$updated].')'])])
                    ."\nFROM {$from}"
                    ."\nWHERE {$target}.{$relatedKey} = {$alias}.{$relatedKey} AND ".$this->distinct($columns, $types, $target, $source),
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
     */
    private function distinct(array $columns, array $types, string $before, string $after): string
    {
        $side = fn (string $alias) => implode(', ', array_map(fn (string $column) => $this->catalog->equatable($types[$column]) ? $alias.'.'.SqlFragments::identifier($column) : 'CAST('.$alias.'.'.SqlFragments::identifier($column).' AS text)', $columns));

        return '('.$side($before).') IS DISTINCT FROM ('.$side($after).')';
    }

    private function deleteGone(): int
    {
        $legacy = SqlFragments::identifier($this->map->table);
        $gone = 'NOT EXISTS (SELECT FROM '.$legacy.' WHERE '.$legacy.'.'.SqlFragments::identifier($this->key).' = walked.'.SqlFragments::identifier(self::KEY).')';
        $deleted = 0;

        foreach ($this->map->branches === [] ? [$this->map] : $this->map->branches as $branch) {
            $primary = SqlFragments::identifier($branch->primary->table);

            $deleted += $this->connection->affectingStatement(
                "DELETE FROM {$primary} USING ".self::WALKED.' AS walked WHERE '.$primary.'.'.SqlFragments::identifier((string) $branch->identifiers[$this->key]->column()).' = walked.'.SqlFragments::identifier(self::KEY)
                    .($branch->membership === null ? '' : ' AND '.$branch->membership->of($branch->primary->table))
                    .implode('', array_map(fn (string $condition) => ' AND NOT COALESCE(('.$condition.'), false)', ViewCompiler::hidden($branch)))
                    .' AND '.$gone,
            );
        }

        $this->connection->delete('DELETE FROM '.self::WALKED.' AS walked WHERE '.$gone);

        return $deleted;
    }

    /**
     * @param  list<int|string>  $keys
     */
    private function walked(array $keys): void
    {
        $type = (string) $this->catalog->type($this->map->table, $this->key);

        foreach ($this->batches(array_map(fn (int|string $key) => [$key], $keys), 1) as $batch) {
            $this->connection->insert('INSERT INTO '.self::WALKED.' VALUES '.implode(', ', array_fill(0, count($batch), "(CAST(? AS {$type}))")).' ON CONFLICT DO NOTHING', array_merge(...$batch));
        }
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

        foreach ($this->map->branches === [] ? [$this->map] : $this->map->branches as $branch) {
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
