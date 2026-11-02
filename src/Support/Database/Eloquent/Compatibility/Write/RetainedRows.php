<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class RetainedRows
{
    public function __construct(private ResolvedMap $map, private ConnectionInterface $connection) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function upsert(int|string $key, array $values): void
    {
        if ($values === []) {
            return;
        }

        $retainedKey = (string) $this->map->retainedKey;

        $this->connection->table(ViewCompiler::retainedTable($this->map))->upsert([[$retainedKey => $key, ...$values]], [$retainedKey], array_keys($values));
    }
}
