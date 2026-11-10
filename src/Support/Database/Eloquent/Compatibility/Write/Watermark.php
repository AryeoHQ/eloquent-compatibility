<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class Watermark
{
    public const string TABLE = 'compatibility_backfills';

    private const string LOCK = 'compatibility_backfills:';

    private const string SECONDS = 'YYYY-MM-DD"T"HH24:MI:SS"Z"';

    private const string MICROSECONDS = 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"';

    public function __construct(private string $legacyType, private ConnectionInterface $connection) {}

    /**
     * @return list<string>
     */
    public static function storage(string $legacyType): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS '.SqlFragments::identifier(self::TABLE)." (\n  legacy_type text PRIMARY KEY,\n  watermark_at timestamptz,\n  pass integer NOT NULL DEFAULT 0,\n  pass_started_at timestamptz,\n  pass_finished_at timestamptz,\n  pass_rows bigint\n)",
            'INSERT INTO '.SqlFragments::identifier(self::TABLE).' (legacy_type) VALUES ('.SqlFragments::literal($legacyType).') ON CONFLICT (legacy_type) DO NOTHING',
        ];
    }

    public static function forgetStatement(string $legacyType): string
    {
        return "DO \$compatibility\$\nBEGIN\n"
            ."  IF to_regclass(quote_ident(current_schema()) || '.' || ".SqlFragments::literal(self::TABLE).") IS NOT NULL THEN\n"
            .'    DELETE FROM '.SqlFragments::identifier(self::TABLE).' WHERE legacy_type = '.SqlFragments::literal($legacyType).";\n"
            ."  END IF;\nEND\n\$compatibility\$";
    }

    public function exists(): bool
    {
        return $this->connection->scalar(
            query: "select to_regclass(quote_ident(current_schema()) || '.' || ?) is not null",
            bindings: [SqlFragments::identifier(self::TABLE)],
            useReadPdo: false,
        ) === true;
    }

    public function tracked(): bool
    {
        return $this->exists() && $this->connection->scalar(
            query: 'select exists (select from '.SqlFragments::identifier(self::TABLE).' where legacy_type = ?)',
            bindings: [$this->legacyType],
            useReadPdo: false,
        ) === true;
    }

    public function remembered(): null|string
    {
        return $this->read(self::SECONDS);
    }

    public function bound(): null|string
    {
        return $this->read(self::MICROSECONDS);
    }

    public function pass(): null|int
    {
        if (! $this->exists()) {
            return null;
        }

        $pass = $this->connection->scalar(
            query: 'select pass from '.SqlFragments::identifier(self::TABLE).' where legacy_type = ?',
            bindings: [$this->legacyType],
            useReadPdo: false,
        );

        return is_int($pass) ? $pass : null;
    }

    public function start(): string
    {
        $started = $this->connection->selectOne(
            query: 'update '.SqlFragments::identifier(self::TABLE)." set pass = pass + 1, pass_started_at = now(), pass_finished_at = null, pass_rows = null where legacy_type = ? returning to_char(pass_started_at at time zone 'UTC', '".self::MICROSECONDS."') as started",
            bindings: [$this->legacyType],
            useReadPdo: false,
        );
        $value = $started === null ? null : ((array) $started)['started'] ?? null;

        return is_string($value) ? $value : throw InvalidCompatibilityMap::cannot(
            map: $this->legacyType,
            operation: 'backfill',
            because: self::TABLE." holds no row for {$this->legacyType}, which Compatibility::prepare() inserts; run Compatibility::prepare() first",
        );
    }

    public function finish(string $started, int $rows): void
    {
        $this->connection->update(
            query: 'update '.SqlFragments::identifier(self::TABLE).' set watermark_at = cast(? as timestamptz), pass_finished_at = now(), pass_rows = ? where legacy_type = ?',
            bindings: [$started, $rows, $this->legacyType],
        );
    }

    public function hold(string $operation): void
    {
        if ($this->connection->scalar(
            query: 'select pg_try_advisory_lock(hashtextextended(?, 0))',
            bindings: [self::LOCK.$this->legacyType],
            useReadPdo: false,
        ) !== true) {
            throw InvalidCompatibilityMap::cannot(
                map: $this->legacyType,
                operation: $operation,
                because: "another backfill pass or install of {$this->legacyType} holds its advisory lock, and each legacy model runs one pass at a time; wait for it to finish, then {$operation} again",
            );
        }
    }

    public function release(): void
    {
        $this->connection->scalar(
            query: 'select pg_advisory_unlock(hashtextextended(?, 0))',
            bindings: [self::LOCK.$this->legacyType],
            useReadPdo: false,
        );
    }

    private function read(string $format): null|string
    {
        if (! $this->exists()) {
            return null;
        }

        $watermark = $this->connection->scalar(
            query: "select to_char(watermark_at at time zone 'UTC', '{$format}') from ".SqlFragments::identifier(self::TABLE).' where legacy_type = ?',
            bindings: [$this->legacyType],
            useReadPdo: false,
        );

        return is_string($watermark) ? $watermark : null;
    }
}
