<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Connections;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Connections\Events\QueryFailed;
use Tests\Fixtures\Support\Connections\ChildConnection;
use Tests\Fixtures\Support\Connections\QueryFailedConnection;
use Tests\Fixtures\Support\Connections\ResilientConnection;
use Tests\Fixtures\Support\Connections\WrappedChildConnection;
use Tests\TestCase;
use WeakReference;

#[CoversClass(QueryFailed::class)]
#[CoversTrait(DispatchesQueryFailed::class)]
final class DispatchesQueryFailedTest extends TestCase
{
    /** @var list<QueryFailed> */
    private array $failures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $default = (string) config('database.default');

        config([
            'database.connections.query-failed' => [...(array) config("database.connections.{$default}"), 'connection_class' => QueryFailedConnection::class],
            'database.connections.resilient' => [...(array) config("database.connections.{$default}"), 'connection_class' => ResilientConnection::class],
            'database.connections.child' => [...(array) config("database.connections.{$default}"), 'connection_class' => ChildConnection::class],
            'database.connections.wrapped-child' => [...(array) config("database.connections.{$default}"), 'connection_class' => WrappedChildConnection::class],
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function connections(): iterable
    {
        yield 'a connection that uses the trait' => ['query-failed'];
        yield 'a connection that overrides run() and the lost-connection retry' => ['resilient'];
        yield 'a connection whose parent class uses the trait too' => ['child'];
        yield 'a connection that uses the trait through another trait, over a parent class that uses it' => ['wrapped-child'];
    }

    #[DataProvider('connections')]
    #[Test]
    public function it_dispatches_query_failed_once_with_the_failed_statement(string $name): void
    {
        $connection = $this->connection($name);

        $thrown = $this->failing($connection, 'select * from missing_relation where id = ?', [7]);

        $this->assertCount(1, $this->failures);
        $this->assertSame('select * from missing_relation where id = ?', $this->failures[0]->sql);
        $this->assertSame([7], $this->failures[0]->bindings);
        $this->assertSame($name, $this->failures[0]->connectionName);
        $this->assertSame($connection, $this->failures[0]->connection);
        $this->assertSame($thrown, $this->failures[0]->exception);
        $this->assertSame('42P01', $thrown->getCode());
        $this->assertSame('select * from missing_relation where id = 7', $this->failures[0]->toRawSql());
    }

    #[DataProvider('connections')]
    #[Test]
    public function it_holds_no_reference_to_a_failure_its_caller_let_go(string $name): void
    {
        $failure = WeakReference::create($this->failing(DB::connection($name), 'select * from missing_relation'));

        gc_collect_cycles();

        $this->assertNull($failure->get());
    }

    #[DataProvider('connections')]
    #[Test]
    public function it_runs_the_callbacks_of_the_event_bridge_it_registered_last_around_every_other_before_executing_callback(string $name): void
    {
        $connection = $this->connection($name);
        $calls = [];

        foreach (['first', 'second'] as $bridge) {
            $connection->registerEventBridge(
                function () use ($bridge, &$calls): null {
                    $calls[] = "{$bridge} start";

                    return null;
                },
                function () use ($bridge, &$calls): void {
                    $calls[] = "{$bridge} open";
                },
                function () use ($bridge, &$calls): void {
                    $calls[] = "{$bridge} release";
                },
            );
        }

        $connection->beforeExecuting(function () use (&$calls): void {
            $calls[] = 'application';
        });

        $this->failing($connection, 'select * from missing_relation');

        $this->assertSame(['second start', 'application', 'second open', 'second release'], $calls);
    }

    #[DataProvider('connections')]
    #[Test]
    public function it_dispatches_nothing_for_a_successful_statement(string $name): void
    {
        $connection = $this->connection($name);

        $this->assertSame(1, $connection->scalar('select 1'));

        $this->assertSame([], $this->failures);
    }

    #[DataProvider('connections')]
    #[Test]
    public function it_dispatches_query_failed_once_for_a_failed_statement_inside_a_transaction(string $name): void
    {
        $connection = $this->connection($name);
        $connection->beginTransaction();

        try {
            $thrown = $this->failing($connection, 'select * from missing_relation');
        } finally {
            $connection->rollBack();
        }

        $this->assertCount(1, $this->failures);
        $this->assertSame($thrown, $this->failures[0]->exception);
    }

    #[DataProvider('connections')]
    #[Test]
    public function it_dispatches_nothing_when_a_lost_connection_retry_succeeds(string $name): void
    {
        $connection = $this->connection($name);
        $before = $this->terminate($connection);

        $after = $connection->scalar('select pg_backend_pid()');

        $this->assertNotSame($before, $after);
        $this->assertSame([], $this->failures);
        $this->assertRetried($connection);
    }

    #[DataProvider('connections')]
    #[Test]
    public function it_dispatches_query_failed_once_when_a_lost_connection_retry_fails(string $name): void
    {
        $connection = $this->connection($name);
        $this->terminate($connection);

        $thrown = $this->failing($connection, 'select * from missing_relation');

        $this->assertSame('42P01', $thrown->getCode());
        $this->assertCount(1, $this->failures);
        $this->assertSame($thrown, $this->failures[0]->exception);
        $this->assertRetried($connection);
    }

    #[DataProvider('connections')]
    #[Test]
    public function it_does_not_dispatch_again_for_a_statement_that_fails_inside_a_listener_on_the_same_connection(string $name): void
    {
        $connection = $this->connection($name);
        $inner = null;
        $connection->listenForFailures(function () use ($connection, &$inner): void {
            if (count($this->failures) === 1) {
                try {
                    $connection->select('select * from another_missing_relation');
                } catch (QueryException $e) {
                    $inner = $e;
                }
            }
        });

        $thrown = $this->failing($connection, 'select * from missing_relation');

        $this->assertInstanceOf(QueryException::class, $inner);
        $this->assertCount(1, $this->failures);
        $this->assertSame($thrown, $this->failures[0]->exception);
    }

    #[DataProvider('connections')]
    #[Test]
    public function it_throws_the_query_exception_when_a_query_failed_listener_throws_and_reporting_it_throws_too(string $name): void
    {
        Exceptions::fake()->throwOnReport();
        $connection = $this->connection($name);
        $connection->listenForFailures(fn () => throw new RuntimeException('A failure listener failed.'));

        $thrown = $this->failing($connection, 'select * from missing_relation');

        $this->assertSame('42P01', $thrown->getCode());
        $this->assertCount(1, $this->failures);
        $this->assertSame($thrown, $this->failures[0]->exception);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'A failure listener failed.');
    }

    #[DataProvider('connections')]
    #[Test]
    public function it_dispatches_query_failed_once_for_a_statement_that_fails_during_a_lost_connection_retry_and_escapes_it(string $name): void
    {
        $connection = $this->connection($name);
        $this->terminate($connection);
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $established) use ($connection): void {
            if ($established->connection === $connection) {
                $connection->select('select * from another_missing_relation');
            }
        });

        $thrown = $this->failing($connection, 'select 1');

        $this->assertSame('42P01', $thrown->getCode());
        $this->assertCount(1, $this->failures);
        $this->assertSame('select * from another_missing_relation', $this->failures[0]->sql);
        $this->assertSame($thrown, $this->failures[0]->exception);
        $this->assertRetried($connection);
    }

    private function connection(string $name): ChildConnection|QueryFailedConnection|ResilientConnection|WrappedChildConnection
    {
        $connection = DB::connection($name);
        $this->assertTrue($connection instanceof ChildConnection || $connection instanceof QueryFailedConnection || $connection instanceof ResilientConnection || $connection instanceof WrappedChildConnection);
        $connection->listenForFailures(function (QueryFailed $failure): void {
            $this->failures[] = $failure;
        });

        return $connection;
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    private function failing(Connection $connection, string $sql, array $bindings = []): QueryException
    {
        try {
            $connection->select($sql, $bindings);
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail("Expected [{$sql}] to fail.");
    }

    private function terminate(Connection $connection): int
    {
        $pid = (int) $connection->scalar('select pg_backend_pid()');

        $this->assertTrue((bool) DB::connection()->scalar('select pg_terminate_backend(?, 5000)', [$pid]));

        return $pid;
    }

    private function assertRetried(Connection $connection): void
    {
        if ($connection instanceof ResilientConnection) {
            $this->assertSame(1, $connection->retries);
        }
    }
}
