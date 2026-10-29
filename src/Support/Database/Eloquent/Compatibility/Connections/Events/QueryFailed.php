<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Connections\Events;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

final readonly class QueryFailed
{
    public string $connectionName;

    /**
     * @param  array<array-key, mixed>  $bindings
     * @param  null|'read'|'write'|'direct'  $readWriteType
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public null|float $time,
        public Connection $connection,
        public QueryException $exception,
        public null|string $readWriteType = null,
    ) {
        $this->connectionName = (string) $connection->getName();
    }

    public function toRawSql(): string
    {
        return $this->connection
            ->query()
            ->getGrammar()
            ->substituteBindingsIntoRawSql($this->sql, $this->connection->prepareBindings($this->bindings));
    }
}
