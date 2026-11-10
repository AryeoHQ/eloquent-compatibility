<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final class Ledger
{
    public const int FIRST_KEYS = 5;

    private readonly string $key;

    /** @var null|array{string, string} */
    private null|array $types = null;

    public function __construct(private readonly ResolvedMap $map, private readonly ConnectionInterface $connection)
    {
        $this->key = (new $map->model)->getKeyName();
    }

    public static function table(string $legacyTable): string
    {
        return SqlFragments::objectName(
            map: $legacyTable,
            name: $legacyTable.'_backfill',
        );
    }

    /**
     * @return list<string>
     */
    public static function storage(ResolvedMap $map, ConnectionInterface $connection): array
    {
        $catalog = new ColumnCatalog(
            connection: $connection,
            throughWriteConnection: true,
        );
        $key = (new $map->model)->getKeyName();
        $keyType = $catalog->type(
            table: $map->table,
            column: $key,
        ) ?? throw InvalidCompatibilityMap::because(
            map: $map->table,
            problem: "{$map->table} has no column {$key}, the key of ".class_basename($map->model),
        );
        $rootType = $catalog->type(
            table: $map->primary->table,
            column: $map->primary->key,
        ) ?? throw InvalidCompatibilityMap::because(
            map: $map->table,
            problem: "the successor table {$map->primary->table} has no column {$map->primary->key}",
        );
        $ledger = self::table($map->table);

        return [
            'CREATE TABLE IF NOT EXISTS '.SqlFragments::identifier($ledger)." (\n  legacy_id {$keyType} PRIMARY KEY,\n  successor_id {$rootType},\n  status text NOT NULL,\n  reason text,\n  attempts integer NOT NULL DEFAULT 0,\n  attempted_at timestamptz NOT NULL\n)",
            'CREATE INDEX IF NOT EXISTS '.SqlFragments::identifier(SqlFragments::objectName(
                map: $map->table,
                name: $ledger.'_status_index',
            )).' ON '.SqlFragments::identifier($ledger)." (status) WHERE status IN ('refused', 'failed')",
        ];
    }

    public function exists(): bool
    {
        return $this->connection->scalar(
            query: "select to_regclass(quote_ident(current_schema()) || '.' || ?) is not null",
            bindings: [SqlFragments::identifier(self::table($this->map->table))],
            useReadPdo: false,
        ) === true;
    }

    /**
     * @param  list<int|string>  $keys
     * @return array<string, Outcome>
     */
    public function statuses(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $statuses = [];

        foreach ($this->connection->table(self::table($this->map->table))->useWritePdo()->whereIn(
            column: 'legacy_id',
            values: $keys,
        )->pluck(
            column: 'status',
            key: 'legacy_id',
        ) as $key => $status) {
            $statuses[(string) $key] = Outcome::from((string) $status);
        }

        return $statuses;
    }

    public function record(int|string $key, null|int|string $successor, Outcome $outcome, null|string $reason): void
    {
        $this->types ??= $this->types();
        $ledger = SqlFragments::identifier(self::table($this->map->table));

        $this->connection->insert(
            query: "INSERT INTO {$ledger} AS ledger (legacy_id, successor_id, status, reason, attempts, attempted_at)"
                ." VALUES (CAST(? AS {$this->types[0]}), CAST(? AS {$this->types[1]}), ?, ?, 1, now())"
                .' ON CONFLICT (legacy_id) DO UPDATE SET successor_id = COALESCE(excluded.successor_id, ledger.successor_id), status = excluded.status, reason = excluded.reason, attempts = ledger.attempts + 1, attempted_at = excluded.attempted_at',
            bindings: [$key, $successor, $outcome->value, $reason === null ? null : str_replace(
                search: "\0",
                replace: '',
                subject: $reason,
            )],
        );
    }

    /**
     * @return list<int|string>
     */
    public function blocking(): array
    {
        return array_values(array_map(
            callback: self::keyOf(...),
            array: $this->connection->table(self::table($this->map->table))->useWritePdo()->whereIn(
                column: 'status',
                values: [Outcome::Refused->value, Outcome::Failed->value],
            )->orderBy('legacy_id')->pluck('legacy_id')->all(),
        ));
    }

    public function refuseBlocked(string $operation): void
    {
        $rows = $this->connection->select(
            query: 'SELECT status, reason, count(*) AS rows, array_to_string((array_agg(CAST(legacy_id AS text) ORDER BY legacy_id))[1:'.self::FIRST_KEYS."], ', ') AS first_keys FROM ".SqlFragments::identifier(self::table($this->map->table))
                ." WHERE status IN ('refused', 'failed') GROUP BY status, reason ORDER BY status DESC, count(*) DESC, reason",
            useReadPdo: false,
        );

        if ($rows === []) {
            return;
        }

        $total = array_sum(array_map(
            callback: fn (object $row) => (int) ($row->rows ?? 0),
            array: $rows,
        ));

        throw InvalidCompatibilityMap::cannot(
            map: $this->map->table,
            operation: $operation,
            because: "{$total} {$this->map->table} ".($total === 1 ? 'row is' : 'rows are').' refused or failed in '.self::table($this->map->table).', and no row installs before every row can be served: '.implode(
                separator: '; ',
                array: array_map(
                    callback: fn (object $row) => (int) ($row->rows ?? 0).' '.(string) ($row->status ?? '').' because '.(string) ($row->reason ?? '').' ('.$this->key.' '.(is_string($row->first_keys ?? null) ? $row->first_keys : '').')',
                    array: $rows,
                ),
            ).'. Fix each row, run php artisan compatibility:backfill '.$this->map->model.', then install again',
        );
    }

    /**
     * @return array{refused: int, failed: int}
     */
    public function counts(): array
    {
        $counts = (array) $this->connection->selectOne(
            query: "SELECT count(*) FILTER (WHERE status = 'refused') AS refused, count(*) FILTER (WHERE status = 'failed') AS failed FROM ".SqlFragments::identifier(self::table($this->map->table))." WHERE status IN ('refused', 'failed')",
            useReadPdo: false,
        );

        return ['refused' => (int) ($counts['refused'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0)];
    }

    /**
     * @return list<array{int|string, null|int|string, Outcome}>
     */
    public function gone(): array
    {
        $legacy = SqlFragments::identifier($this->map->table);
        $ledger = SqlFragments::identifier(self::table($this->map->table));

        return array_values(array_map(
            callback: fn (object $row) => [self::keyOf($row->legacy_id ?? null), is_int($row->successor_id ?? null) || is_string($row->successor_id ?? null) ? $row->successor_id : null, Outcome::from(is_string($row->status ?? null) ? $row->status : '')],
            array: $this->connection->select(
                query: "SELECT ledger.legacy_id, ledger.successor_id, ledger.status FROM {$ledger} AS ledger WHERE ledger.status <> 'deleted' AND NOT EXISTS (SELECT FROM {$legacy} WHERE {$legacy}.".SqlFragments::identifier($this->key).' = ledger.legacy_id) ORDER BY ledger.legacy_id',
                useReadPdo: false,
            ),
        ));
    }

    public function deletedRoots(string $root): string
    {
        return 'SELECT ledger.successor_id FROM '.SqlFragments::identifier(self::table($this->map->table))." AS ledger WHERE ledger.status = 'deleted' AND ledger.successor_id = {$root}";
    }

    /**
     * @return array{string, string}
     */
    private function types(): array
    {
        $catalog = new ColumnCatalog(
            connection: $this->connection,
            throughWriteConnection: true,
        );

        return [(string) $catalog->type(
            table: $this->map->table,
            column: $this->key,
        ), (string) $catalog->type(
            table: $this->map->primary->table,
            column: $this->map->primary->key,
        )];
    }

    private static function keyOf(mixed $key): int|string
    {
        return is_int($key) || is_string($key) ? $key : throw new LogicException('A ledger row has no legacy key.');
    }
}
