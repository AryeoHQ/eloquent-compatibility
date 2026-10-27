<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Tests\Fixtures\Support\Workbench\Article;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Archive;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(CompatibilityWriteRefused::class)]
final class CompatibilityWriteRefusedTest extends TestCase
{
    private const string SECOND = '00000000-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
    }

    #[Test]
    public function it_states_what_it_refused_why_and_what_to_do_instead(): void
    {
        $previous = new RuntimeException('The database said no.');

        $refusal = new CompatibilityWriteRefused('Refused a change to street', 'A widget has one street.', 'Change the place instead.', $previous);

        $this->assertSame('Refused a change to street. Why: A widget has one street. Instead: Change the place instead.', $refusal->getMessage());
        $this->assertSame(['Refused a change to street', 'A widget has one street.', 'Change the place instead.'], [$refusal->refused, $refusal->because, $refusal->instead]);
        $this->assertSame($previous, $refusal->getPrevious());
        $this->assertSame(0, $refusal->getCode());
        $this->assertInstanceOf(CompatibilityException::class, $refusal);
    }

    #[Test]
    public function it_counts_itself_and_a_query_refused_by_the_database_as_a_refusal_and_nothing_else(): void
    {
        $this->assertTrue(CompatibilityWriteRefused::isRefusal(new CompatibilityWriteRefused('Refused', 'Because.', 'Instead.')));
        $this->assertTrue(CompatibilityWriteRefused::isRefusal($this->secondRootPlace()));
        $this->assertFalse(CompatibilityWriteRefused::isRefusal($this->duplicateUuid()));
        $this->assertFalse(CompatibilityWriteRefused::isRefusal(InvalidCompatibilityMap::because('widgets', 'its event mirror is registered twice')));
        $this->assertFalse(CompatibilityWriteRefused::isRefusal(new RuntimeException('Refused', 0)));
    }

    #[Test]
    public function it_splits_a_refusal_the_database_raised_into_its_message_detail_and_hint(): void
    {
        $exception = $this->secondRootPlace();

        $refusal = CompatibilityWriteRefused::fromQueryException($exception);

        $this->assertNotNull($refusal);
        $this->assertSame("Compatibility map 'widgets' refused a second root place for one gadget in gadget_places record ".self::SECOND, $refusal->refused);
        $this->assertSame('A widget has one street, so a gadget may have only one place without a parent.', $refusal->because);
        $this->assertSame('Give the new place a parent_id, or soft-delete the current root place first.', $refusal->instead);
        $this->assertSame($exception, $refusal->getPrevious());
    }

    #[Test]
    public function it_leaves_a_part_the_database_did_not_raise_empty(): void
    {
        $refusal = CompatibilityWriteRefused::fromQueryException($this->failure(
            fn () => DB::unprepared("DO \$refuse\$ BEGIN RAISE EXCEPTION USING ERRCODE = 'CM001', MESSAGE = 'refused with no hint', DETAIL = 'Only a detail.'; END \$refuse\$"),
        ));

        $this->assertNotNull($refusal);
        $this->assertSame(['refused with no hint', 'Only a detail.', ''], [$refusal->refused, $refusal->because, $refusal->instead]);
    }

    #[Test]
    public function it_makes_no_refusal_of_a_query_that_failed_for_another_reason(): void
    {
        $this->assertNull(CompatibilityWriteRefused::fromQueryException($this->duplicateUuid()));
    }

    #[Test]
    public function it_blames_the_mirrored_listener_that_returned_false(): void
    {
        $gadget = Gadget::query()->create(['name' => 'Blue gadget']);

        $refusal = CompatibilityWriteRefused::byMirroredListener('widgets', $gadget, ModelEvent::Updating);

        $this->assertSame("Compatibility map 'widgets' refused this write, because a 'updating' listener of Gadget #{$gadget->id} returned false", $refusal->refused);
        $this->assertSame("The write changed Gadget #{$gadget->id} through the other side, and every listener of that side runs for it, so that side's rules refused the change.", $refusal->because);
        $this->assertSame("Make the write satisfy the rules of Gadget, or exclude the listener in the map's event mirror if it must not run for writes through the other side.", $refusal->instead);
        $this->assertNull($refusal->getPrevious());
        $this->assertStringContainsString("a 'creating' listener of Gadget (new) returned false", CompatibilityWriteRefused::byMirroredListener('widgets', new Gadget, ModelEvent::Creating)->getMessage());
    }

    #[Test]
    public function it_names_the_columns_a_mirrored_listener_changed(): void
    {
        $gadget = Gadget::query()->create(['name' => 'Blue gadget']);

        $refusal = CompatibilityWriteRefused::byMirroredChange('widgets', $gadget, ModelEvent::Updated, ['name', 'price_cents']);

        $this->assertSame("Compatibility map 'widgets' refused this write, because a 'updated' listener of Gadget #{$gadget->id} changed name, price_cents", $refusal->refused);
        $this->assertSame('Mirrored events fire after the write through the other side has reached the database, so a change a listener makes to the mirrored record cannot be stored.', $refusal->because);
        $this->assertSame("Make the change through Gadget itself, or exclude the listener in the map's event mirror.", $refusal->instead);
        $this->assertStringContainsString("a 'created' listener of Gadget (new) changed name.", CompatibilityWriteRefused::byMirroredChange('widgets', new Gadget, ModelEvent::Created, ['name'])->getMessage());
    }

    #[Test]
    public function it_names_the_previous_models_attribute_and_the_state_column_a_replay_could_not_change_and_keeps_the_replay_failure(): void
    {
        $gadget = Gadget::query()->create(['name' => 'Blue gadget']);
        $previous = new RuntimeException('Invalid transition from [Published] to [Draft] on [Gadget].');

        $refusal = CompatibilityWriteRefused::byTransition('articles', $gadget, 'state', Article::class, 'status', $previous);

        $this->assertSame("Compatibility map 'articles' refused this write, because it changes status of Article, stored as state of Gadget #{$gadget->id}, in a way its state machine cannot replay", $refusal->refused);
        $this->assertSame('A state changes only through the trigger its #[Transition] names, so the event bridge replays that trigger for a write through the other side, and the replay failed: Invalid transition from [Published] to [Draft] on [Gadget].', $refusal->because);
        $this->assertSame("Change status of Article to a state the stored state has a #[Transition] to, whose trigger allows the change and receives in the map's triggerArguments() every constructor argument the container cannot resolve, or run the trigger on Gadget itself.", $refusal->instead);
        $this->assertSame($previous, $refusal->getPrevious());

        $unsaved = CompatibilityWriteRefused::byTransition('articles', new Gadget, 'review', Article::class, 'review_status', new RuntimeException('Unresolvable dependency'));

        $this->assertStringContainsString('because it changes review_status of Article, stored as review of Gadget (new), in a way', $unsaved->refused);
        $this->assertStringEndsWith('the replay failed: Unresolvable dependency.', $unsaved->because);
    }

    #[Test]
    public function it_names_the_trigger_argument_whose_closure_threw_and_keeps_the_closure_failure(): void
    {
        $gadget = Gadget::query()->create(['name' => 'Blue gadget']);
        $previous = new RuntimeException('No reason.');

        $refusal = CompatibilityWriteRefused::byTriggerArgument('bulletins', $gadget, Archive::class, 'reason', $previous);

        $this->assertSame("Compatibility map 'bulletins' refused this write, because its triggerArguments() closure for argument 'reason' of ".Archive::class." threw for Gadget #{$gadget->id}", $refusal->refused);
        $this->assertSame('The event bridge builds the declared arguments of a state change\'s trigger before it calls the trigger, so the trigger cannot run without them: No reason.', $refusal->because);
        $this->assertSame("Fix the closure for 'reason' in the map's triggerArguments() for ".Archive::class.', so that it returns a value for this write, or run the trigger on Gadget itself.', $refusal->instead);
        $this->assertSame($previous, $refusal->getPrevious());

        $unsaved = CompatibilityWriteRefused::byTriggerArgument('bulletins', new Gadget, Archive::class, 'until', new RuntimeException('No date'));

        $this->assertStringEndsWith("for argument 'until' of ".Archive::class.' threw for Gadget (new)', $unsaved->refused);
        $this->assertStringEndsWith('the trigger cannot run without them: No date.', $unsaved->because);
    }

    private function secondRootPlace(): QueryException
    {
        $gadget = Gadget::query()->create(['name' => 'Blue gadget']);
        DB::table('gadget_places')->insert(['gadget_id' => $gadget->id, 'street' => '1 Main St']);

        return $this->failure(fn () => DB::table('gadget_places')->insert(['id' => self::SECOND, 'gadget_id' => $gadget->id, 'street' => '2 Second St']));
    }

    private function duplicateUuid(): QueryException
    {
        $uuid = DB::table('retained_widgets')->insertGetId(['status' => 'Draft']);
        $taken = DB::table('retained_widgets')->where('id', $uuid)->value('uuid');

        return $this->failure(fn () => DB::table('retained_widgets')->insert(['uuid' => $taken]));
    }

    /**
     * @param  Closure(): mixed  $statement
     */
    private function failure(Closure $statement): QueryException
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Expected the statement to fail.');
    }
}
