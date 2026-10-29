<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Connections\Events;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(QueryFailed::class)]
final class QueryFailedTest extends TestCase
{
    #[Test]
    public function it_carries_the_failed_statement_it_was_given_and_its_connections_name(): void
    {
        $connection = DB::connection();
        $exception = $this->failure($connection);

        $failure = new QueryFailed('select * from missing_relation where id = ?', [7], 1.5, $connection, $exception, 'write');

        $this->assertSame([
            'connectionName' => (string) config('database.default'),
            'sql' => 'select * from missing_relation where id = ?',
            'bindings' => [7],
            'time' => 1.5,
            'connection' => $connection,
            'exception' => $exception,
            'readWriteType' => 'write',
        ], get_object_vars($failure));
    }

    #[Test]
    public function it_takes_no_time_and_no_read_write_type_by_default(): void
    {
        $connection = DB::connection();

        $failure = new QueryFailed('select 1', [], null, $connection, $this->failure($connection));

        $this->assertNull($failure->time);
        $this->assertNull($failure->readWriteType);
    }

    #[Test]
    public function it_names_the_connection_the_statement_failed_on(): void
    {
        config(['database.connections.reporting' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('reporting'));
        $connection = DB::connection('reporting');

        $failure = new QueryFailed('select 1', [], null, $connection, $this->failure($connection));

        $this->assertSame('reporting', $failure->connectionName);
    }

    #[Test]
    public function it_prints_its_statement_with_each_binding_prepared_and_quoted_by_its_connection(): void
    {
        $connection = DB::connection();
        $sql = 'select * from widgets where title = ? and archived = ? and id = ? and created_at = ? and uuid is null';
        $bindings = ["O'Brien", true, 7, Date::parse('2026-10-26 10:00:00')];

        $failure = new QueryFailed($sql, $bindings, null, $connection, $this->failure($connection));

        $this->assertSame("select * from widgets where title = 'O''Brien' and archived = 1 and id = 7 and created_at = '2026-10-26 10:00:00' and uuid is null", $failure->toRawSql());
    }

    #[Test]
    public function it_prints_a_statement_without_bindings_unchanged(): void
    {
        $connection = DB::connection();

        $failure = new QueryFailed('select * from missing_relation', [], null, $connection, $this->failure($connection));

        $this->assertSame('select * from missing_relation', $failure->toRawSql());
    }

    private function failure(Connection $connection): QueryException
    {
        try {
            $connection->select('select * from missing_relation');
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Expected the statement to fail.');
    }
}
