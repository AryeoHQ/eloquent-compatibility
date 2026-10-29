<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Bridge\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;
use Support\Database\Eloquent\Compatibility\Connections\Events\QueryFailed;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityConnectionUnsupported;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityEventMirrorBypassed;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Connections\ChildConnection;
use Tests\Fixtures\Support\Connections\FailingRollBackConnection;
use Tests\Fixtures\Support\Connections\OverridesBeforeExecutingConnection;
use Tests\Fixtures\Support\Connections\OverridesHandleQueryExceptionConnection;
use Tests\Fixtures\Support\Connections\OverridesRegisterEventBridgeConnection;
use Tests\Fixtures\Support\Connections\QueryFailedConnection;
use Tests\Fixtures\Support\Workbench\Account;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Article;
use Tests\Fixtures\Support\Workbench\ArticleCompatibilityMap;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\CompatibleModel;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\GadgetSite;
use Tests\Fixtures\Support\Workbench\Person;
use Tests\Fixtures\Support\Workbench\Profile;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Publication;
use Tests\Fixtures\Support\Workbench\PublicationState\Events\Transitioned;
use Tests\Fixtures\Support\Workbench\PublicationState\Events\Transitioning;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(EventBridge::class)]
final class EventBridgeOutsideTransactionTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    private const string CONCURRENT_STATEMENT = <<<'PHP'
        $config = json_decode((string) getenv('CONCURRENT_CONNECTION'), true);
        $connection = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec("set application_name = 'compatibility_concurrent_reader'");
        $connection->exec("set lock_timeout = '10s'");
        $connection->exec("set deadlock_timeout = '100ms'");
        $connection->beginTransaction();
        $connection->exec((string) getenv('CONCURRENT_STATEMENT'));
        $connection->commit();
        PHP;

    private null|string $concurrentOutcome = null;

    protected function setUp(): void
    {
        parent::setUp();

        Tables::drop();
        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new WidgetCompatibilityMap));
    }

    protected function tearDown(): void
    {
        Model::preventAccessingMissingAttributes(false);
        DB::rollBack(0);
        Tables::drop();

        parent::tearDown();
    }

    /** @return list<string> */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function it_commits_a_mirrored_write_made_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Committed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Committed', DB::table('gadgets')->value('name'));
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_runs_the_trigger_of_a_state_change_made_outside_a_transaction_and_fires_its_after_event_after_the_commit(): void
    {
        resolve(Installer::class)->install(new ArticleCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new ArticleCompatibilityMap));
        $article = Article::query()->create(['title' => 'Draft article']);
        DB::connection()->setTransactionManager(new DatabaseTransactionsManager);
        $after = [];
        Event::listen(Transitioned::class, function () use (&$after): void {
            $after[] = [DB::transactionLevel(), DB::table('publications')->value('state')];
        });

        $article->update(['status' => 'published']);

        $this->assertSame([[0, 'published']], $after);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertNotNull(DB::table('publications')->value('published_at'));
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_mirrors_an_explicit_save_inside_a_trigger_run_outside_a_transaction_on_its_own_statement_and_commits_both(): void
    {
        resolve(Installer::class)->install(new ArticleCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new ArticleCompatibilityMap));
        $article = Article::query()->create(['title' => 'Draft article']);
        DB::table('publications')->update(['state' => 'published']);
        $article->refresh();
        $this->recordMirroredEvents();
        $levels = [];
        Event::listen('eloquent.updating: '.Publication::class, function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        $article->update(['status' => 'featured']);

        $this->assertSame([2, 1], $levels);
        $this->assertMirrored(Article::class, ModelEvent::Updated, fn (Model $mirrored) => $mirrored->getAttribute('featured_at') !== null);
        $this->assertMirrored(Publication::class, ModelEvent::Updated, fn (Model $mirrored) => $mirrored->wasChanged('state'));
        $this->assertCount(2, $this->mirroredEvents());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['featured', true], [DB::table('publications')->value('state'), DB::table('publications')->value('featured_at') !== null]);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_rolls_back_a_state_change_made_outside_a_transaction_and_fires_no_after_event_when_its_trigger_throws_or_refuses(bool $refuses): void
    {
        resolve(Installer::class)->install(new ArticleCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new ArticleCompatibilityMap));
        $article = Article::query()->create(['title' => 'Draft article']);
        DB::connection()->setTransactionManager(new DatabaseTransactionsManager);
        $after = [];
        Event::listen(Transitioned::class, function () use (&$after): void {
            $after[] = DB::transactionLevel();
        });

        if ($refuses) {
            DB::table('publications')->update(['locked' => true]);
        } else {
            Event::listen(Transitioning::class, fn () => throw new RuntimeException('The before event failed.'));
        }

        $this->recordMirroredEvents();

        $this->assertThrows(fn () => $article->update(['title' => 'Rolled back', 'status' => 'published']), $refuses ? CompatibilityWriteRefused::class : RuntimeException::class);

        $this->assertSame([], $after);
        $this->assertNothingMirrored();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['draft', 'Draft article', null], [DB::table('publications')->value('state'), DB::table('publications')->value('title'), DB::table('publications')->value('published_at')]);
    }

    #[Test]
    public function it_rolls_back_a_write_made_outside_a_transaction_when_a_mirrored_listener_refuses(): void
    {
        $widget = $this->widget();
        Event::listen('eloquent.updating: '.Gadget::class, fn () => false);

        try {
            $widget->update(['title' => 'Refused']);
            $this->fail('Expected the mirrored listener to refuse the write.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringContainsString("a 'updating' listener of Gadget", $refusal->refused);
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('widgets')->value('title'));
    }

    #[Test]
    public function it_refuses_every_mirrored_write_on_a_connection_whose_class_does_not_dispatch_query_failed_and_names_the_connection_its_class_and_the_trait(): void
    {
        $default = (string) config('database.default');
        $widget = $this->widget();
        config(["database.connections.{$default}.connection_class" => PostgresConnection::class]);
        DB::purge($default);

        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new AccountCompatibilityMap));

        $this->assertArrayNotHasKey($default, DB::getConnections());
        $this->recordMirroredEvents();
        $refusal = "connection '{$default}' is an instance of ".PostgresConnection::class.', which does not use the trait '.DispatchesQueryFailed::class;
        $this->assertThrows(fn () => $widget->update(['title' => 'Refused']), CompatibilityConnectionUnsupported::class, $refusal);
        $this->assertThrows(fn () => Widget::query()->create(['title' => 'Refused too']), CompatibilityConnectionUnsupported::class, $refusal);
        $this->assertNothingMirrored();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['Blue gadget'], DB::table('gadgets')->pluck('name')->all());
    }

    /**
     * @param  class-string<Connection>  $class
     */
    #[Test]
    #[TestWith([OverridesHandleQueryExceptionConnection::class, 'handleQueryException'])]
    #[TestWith([OverridesBeforeExecutingConnection::class, 'beforeExecuting'])]
    #[TestWith([OverridesRegisterEventBridgeConnection::class, 'registerEventBridge'])]
    public function it_refuses_every_mirrored_write_on_a_connection_whose_class_replaces_a_method_of_the_trait_and_names_that_class(string $class, string $method): void
    {
        $default = (string) config('database.default');
        $widget = $this->widget();
        config(["database.connections.{$default}.connection_class" => $class]);
        DB::purge($default);
        $this->recordMirroredEvents();
        $refusal = "connection '{$default}' is an instance of {$class}, which resolves {$method}() to {$class}::{$method}(), not to the method of ".DispatchesQueryFailed::class;

        $this->assertThrows(fn () => $widget->update(['title' => 'Refused']), CompatibilityConnectionUnsupported::class, $refusal);
        $this->assertThrows(fn () => Widget::query()->create(['title' => 'Refused too']), CompatibilityConnectionUnsupported::class, $refusal);

        $this->assertNothingMirrored();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['Blue gadget'], DB::table('gadgets')->pluck('name')->all());
    }

    #[Test]
    public function it_runs_a_transaction_as_the_next_call_after_a_database_refusal_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $this->refuseUuidChange($widget);

        DB::transaction(fn () => $widget->refresh()->update(['title' => 'In a transaction']));

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('In a transaction', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_runs_create_or_first_as_the_next_call_after_a_database_refusal_outside_a_transaction(): void
    {
        $this->refuseUuidChange($this->widget());
        $this->recordMirroredEvents();

        $created = Widget::query()->createOrFirst(['title' => 'Created next'], ['street' => '2 Next St']);

        $this->assertTrue($created->wasRecentlyCreated);
        $this->assertMirrored(Gadget::class, ModelEvent::Created, fn (Model $gadget) => $gadget->getAttribute('name') === 'Created next');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['Blue gadget', 'Created next'], DB::table('gadgets')->orderBy('name')->pluck('name')->all());
    }

    #[Test]
    public function it_runs_an_after_commit_callback_registered_as_the_next_call_after_a_database_refusal_outside_a_transaction_once(): void
    {
        $widget = $this->widget();
        $this->refuseUuidChange($widget);
        $levels = [];

        DB::afterCommit(function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        $widget->refresh()->update(['title' => 'Next write']);

        $this->assertSame([0], $levels);
        $this->assertSame('Next write', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_rolls_back_its_transaction_once_as_soon_as_the_database_refuses_a_write_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $rollbacks = 0;
        Event::listen(TransactionRolledBack::class, function () use (&$rollbacks): void {
            $rollbacks++;
        });

        $this->refuseUuidChange($widget);

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(1, $rollbacks);

        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
        $widget->syncOriginalAttribute('uuid');
        $widget->title = 'Next write';
        $widget->save();

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Next write', DB::table('gadgets')->value('name'));
        $this->assertSame(1, $rollbacks);
    }

    #[Test]
    public function it_rolls_back_at_once_a_write_whose_own_statement_fails_before_it_reaches_the_database_outside_a_transaction(): void
    {
        $widget = $this->widget()->refresh();
        $concurrent = $this->concurrentConnection();
        $concurrent->statement("set lock_timeout = '200ms'");
        $this->recordMirroredEvents();

        $this->assertThrows(fn () => $widget->update(['title' => DB::raw("coalesce(:x, 'Renamed')")]), QueryException::class, 'HY093');

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', $concurrent->transaction(fn () => $concurrent->table('gadgets')->where('id', $widget->gadget_id)->lockForUpdate()->value('name')));
        DB::table('people')->insert(['email' => 'later@example.test']);
        $this->assertSame(['later@example.test'], $concurrent->table('people')->pluck('email')->all());
        $this->assertAfterCommitRunsAtOnce();
        $this->assertNothingMirrored();
    }

    #[Test]
    public function it_rolls_back_to_its_savepoint_and_releases_it_when_its_own_statement_fails_before_it_reaches_the_database_inside_a_caller_transaction(): void
    {
        $widget = $this->widget()->refresh();
        $concurrent = $this->concurrentConnection();
        $concurrent->statement("set lock_timeout = '200ms'");
        $this->recordMirroredEvents();
        DB::beginTransaction();

        try {
            $this->assertThrows(fn () => $widget->update(['title' => DB::raw("coalesce(:x, 'Renamed')")]), QueryException::class, 'HY093');

            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame('Blue gadget', $concurrent->transaction(fn () => $concurrent->table('gadgets')->where('id', $widget->gadget_id)->lockForUpdate()->value('name')));
            $widget->refresh()->update(['title' => 'Renamed']);
        } finally {
            DB::commit();
        }

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertCount(1, $this->mirroredEvents());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Renamed', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_fails_and_rolls_back_a_write_whose_query_executed_listener_catches_a_failed_mapped_create_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $nested = null;
        $this->listenBeforeTheBridge(function (QueryExecuted $query) use (&$nested): void {
            if ($nested === null && str_starts_with($query->sql, 'update "widgets"')) {
                try {
                    Widget::query()->create(['title' => null, 'street' => '2 Nested St']);
                } catch (QueryException $e) {
                    $nested = $e;
                }
            }
        });
        $this->recordMirroredEvents();

        $this->assertThrows(fn () => $widget->update(['title' => 'Never mirrored']), QueryException::class, 'current transaction is aborted');

        $this->assertSame('23502', $nested?->getCode());
        $this->assertNothingMirrored();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertAfterCommitRunsAtOnce();
        $this->assertSame(['Blue gadget'], DB::table('gadgets')->pluck('name')->all());
    }

    #[Test]
    public function it_completes_a_write_whose_statement_fails_on_another_connection_of_the_same_name_outside_a_transaction(): void
    {
        $default = (string) config('database.default');
        $read = DB::connection("{$default}::read");
        $this->assertSame($default, $read->getName());
        $this->assertTrue((new Widget(['title' => 'Written through the read connection']))->setConnection("{$default}::read")->save());
        $widget = $this->widget();
        $read->beginTransaction();
        $read->statement("set local lock_timeout = '100ms'");
        $failures = [];
        $this->listenBeforeTheBridge(function (QueryExecuted $query) use ($read, &$failures): void {
            if ($failures === [] && $query->connection === DB::connection() && str_starts_with($query->sql, 'update "widgets"')) {
                try {
                    $read->update($query->sql, $query->bindings);
                } catch (QueryException $e) {
                    $failures[] = $e;
                }
            }
        });
        $this->recordMirroredEvents();

        try {
            $widget->update(['title' => 'Renamed']);
        } finally {
            $read->rollBack();
        }

        $this->assertSame('55P03', ($failures[0] ?? null)?->getCode());
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame([0, 0], [DB::transactionLevel(), $read->transactionLevel()]);
    }

    #[Test]
    public function it_keeps_a_write_whose_rollback_fails_and_retries_the_rollback_once_on_each_later_statement_outside_a_transaction(): void
    {
        $default = (string) config('database.default');
        $widget = $this->widget();
        config(["database.connections.{$default}.connection_class" => FailingRollBackConnection::class]);
        DB::purge($default);
        $connection = DB::connection();
        $this->assertInstanceOf(FailingRollBackConnection::class, $connection);
        $connection->rollBackFailures = 2;

        $this->refuseUuidChange($widget);

        $this->assertSame([1, 1], [$connection->rollBackAttempts, DB::transactionLevel()]);
        $this->assertThrows(fn () => DB::table('gadgets')->count(), PDOException::class, 'The server failed the rollback.');
        $this->assertSame([2, 1], [$connection->rollBackAttempts, DB::transactionLevel()]);
        $this->assertSame(1, DB::table('gadgets')->count());
        $this->assertSame([3, 0], [$connection->rollBackAttempts, DB::transactionLevel()]);
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_rolls_back_and_never_commits_a_write_whose_rollback_failed_when_a_later_save_of_its_model_sends_no_statement(): void
    {
        $default = (string) config('database.default');
        $widget = $this->widget();
        config(["database.connections.{$default}.connection_class" => FailingRollBackConnection::class]);
        DB::purge($default);
        $connection = DB::connection();
        $this->assertInstanceOf(FailingRollBackConnection::class, $connection);
        $connection->rollBackFailures = 1;
        $this->refuseUuidChange($widget);
        $ended = [];
        Event::listen(TransactionCommitted::class, function () use (&$ended): void {
            $ended[] = 'committed';
        });
        Event::listen(TransactionRolledBack::class, function () use (&$ended): void {
            $ended[] = 'rolled back';
        });

        $widget->syncOriginalAttribute('uuid');
        $this->assertTrue($widget->save());

        $this->assertSame(['rolled back'], $ended);
        $this->assertSame([2, 0], [$connection->rollBackAttempts, DB::transactionLevel()]);
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_leaves_a_caller_transaction_aborted_after_a_database_refusal_inside_it_as_a_native_write_would(): void
    {
        $widget = $this->widget();
        DB::beginTransaction();
        $this->refuseUuidChange($widget);

        try {
            DB::table('gadgets')->count();
            $this->fail('Expected the caller transaction to stay aborted, as it would after a native failed statement.');
        } catch (QueryException $e) {
            $this->assertSame('25P02', $e->getCode());
        }

        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function it_throws_the_original_query_exception_when_the_connection_is_lost_during_a_bridged_write_outside_a_transaction(): void
    {
        $default = (string) config('database.default');
        $widget = $this->widget();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION lose_the_connection() RETURNS trigger LANGUAGE plpgsql AS $lose$
            BEGIN
              IF NEW.name = 'Lost' THEN
                PERFORM pg_terminate_backend(pg_backend_pid());
              END IF;
              RETURN NEW;
            END $lose$;
            CREATE TRIGGER lose_the_connection BEFORE UPDATE ON gadgets FOR EACH ROW EXECUTE FUNCTION lose_the_connection();
            SQL);
        $this->beforeApplicationDestroyed(function (): void {
            DB::unprepared('DROP FUNCTION IF EXISTS lose_the_connection() CASCADE');
        });

        try {
            $widget->update(['title' => 'Lost']);
            $this->fail('Expected the lost connection to fail the write.');
        } catch (QueryException $e) {
            $this->assertStringStartsWith('update "widgets"', $e->getSql());
        } finally {
            DB::purge($default);
        }
    }

    #[Test]
    public function it_rolls_back_its_transaction_before_the_next_statement_when_a_statement_in_its_window_fails_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $this->failInTheWindow($widget);

        $this->assertSame(1, DB::transactionLevel(), "Declared limit: the statement that failed is not the write's own, so until the next statement the connection still counts the transaction of the event bridge.");

        $this->assertSame(1, DB::table('gadgets')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function it_mirrors_a_write_whose_before_executing_callback_catches_a_failed_statement_outside_a_transaction(bool $registeredFirst, bool $readsAgain): void
    {
        $caught = null;
        $callback = function (string $query) use ($readsAgain, &$caught): void {
            if ($caught === null && str_starts_with($query, 'update "widgets"')) {
                try {
                    DB::select('select * from missing_relation');
                } catch (QueryException $e) {
                    $caught = $e;
                }

                if ($readsAgain) {
                    DB::select('select 1');
                }
            }
        };

        if ($registeredFirst) {
            DB::connection()->beforeExecuting($callback);
        }

        $widget = $this->widget();

        if (! $registeredFirst) {
            DB::connection()->beforeExecuting($callback);
        }

        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertInstanceOf(QueryException::class, $caught);
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Renamed', DB::table('gadgets')->value('name'));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_fails_a_write_with_25p02_when_its_before_executing_callback_catches_a_failed_statement_and_reads_again_inside_a_caller_transaction(bool $registeredFirst): void
    {
        $caught = null;
        $callback = function (string $query) use (&$caught): void {
            if ($caught === null && str_starts_with($query, 'update "widgets"')) {
                try {
                    DB::select('select * from missing_relation');
                } catch (QueryException $e) {
                    $caught = $e;
                }

                DB::select('select 1');
            }
        };

        if ($registeredFirst) {
            DB::connection()->beforeExecuting($callback);
        }

        $widget = $this->widget();

        if (! $registeredFirst) {
            DB::connection()->beforeExecuting($callback);
        }

        $this->recordMirroredEvents();
        DB::beginTransaction();

        try {
            $widget->update(['title' => 'Never written']);
            $this->fail('Expected the aborted caller transaction to fail the write.');
        } catch (QueryException $e) {
            $this->assertSame('25P02', $e->getCode());
        } finally {
            DB::rollBack();
        }

        $this->assertInstanceOf(QueryException::class, $caught);
        $this->assertNothingMirrored();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_mirrors_a_mapped_create_a_before_executing_callback_makes_on_an_update_and_the_update_itself_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $created = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$created): void {
            if (! $created && str_starts_with($query, 'update "widgets"')) {
                $created = true;
                Widget::query()->create(['title' => 'Created on the update', 'street' => '2 Window St']);
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Created, fn (Model $gadget) => $gadget->getAttribute('name') === 'Created on the update');
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['Created on the update', 'Renamed'], DB::table('gadgets')->orderBy('name')->pluck('name')->all());
    }

    #[Test]
    public function it_mirrors_an_update_whose_before_executing_callback_catches_a_failed_mapped_create_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $nested = null;
        DB::connection()->beforeExecuting(function (string $query) use (&$nested): void {
            if ($nested === null && str_starts_with($query, 'update "widgets"')) {
                try {
                    Widget::query()->create(['title' => null, 'street' => '2 Nested St']);
                } catch (QueryException $e) {
                    $nested = $e;
                }
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertSame('23502', $nested?->getCode());
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertCount(1, $this->mirroredEvents());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertAfterCommitRunsAtOnce();
        $this->assertSame(['Renamed'], DB::table('gadgets')->pluck('name')->all());
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_rolls_back_its_transaction_at_once_when_an_earlier_query_failed_listener_returns_false_or_throws(bool $throws): void
    {
        Exceptions::fake();
        $widget = $this->widget();
        $this->listenForFailuresBeforeTheBridge(fn () => $throws ? throw new RuntimeException('A failure listener failed.') : false);

        $this->refuseUuidChange($widget);

        $this->assertSame(0, DB::transactionLevel());
        $this->assertAfterCommitRunsAtOnce();
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));

        if ($throws) {
            Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'A failure listener failed.');
        } else {
            Exceptions::assertNothingReported();
        }
    }

    /**
     * @param  class-string<Connection>  $class
     */
    #[Test]
    #[TestWith([QueryFailedConnection::class])]
    #[TestWith([ChildConnection::class])]
    public function it_rolls_back_its_transaction_before_query_failed_reaches_a_listener(string $class): void
    {
        $default = (string) config('database.default');
        $widget = $this->widget();
        config(["database.connections.{$default}.connection_class" => $class]);
        DB::purge($default);
        $levels = [];
        Event::listen(QueryFailed::class, function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        $this->refuseUuidChange($widget);

        $this->assertInstanceOf($class, DB::connection());
        $this->assertSame([0], $levels);
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function it_rolls_back_at_once_the_transaction_of_a_mirrored_write_refused_inside_a_query_failed_listener(): void
    {
        $widget = $this->widget();
        $refusals = [];
        Event::listen(QueryFailed::class, function () use ($widget, &$refusals): void {
            if ($refusals === []) {
                try {
                    $widget->update(['uuid' => '00000000-0000-0000-0000-000000000000']);
                } catch (QueryException $e) {
                    $refusals[] = $e;
                }
            }
        });

        $this->assertThrows(fn () => DB::select('select * from missing_relation'), QueryException::class, 'missing_relation');

        $this->assertCount(1, $refusals);
        $this->assertTrue(CompatibilityWriteRefused::isRefusal($refusals[0]));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertAfterCommitRunsAtOnce();
    }

    #[Test]
    public function it_rolls_back_its_transaction_when_a_queued_bridged_write_fails_so_the_worker_records_the_failed_job(): void
    {
        $widget = $this->widget();
        $queue = 'compatibility-'.Str::random(8);
        $this->isolateTheDatabaseQueue();
        $id = $widget->id;

        dispatch(static fn () => Widget::query()->findOrFail($id)->update(['uuid' => '00000000-0000-0000-0000-000000000000']))
            ->onConnection('isolated')
            ->onQueue($queue);

        $this->artisan('queue:work', ['connection' => 'isolated', '--queue' => $queue, '--once' => true, '--tries' => 1])->assertSuccessful();

        $failed = DB::table('failed_jobs')->where('queue', $queue)->sole();
        $this->assertStringContainsString('CM001', (string) $failed->exception);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_opens_no_transaction_and_takes_no_lock_for_a_write_whose_statement_another_before_executing_callback_stops(): void
    {
        $widget = $this->widget()->refresh();
        $this->stopBeforeTheStatement($widget);

        $this->assertSame(0, DB::transactionLevel());
        $concurrent = $this->concurrentConnection();
        $concurrent->statement("set lock_timeout = '200ms'");
        $this->assertSame('Blue gadget', $concurrent->transaction(fn () => $concurrent->table('gadgets')->where('id', $widget->gadget_id)->lockForUpdate()->value('name')));
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function it_releases_the_transaction_left_by_a_failure_in_its_window_on_the_next_bridged_write(): void
    {
        $this->failInTheWindow($this->widget());

        Widget::query()->create(['title' => 'Next write']);

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['Blue gadget', 'Next write'], DB::table('gadgets')->orderBy('name')->pluck('name')->all());
    }

    #[Test]
    public function it_releases_the_transaction_left_by_a_failure_in_its_window_before_the_next_transaction_begins(): void
    {
        $this->failInTheWindow($this->widget());

        $count = DB::transaction(fn () => DB::table('gadgets')->count());

        $this->assertSame(1, $count);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_raises_the_force_delete_events_of_a_dependent_row_committed_before_the_primary_record_is_locked(): void
    {
        $widget = $this->widget()->refresh();
        $root = DB::table('gadget_places')->value('id');
        $this->recordMirroredEvents();
        $this->concurrently(
            fn (string $query) => str_ends_with($query, ' for update'),
            fn (Connection $concurrent) => $concurrent->table('gadget_places')->insert(['gadget_id' => $widget->gadget_id, 'parent_id' => $root, 'street' => '2 Child St']),
        );

        $widget->forceDelete();

        $this->assertSame('committed', $this->concurrentOutcome);
        $this->assertMirrored(GadgetPlace::class, ModelEvent::ForceDeleted, times: 2);
        $this->assertSame(0, DB::table('gadget_places')->count());
    }

    #[Test]
    public function it_raises_the_update_events_of_a_readable_dependent_row_committed_before_the_primary_record_is_locked(): void
    {
        $widget = Widget::query()->create(['title' => 'No place yet'])->refresh();
        $this->recordMirroredEvents();
        $this->concurrently(
            fn (string $query) => str_ends_with($query, ' for update'),
            fn (Connection $concurrent) => $concurrent->table('gadget_places')->insert(['gadget_id' => $widget->gadget_id, 'street' => '2 Concurrent St']),
        );

        $widget->update(['street' => '3 Updated St']);

        $this->assertSame('committed', $this->concurrentOutcome);
        $this->assertMirrored(GadgetPlace::class, ModelEvent::Updated, fn (Model $place, RecordMirrored $event) => $event->rowBefore['street'] === '2 Concurrent St' && $place->getAttribute('street') === '3 Updated St');
        $this->assertSame('3 Updated St', DB::table('gadget_places')->value('street'));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_locks_the_own_record_of_a_model_loaded_without_its_mapped_key_before_the_statement(bool $strict): void
    {
        Model::preventAccessingMissingAttributes($strict);
        $id = $this->widget()->getKey();
        $widget = Widget::query()->select(['uuid', 'title'])->firstOrFail();
        $widget->setKeyName('uuid')->setKeyType('string')->setIncrementing(false);
        $this->concurrently(
            $this->lockedFor(fn (string $query) => str_starts_with($query, 'update "widgets"')),
            fn (Connection $concurrent) => $concurrent->table('retained_widgets')->where('id', $id)->update(['sku' => 'Concurrent']),
        );

        $widget->update(['title' => 'Loaded without its id']);

        $this->assertSame('55P03', $this->concurrentOutcome);
        $this->assertNull(DB::table('retained_widgets')->value('sku'));
        $this->assertSame('Loaded without its id', DB::table('gadgets')->value('name'));
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[Test]
    #[TestWith(['increment', [], 49.0])]
    #[TestWith(['increment', ['sku' => 'Counted'], 49.0])]
    #[TestWith(['decrement', [], 46.0])]
    #[TestWith(['decrement', ['sku' => 'Counted'], 46.0])]
    public function it_locks_and_mirrors_an_increment_or_decrement_of_a_model_that_holds_an_unsaved_change_outside_a_transaction(string $method, array $extra, float $latitude): void
    {
        $widget = $this->locatedWidget();

        $widget->{$method}('latitude', 1.5, $extra);

        $this->assertLockedAndMirrored($widget, $extra, $latitude);
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[RequiresMethod(Model::class, 'incrementOrDecrementEach')]
    #[Test]
    #[TestWith(['incrementEach', [], 49.0])]
    #[TestWith(['incrementEach', ['sku' => 'Counted'], 49.0])]
    #[TestWith(['decrementEach', [], 46.0])]
    #[TestWith(['decrementEach', ['sku' => 'Counted'], 46.0])]
    public function it_locks_and_mirrors_an_increment_or_decrement_of_each_column_of_a_model_that_holds_an_unsaved_change_outside_a_transaction(string $method, array $extra, float $latitude): void
    {
        $widget = $this->locatedWidget();

        $widget->{$method}(['latitude' => 1.5], $extra);

        $this->assertLockedAndMirrored($widget, $extra, $latitude);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[Test]
    #[TestWith(['increment', ['latitude' => 1.0, 'updated_at' => '2026-01-01 00:00:00+00']])]
    #[TestWith(['update', ['title' => 'Written by a listener', 'latitude' => 1.0]])]
    public function it_locks_and_mirrors_a_write_on_its_own_statement_and_not_on_a_listeners_update_of_the_same_columns_outside_a_transaction(string $method, array $values): void
    {
        $widget = $this->locatedWidget(2);
        Event::listen('eloquent.updating: '.Widget::class.'*', function (string $name, array $payload) use ($values): void {
            if ($payload[0] instanceof Widget) {
                DB::table('widgets')->where('id', $payload[0]->getKey())->update($values);
            }
        });

        $method === 'increment' ? $widget->increment('latitude', 1.5) : $widget->update(['latitude' => 2.5]);

        $this->assertSame('55P03', $this->concurrentOutcome);
        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site, RecordMirrored $event) => (float) $site->getAttribute('latitude') === 2.5 && (float) ($event->rowBefore['latitude'] ?? null) === 1.0);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertNull(DB::table('retained_widgets')->where('id', $widget->getKey())->value('sku'));
    }

    #[Test]
    public function it_mirrors_both_writes_when_a_beginning_listener_writes_another_mapped_model_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $other = Widget::query()->create(['title' => 'Other gadget']);
        Event::listen('eloquent.updating: '.Widget::class.'*', function (string $name, array $payload) use ($other): void {
            if ($payload[0] instanceof Widget && $payload[0]->isNot($other)) {
                $other->update(['title' => 'Renamed by a listener']);
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed by a listener');
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function it_mirrors_the_outer_write_when_a_beginning_listener_catches_a_refused_write_to_another_mapped_model_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $other = Widget::query()->create(['title' => 'Other gadget']);
        Event::listen('eloquent.updating: '.Widget::class.'*', function (string $name, array $payload) use ($other): void {
            if ($payload[0] instanceof Widget && $payload[0]->isNot($other)) {
                try {
                    $other->update(['uuid' => '00000000-0000-0000-0000-000000000000']);
                } catch (QueryException) {
                }
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['Renamed', 'Other gadget'], [$widget->refresh()->title, $other->refresh()->title]);
    }

    #[Test]
    public function it_opens_no_transaction_and_takes_no_lock_when_a_listener_throws_before_the_statement(): void
    {
        $widget = $this->widget()->refresh();
        Event::listen('eloquent.updating: '.Widget::class.'*', function (): void {
            throw new RuntimeException('Thrown before the statement.');
        });

        $this->assertThrows(fn () => $widget->update(['title' => 'Never written']), RuntimeException::class, 'Thrown before the statement.');

        $this->assertSame(0, DB::transactionLevel());
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('concurrent'));
        $concurrent = DB::connection('concurrent');
        $concurrent->statement("set lock_timeout = '200ms'");
        $locked = $concurrent->transaction(fn () => $concurrent->table('gadgets')->where('id', $widget->gadget_id)->lockForUpdate()->value('name'));
        $this->assertSame('Blue gadget', $locked);
        $this->recordMirroredEvents();
        Widget::query()->whereKey($widget->getKey())->update(['title' => 'Written without events']);
        $this->assertNothingMirrored();
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function it_rolls_back_a_mirrored_write_whose_commit_fails_and_leaves_the_connection_outside_a_transaction(): void
    {
        $widget = $this->widget();
        DB::unprepared("create function refuse_at_commit() returns trigger language plpgsql as \$refuse\$ begin if NEW.name = 'Refused at commit' then raise exception 'refused at commit'; end if; return NEW; end \$refuse\$");
        DB::statement('create constraint trigger refuse_at_commit after update on gadgets deferrable initially deferred for each row execute function refuse_at_commit()');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop function if exists refuse_at_commit() cascade');
        });

        $this->assertThrows(fn () => $widget->update(['title' => 'Refused at commit']), PDOException::class, 'refused at commit');

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
        $this->recordMirroredEvents();
        $widget->refresh()->update(['title' => 'After a failed commit']);
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'After a failed commit');
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    #[TestWith(['creating', 'created'])]
    #[TestWith(['updating', 'updated'])]
    #[TestWith(['deleting', 'deleted'])]
    public function it_refuses_a_write_whose_statement_ran_without_its_mark_and_leaves_the_statement_committed_outside_a_transaction(string $beginning, string $finishing): void
    {
        $widget = $beginning === 'creating' ? new Widget(['title' => 'Unmarked']) : $this->widget();
        $this->recordMirroredEvents();

        $this->assertThrows(
            function () use ($widget, $beginning, $finishing): void {
                $this->fire($widget, $beginning);
                match ($beginning) {
                    'creating' => Widget::query()->insert(['title' => 'Unmarked']),
                    'updating' => Widget::query()->whereKey($widget->getKey())->update(['title' => 'Unmarked']),
                    default => Widget::query()->whereKey($widget->getKey())->forceDelete(),
                };
                $this->fire($widget, $finishing);
            },
            CompatibilityEventMirrorBypassed::class,
            'The '.Widget::class." write that fired '{$finishing}' reached the database on a statement HasCompatibility never marked, so the event bridge mirrored none of it.",
        );

        $this->assertNothingMirrored();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($beginning === 'deleting' ? [] : ['Unmarked'], DB::table('gadgets')->pluck('name')->all());
    }

    #[Test]
    public function it_makes_a_bridged_update_wait_on_the_primary_row_a_lock_through_the_view_holds(): void
    {
        $widget = $this->widget();
        $concurrent = $this->concurrentConnection();
        $concurrent->beginTransaction();
        Widget::on('concurrent')->whereKey($widget->getKey())->lockForUpdate()->firstOrFail();
        DB::statement("set lock_timeout = '200ms'");

        try {
            $widget->update(['title' => 'Renamed']);
            $this->fail('Expected the bridged update to wait on the locked record.');
        } catch (QueryException $e) {
            $this->assertSame(['55P03', 'select 1 from "gadgets" where "id" = ? for update'], [$e->getCode(), $e->getSql()]);
        } finally {
            DB::statement('reset lock_timeout');
            $concurrent->rollBack();
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->recordMirroredEvents();
        $widget->update(['title' => 'Renamed']);
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
    }

    #[Test]
    public function it_does_not_deadlock_a_lock_through_the_view_against_a_bridged_update_of_the_same_record(): void
    {
        $widget = $this->widget();
        $lock = Widget::query()->whereKey($widget->getKey())->lockForUpdate()->toRawSql();
        $reader = null;
        $this->concurrentConnection();
        DB::connection()->beforeExecuting(function (string $query) use ($lock, &$reader): void {
            if ($reader === null && str_starts_with($query, 'select 1 from "retained_widgets"')) {
                $reader = $this->lockConcurrently($lock);
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertInstanceOf(InvokedProcess::class, $reader);
        $result = $reader->wait();
        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_mirrors_a_create_and_the_update_its_created_listener_makes_to_the_same_instance(bool $inTransaction): void
    {
        Event::listen('eloquent.created: '.Widget::class, fn (Widget $widget) => $widget->update(['title' => 'Slug of '.$widget->getKey()]));
        $this->recordMirroredEvents();
        $create = fn () => Widget::query()->create(['title' => 'Blue gadget', 'street' => '1 Main St']);

        $widget = $inTransaction ? DB::transaction($create) : $create();

        $this->assertMirrored(Gadget::class, ModelEvent::Created, fn (Model $gadget) => $gadget->getAttribute('name') === 'Blue gadget');
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Slug of '.$widget->getKey());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Slug of '.$widget->getKey(), DB::table('gadgets')->value('name'));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_mirrors_a_current_model_create_and_the_update_its_created_listener_makes_to_the_same_instance(bool $inTransaction): void
    {
        Event::listen('eloquent.created: '.Gadget::class, fn (Gadget $gadget) => $gadget->update(['name' => 'Renamed by its created listener']));
        $this->recordMirroredEvents();
        $create = fn () => Gadget::query()->create(['name' => 'Blue gadget']);

        $inTransaction ? DB::transaction($create) : $create();

        $this->assertSame(
            [
                [Widget::class, 'Blue gadget', ['saving', 'creating', 'created', 'saved']],
                [Widget::class, 'Renamed by its created listener', ['saving', 'updating', 'updated', 'saved']],
            ],
            array_map(fn (RecordMirrored $recordMirrored) => [
                $recordMirrored->destination::class,
                $recordMirrored->destination->getAttribute('title'),
                array_map(fn (ModelEvent $event) => $event->value, $recordMirrored->events),
            ], $this->mirroredEvents()),
        );
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Renamed by its created listener', DB::table('widgets')->value('title'));
    }

    #[Test]
    public function it_commits_and_mirrors_a_write_whose_statement_an_earlier_query_executed_listener_follows_with_a_read(): void
    {
        $widget = $this->widget();
        $this->listenBeforeTheBridge(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "widgets"')) {
                DB::select('select 1');
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Renamed', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_mirrors_a_write_whose_statement_a_later_before_executing_callback_precedes_with_a_read(): void
    {
        $widget = $this->widget();
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'update "widgets"')) {
                DB::select('select 1');
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Renamed', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_mirrors_a_write_on_its_own_statement_when_an_earlier_before_executing_callback_inserts_into_another_table_first(): void
    {
        $inserted = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$inserted): void {
            if (! $inserted && str_starts_with($query, 'update "widgets"')) {
                $inserted = true;
                DB::table('people')->insert(['email' => 'audit@example.test']);
            }
        });
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget, RecordMirrored $event) => $event->rowBefore['name'] === 'Blue gadget' && $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['audit@example.test'], DB::table('people')->pluck('email')->all());
    }

    #[Test]
    public function it_mirrors_both_writes_when_an_earlier_query_executed_listener_makes_a_bridged_write_after_the_statement(): void
    {
        $widget = $this->widget();
        $other = Widget::query()->create(['title' => 'Other gadget']);
        $this->listenBeforeTheBridge(function (QueryExecuted $query) use ($other): void {
            if (str_starts_with($query->sql, 'update "widgets"') && $other->title === 'Other gadget') {
                $other->update(['title' => 'Renamed by a listener']);
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed by a listener');
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['Renamed', 'Renamed by a listener'], [$widget->refresh()->title, $other->refresh()->title]);
    }

    #[Test]
    public function it_mirrors_a_mapped_write_that_an_after_commit_callback_of_a_mirrored_listener_makes(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue gadget', 'latitude' => 47.5]);
        $deferred = false;
        Event::listen('eloquent.updated: '.Gadget::class, function (Gadget $gadget) use (&$deferred): void {
            if (! $deferred) {
                $deferred = true;
                DB::afterCommit(fn () => GadgetSite::query()->where('gadget_id', $gadget->getKey())->firstOrFail()->update(['latitude' => 9.0]));
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Model $mirrored) => (float) $mirrored->getAttribute('latitude') === 9.0);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('9.000000000000', DB::table('gadget_sites')->value('latitude'));
    }

    #[Test]
    #[TestWith([true])]
    #[TestWith([false])]
    public function it_mirrors_a_delete_made_again_after_its_touch_failed_before_its_statement(bool $retriedByTheTransaction): void
    {
        $this->widget();
        GadgetPlace::resolveRelationUsing('gadget', fn (GadgetPlace $place) => $place->belongsTo(Gadget::class, 'gadget_id', 'id', 'gadget'));
        $place = GadgetPlace::query()->sole()->setTouchedRelations(['gadget']);
        DB::unprepared(<<<'SQL'
            CREATE SEQUENCE touch_attempts;
            CREATE FUNCTION fail_the_first_touch() RETURNS trigger LANGUAGE plpgsql AS $fail$
            BEGIN
              IF nextval('touch_attempts') = 1 THEN
                RAISE EXCEPTION 'a concurrent update' USING ERRCODE = '40001';
              END IF;
              RETURN NEW;
            END $fail$;
            CREATE TRIGGER fail_the_first_touch BEFORE UPDATE ON gadgets FOR EACH ROW EXECUTE FUNCTION fail_the_first_touch();
            SQL);
        $this->beforeApplicationDestroyed(function (): void {
            DB::unprepared('DROP FUNCTION IF EXISTS fail_the_first_touch() CASCADE; DROP SEQUENCE IF EXISTS touch_attempts');
        });
        $this->recordMirroredEvents();

        if ($retriedByTheTransaction) {
            DB::transaction(fn () => $place->delete(), 2);
        } else {
            $this->assertThrows(fn () => $place->delete(), QueryException::class, 'a concurrent update');
            $place->delete();
        }

        $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Model $widget) => $widget->getAttribute('street') === null);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertNotNull(DB::table('gadget_places')->value('deleted_at'));
    }

    #[Test]
    public function it_raises_the_events_of_every_previous_model_over_a_current_table_on_a_default_connection_set_after_registration(): void
    {
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        resolve(Installer::class)->install(new ProfileCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new AccountCompatibilityMap));
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new ProfileCompatibilityMap));
        $this->switchTheDefaultConnection();
        $account = Account::query()->create(['email' => 'one@example.test']);
        $this->recordMirroredEvents();

        $account->update(['email' => 'two@example.test']);

        $this->assertMirrored(Person::class, ModelEvent::Updated, fn (Model $person) => $person->getAttribute('email') === 'two@example.test');
        $this->assertMirrored(Profile::class, ModelEvent::Updated, fn (Model $profile) => $profile->getAttribute('email') === 'two@example.test');
        $this->assertSame('tenant', $account->getConnectionName());
    }

    #[Test]
    public function it_raises_no_event_of_a_map_whose_current_model_names_a_connection_other_than_the_default_set_after_registration(): void
    {
        $person = (new class extends CompatibleModel
        {
            use HasUuids;

            protected $connection = 'pgsql';

            protected $table = 'people';

            protected $guarded = [];
        })::class;
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        resolve(Installer::class)->install(new ProfileCompatibilityMap($person));
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new AccountCompatibilityMap));
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new ProfileCompatibilityMap($person)));
        $this->switchTheDefaultConnection();
        $account = Account::query()->create(['email' => 'one@example.test']);
        $connections = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$connections): void {
            $connections[] = $query->connectionName;
        });
        $this->recordMirroredEvents();

        $account->update(['email' => 'two@example.test']);

        $this->assertMirrored(Person::class, ModelEvent::Updated, fn (Model $person) => $person->getAttribute('email') === 'two@example.test');
        $this->assertCount(1, $this->mirroredEvents());
        $this->assertSame(['tenant'], array_values(array_unique($connections)));
    }

    #[Test]
    public function it_raises_a_unique_violation_for_an_insert_or_ignore_through_the_view_that_conflicts(): void
    {
        $taken = $this->widget()->refresh()->getAttribute('uuid');

        $this->assertThrows(fn () => Widget::query()->insertOrIgnore(['title' => 'Duplicate', 'uuid' => $taken]), UniqueConstraintViolationException::class);

        $this->assertSame(1, DB::table('retained_widgets')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[RequiresMethod(Model::class, 'saveOrIgnore')]
    #[Test]
    public function it_raises_a_unique_violation_for_a_save_or_ignore_through_the_view_that_conflicts(): void
    {
        $taken = $this->widget()->refresh()->getAttribute('uuid');

        $this->assertThrows(fn () => (new Widget(['title' => 'Duplicate', 'uuid' => $taken]))->saveOrIgnore(), UniqueConstraintViolationException::class);

        $this->assertSame(1, DB::table('retained_widgets')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function switchTheDefaultConnection(): void
    {
        config()->set('database.connections.tenant', config('database.connections.pgsql'));
        DB::setDefaultConnection('tenant');
    }

    private function listenBeforeTheBridge(Closure $listener): void
    {
        Event::forget(QueryExecuted::class);
        DB::listen($listener);
        Event::listen(QueryExecuted::class, resolve(EventBridge::class)->handleQuery(...));
    }

    private function listenForFailuresBeforeTheBridge(Closure $listener): void
    {
        /** @var list<Closure> $listeners */
        $listeners = Event::getRawListeners()[QueryFailed::class] ?? [];
        Event::forget(QueryFailed::class);
        Event::listen(QueryFailed::class, $listener);

        foreach ($listeners as $registered) {
            Event::listen(QueryFailed::class, $registered);
        }
    }

    /**
     * @param  Closure(string): bool  $statement
     * @return Closure(string): bool
     */
    private function lockedFor(Closure $statement): Closure
    {
        $started = false;

        return function (string $query) use ($statement, &$started): bool {
            if (! $started) {
                $started = $statement($query);

                return false;
            }

            return str_starts_with($query, 'select * from ');
        };
    }

    private function assertAfterCommitRunsAtOnce(): void
    {
        $levels = [];

        DB::afterCommit(function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        $this->assertSame([0], $levels);
    }

    private function concurrentConnection(): Connection
    {
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('concurrent'));

        return DB::connection('concurrent');
    }

    private function lockConcurrently(string $statement): InvokedProcess
    {
        $reader = Process::env([
            'CONCURRENT_CONNECTION' => (string) json_encode(config('database.connections.concurrent')),
            'CONCURRENT_STATEMENT' => $statement,
        ])->start([PHP_BINARY, '-r', self::CONCURRENT_STATEMENT]);

        for ($attempt = 0; $attempt < 250 && $reader->running(); $attempt++) {
            if (DB::connection('concurrent')->scalar("select exists (select 1 from pg_stat_activity where datname = current_database() and application_name = 'compatibility_concurrent_reader' and wait_event_type = 'Lock')") === true) {
                return $reader;
            }

            usleep(20_000);
        }

        $result = $reader->wait();

        $this->fail('The concurrent reader never waited on a lock. '.$result->output().$result->errorOutput());
    }

    /**
     * @param  Closure(string): bool  $before
     * @param  Closure(Connection): mixed  $write
     */
    private function concurrently(Closure $before, Closure $write): void
    {
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('concurrent'));
        $concurrent = DB::connection('concurrent');
        $concurrent->statement("set lock_timeout = '200ms'");

        DB::connection()->beforeExecuting(function (string $query) use ($before, $write, $concurrent): void {
            if ($this->concurrentOutcome !== null || ! $before($query)) {
                return;
            }

            try {
                $write($concurrent);
                $this->concurrentOutcome = 'committed';
            } catch (QueryException $e) {
                $this->concurrentOutcome = (string) $e->getCode();
            }
        });
    }

    private function fire(Model $model, string $event): void
    {
        (fn () => $this->fireModelEvent($event, in_array($event, ['creating', 'updating', 'deleting'], true)))->call($model);
    }

    private function widget(): Widget
    {
        return Widget::query()->create(['title' => 'Blue gadget', 'street' => '1 Main St']);
    }

    private function locatedWidget(int $update = 1): Widget
    {
        $widget = Widget::query()->create(['title' => 'Blue gadget', 'street' => '1 Main St', 'latitude' => 47.5])->refresh();
        $widget->setAttribute('title', 'Never saved');
        $this->recordMirroredEvents();
        $updates = 0;
        $this->concurrently(
            $this->lockedFor(function (string $query) use ($update, &$updates): bool {
                return str_starts_with($query, 'update "widgets"') && ++$updates === $update;
            }),
            fn (Connection $concurrent) => $concurrent->table('retained_widgets')->where('id', $widget->getKey())->update(['sku' => 'Concurrent']),
        );

        return $widget;
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function assertLockedAndMirrored(Widget $widget, array $extra, float $latitude): void
    {
        $this->assertSame('55P03', $this->concurrentOutcome);
        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site) => (float) $site->getAttribute('latitude') === $latitude);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($extra['sku'] ?? null, DB::table('retained_widgets')->where('id', $widget->getKey())->value('sku'));
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    private function refuseUuidChange(Widget $widget): void
    {
        try {
            $widget->update(['uuid' => '00000000-0000-0000-0000-000000000000']);
            $this->fail('Expected the database to refuse the change.');
        } catch (QueryException $e) {
            $this->assertTrue(CompatibilityWriteRefused::isRefusal($e));
        }
    }

    private function failInTheWindow(Widget $widget): void
    {
        $armed = true;
        $this->listenBeforeTheBridge(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'update "widgets"')) {
                $armed = false;
                DB::select('select * from missing_relation');
            }
        });

        $this->assertThrows(fn () => $widget->update(['title' => 'Never mirrored']), QueryException::class, 'missing_relation');
    }

    private function stopBeforeTheStatement(Widget $widget): void
    {
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$armed): void {
            if ($armed && str_starts_with($query, 'update "widgets"')) {
                $armed = false;

                throw new RuntimeException('Stopped before the statement.');
            }
        });

        $this->assertThrows(fn () => $widget->update(['title' => 'Never sent']), RuntimeException::class, 'Stopped before the statement.');
    }

    private function isolateTheDatabaseQueue(): void
    {
        $default = (string) config('database.default');

        config([
            'database.connections.isolated' => config("database.connections.{$default}"),
            'queue.connections.isolated' => ['driver' => 'database', 'connection' => 'isolated', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
            'queue.failed' => ['driver' => 'database-uuids', 'database' => $default, 'table' => 'failed_jobs'],
        ]);

        Schema::dropIfExists('jobs');
        Schema::dropIfExists('failed_jobs');

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $this->beforeApplicationDestroyed(function (): void {
            DB::rollBack(0);
            Schema::dropIfExists('jobs');
            Schema::dropIfExists('failed_jobs');
            DB::purge('isolated');
        });
    }
}
