<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Connections;

use Illuminate\Database\PostgresConnection;
use PDOException;
use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;

final class FailingRollBackConnection extends PostgresConnection
{
    use DispatchesQueryFailed;

    public int $rollBackAttempts = 0;

    public int $rollBackFailures = 0;

    /**
     * @param  int  $toLevel
     */
    protected function performRollBack($toLevel)
    {
        $this->rollBackAttempts++;

        if ($this->rollBackFailures > 0) {
            $this->rollBackFailures--;

            throw new PDOException('The server failed the rollback.');
        }

        parent::performRollBack($toLevel);
    }
}
