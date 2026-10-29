<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Connections\ApplicationConnection;
use Tests\Fixtures\Support\Connections\OverridesHandleQueryExceptionConnection;
use Tests\TestCase;

#[CoversClass(CompatibilityConnectionUnsupported::class)]
final class CompatibilityConnectionUnsupportedTest extends TestCase
{
    private const string WHY = "Why: Outside a transaction the event bridge runs a mirrored write in a transaction of its own. Only the trait tells the bridge the moment the write's statement fails, so that the bridge can roll that transaction back at once. Without it, the next DB::transaction() or createOrFirst() on the connection fails with 25P02, and a DB::afterCommit() callback registered before the next statement is lost.";

    #[Test]
    public function it_names_the_connection_its_class_and_the_trait_the_class_lacks_and_counts_as_no_refusal(): void
    {
        $unsupported = CompatibilityConnectionUnsupported::byMissingTrait(['widgets', 'accounts'], $this->connection('reporting', PostgresConnection::class));

        $this->assertSame(
            "Compatibility map 'widgets', 'accounts' refused this write, because connection 'reporting' is an instance of Illuminate\\Database\\PostgresConnection, which does not use the trait Support\\Database\\Eloquent\\Compatibility\\Connections\\DispatchesQueryFailed. "
            .self::WHY
            ." Instead: Register a connection class that uses the trait with Connection::resolverFor('pgsql', …), as docs/adopting-a-map.md shows.",
            $unsupported->getMessage(),
        );
        $this->assertInstanceOf(CompatibilityException::class, $unsupported);
        $this->assertFalse(CompatibilityWriteRefused::isRefusal($unsupported));
    }

    #[Test]
    public function it_names_the_class_that_needs_the_trait_instead_of_a_resolver_when_the_class_is_the_applications_own(): void
    {
        $unsupported = CompatibilityConnectionUnsupported::byMissingTrait(['widgets'], $this->connection('reporting', ApplicationConnection::class));

        $this->assertStringEndsWith('. Instead: Use the trait in '.ApplicationConnection::class.'.', $unsupported->getMessage());
        $this->assertStringNotContainsString('resolverFor', $unsupported->getMessage());
    }

    #[Test]
    public function it_names_the_method_a_class_replaces_and_the_class_that_declares_it(): void
    {
        $unsupported = CompatibilityConnectionUnsupported::byOverride(['widgets'], $this->connection('reporting', OverridesHandleQueryExceptionConnection::class), 'handleQueryException', OverridesHandleQueryExceptionConnection::class);

        $this->assertSame(
            "Compatibility map 'widgets' refused this write, because connection 'reporting' is an instance of Tests\\Fixtures\\Support\\Connections\\OverridesHandleQueryExceptionConnection, which resolves handleQueryException() to Tests\\Fixtures\\Support\\Connections\\OverridesHandleQueryExceptionConnection::handleQueryException(), not to the method of Support\\Database\\Eloquent\\Compatibility\\Connections\\DispatchesQueryFailed. "
            .'Why: The event bridge works only through the methods of the trait. They open a mirrored write after every other beforeExecuting callback, and roll it back the moment its statement fails. '
            .'A declaration of handleQueryException() in a class replaces the method of the trait. Instead: Remove that declaration.',
            $unsupported->getMessage(),
        );
        $this->assertFalse(CompatibilityWriteRefused::isRefusal($unsupported));
    }

    /**
     * @param  class-string<Connection>  $class
     */
    private function connection(string $name, string $class): Connection
    {
        $default = (string) config('database.default');
        config(["database.connections.{$name}" => [...(array) config("database.connections.{$default}"), 'connection_class' => $class]]);
        $this->beforeApplicationDestroyed(fn () => DB::purge($name));

        return DB::connection($name);
    }
}
