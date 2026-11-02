<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class Installer
{
    public function __construct(private ConnectionInterface $connection) {}

    public function run(Statements $statements): void
    {
        if ($statements->afterwards !== [] && $this->connection->transactionLevel() > 0) {
            throw new LogicException("Compatibility::{$statements->operation}() runs ".count($statements->afterwards).' statements after its own transaction commits, and the connection is already inside a transaction. Run it from a migration that sets public $withinTransaction = false.');
        }

        $this->connection->transaction(function () use ($statements): void {
            foreach ($statements->transaction as $index => $statement) {
                $this->connection->unprepared($statement);

                foreach ($statements->steps[$index] ?? [] as $step) {
                    $step($this->connection);
                }
            }
        });

        foreach ($statements->afterwards as $statement) {
            $this->connection->unprepared($statement);
        }
    }
}
