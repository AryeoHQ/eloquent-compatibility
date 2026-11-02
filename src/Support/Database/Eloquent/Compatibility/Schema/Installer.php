<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class Installer
{
    public function __construct(private ConnectionInterface $connection) {}

    public function run(Statements $statements): void
    {
        $this->compileAndRun([], fn () => $statements);
    }

    /**
     * @param  list<string>  $tables
     * @param  Closure(): Statements  $compile
     */
    public function compileAndRun(array $tables, Closure $compile): void
    {
        $outside = $this->connection->transactionLevel() === 0;

        $statements = $this->connection->transaction(function () use ($tables, $compile, $outside): Statements {
            $locked = array_values(array_filter(array_map(
                fn (string $table) => $this->connection->scalar("select cast(to_regclass(quote_ident(current_schema()) || '.' || ?) as text)", [SqlFragments::identifier($table)]),
                $tables,
            ), is_string(...)));

            if ($locked !== []) {
                $this->connection->unprepared('LOCK TABLE '.implode(', ', $locked).' IN SHARE UPDATE EXCLUSIVE MODE');
            }

            $statements = $compile();

            if ($statements->afterwards !== [] && ! $outside) {
                throw new LogicException("Compatibility::{$statements->operation}() runs ".count($statements->afterwards).' statements after its own transaction commits, and the connection is already inside a transaction. Run it from a migration that sets public $withinTransaction = false.');
            }

            foreach ($statements->transaction as $statement) {
                $this->connection->unprepared($statement);
            }

            return $statements;
        });

        foreach ($statements->afterwards as $statement) {
            $this->connection->unprepared($statement);
        }
    }
}
