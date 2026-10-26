<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Schema\SqlState;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(EventBridge::class)]
final class EventBridgeOutsideTransactionTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

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
    public function it_rolls_back_its_transaction_before_the_next_statement_after_a_database_refusal_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $this->refuseUuidChange($widget);

        $this->assertSame(1, DB::transactionLevel(), 'Declared limit: the event bridge sees a failed statement only when the next statement starts, so until then the connection still counts its transaction.');

        $this->assertSame(1, DB::table('gadgets')->count());
        $this->assertSame(0, DB::transactionLevel());
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
        $this->assertStringContainsString(SqlState::Refused->value, (string) $failed->exception);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_releases_the_transaction_left_by_a_database_refusal_when_the_application_terminates(): void
    {
        $this->refuseUuidChange($this->widget());

        $this->app->terminate();

        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function it_releases_the_transaction_left_by_a_database_refusal_on_the_next_bridged_write(): void
    {
        $widget = $this->widget();
        $this->refuseUuidChange($widget);
        $widget->syncOriginalAttribute('uuid');
        $widget->title = 'Next write';

        $widget->save();

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Next write', DB::table('gadgets')->value('name'));
    }

    private function widget(): Widget
    {
        return Widget::query()->create(['title' => 'Blue gadget', 'street' => '1 Main St']);
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
