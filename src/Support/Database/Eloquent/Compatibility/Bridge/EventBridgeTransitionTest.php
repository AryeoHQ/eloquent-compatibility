<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Bridge\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Exceptions\Invalid;
use Support\Database\Eloquent\StateMachines\Triggers\Exceptions\Duplicate;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Article;
use Tests\Fixtures\Support\Workbench\ArticleCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Bulletin;
use Tests\Fixtures\Support\Workbench\BulletinCompatibilityMap;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Placement;
use Tests\Fixtures\Support\Workbench\Publication;
use Tests\Fixtures\Support\Workbench\PublicationState\Events\Transitioned;
use Tests\Fixtures\Support\Workbench\PublicationState\Events\Transitioning;
use Tests\Fixtures\Support\Workbench\PublicationState\PublicationState;
use Tests\Fixtures\Support\Workbench\ReviewState\Events\Reviewed;
use Tests\Fixtures\Support\Workbench\StatefulBulletin;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;
use Throwable;

#[CoversClass(EventBridge::class)]
#[CoversClass(EventReplayer::class)]
#[CoversClass(CompatibilityWriteRefused::class)]
#[RequiresMethod(Trigger::class, 'withoutTransition')]
final class EventBridgeTransitionTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    /** @var list<string> */
    private array $seen = [];

    /** @var list<string> */
    private array $statements = [];

    private Journal $journal;

    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        resolve(Installer::class)->install(new ArticleCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new ArticleCompatibilityMap));
        $this->journal = new Journal;
        $this->app->instance(Journal::class, $this->journal);
        Event::listen(Transitioning::class, fn (Transitioning $event) => $this->seen[] = "before {$event->publication->getAttribute('state')}");
        Event::listen(Transitioned::class, fn (Transitioned $event) => $this->seen[] = "after {$event->publication->getAttribute('state')}");
    }

    #[Test]
    public function it_runs_the_trigger_of_a_state_change_through_the_previous_model_once_and_reports_each_change_once_per_side_as_a_native_transition_does(): void
    {
        $native = $this->article();
        $article = $this->article();
        Event::listen('eloquent.updated: '.Publication::class, fn (Publication $publication) => $this->seen[] = 'publication updated: '.$this->changed($publication, ['state', 'published_at']));
        Event::listen('eloquent.updated: '.Article::class, fn (Article $updated) => $this->seen[] = 'article updated: '.$this->changed($updated, ['status', 'published_at']));

        DB::transaction(fn () => Publication::query()->findOrFail($native->publication_id)->state->publish()->now());
        $natively = $this->seen;
        $this->seen = [];
        $this->journal->entries = [];
        $this->recordMirroredEvents();
        DB::listen(fn (QueryExecuted $query) => $this->statements[] = $query->sql);

        DB::transaction(function () use ($article): void {
            $article->update(['status' => 'published']);
            $this->seen[] = 'written';
        });

        $this->assertSame(['before draft', 'article updated: status, published_at', 'publication updated: state, published_at', 'after published'], $natively);
        $this->assertSame([
            'before draft',
            'article updated: published_at',
            'publication updated: published_at',
            'publication updated: state',
            'article updated: status',
            'written',
            'after published',
        ], $this->seen);
        $this->assertSame(['Publication '.json_encode($article->publication_id)], $this->journal->entries);
        $this->assertSame([[Article::class, Publication::class], [Publication::class, Article::class]], array_map(fn (RecordMirrored $event) => [$event->destination::class, $event->source::class], $this->mirroredEvents()));
        $this->assertSame(['draft', 'published'], [$this->mirroredEvents()[1]->rowBefore['state'] ?? null, $this->mirroredEvents()[1]->rowAfter['state'] ?? null]);
        $this->assertNotNull($article->published_at);
        $this->assertSame($article->published_at, DB::table('articles')->where('id', $article->id)->value('published_at'));
        $this->assertSame([], array_values(array_filter($this->statements, fn (string $sql) => preg_match('/^update "publications" set .*"state"/', $sql) === 1)));
        $this->assertSame(['published', true], [DB::table('publications')->where('id', $article->publication_id)->value('state'), DB::table('publications')->where('id', $article->publication_id)->value('published_at') !== null]);
    }

    #[Test]
    public function it_records_the_transition_events_and_the_model_events_of_a_replay_under_a_fake(): void
    {
        $article = $this->article();
        Event::fake([Transitioning::class, Transitioned::class, 'eloquent.updated: '.Publication::class]);

        DB::transaction(fn () => $article->update(['status' => 'published']));

        Event::assertDispatched(Transitioning::class, 1);
        Event::assertDispatched(Transitioned::class, 1);
        Event::assertDispatched('eloquent.updated: '.Publication::class, 2);
        $this->assertSame([], $this->seen);
        $this->assertSame(['Publication '.json_encode($article->publication_id)], $this->journal->entries);
        $this->assertSame('published', DB::table('publications')->value('state'));
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: null|string, 3: string, 4: class-string<Throwable>}>
     */
    public static function unreplayableChanges(): array
    {
        return [
            'no matching transition' => ['published', false, 'draft', 'Invalid transition from [Published] to [Draft] on [Publication].', Invalid::class],
            'two matching transitions' => ['archived', false, 'draft', 'Multiple [Draft] triggers defined on [Archived]', Duplicate::class],
            'a blocked transition' => ['draft', true, 'published', 'Invalid transition from [Draft] to [Published] on [Publication].', Invalid::class],
            'a trigger the container cannot build' => ['draft', false, 'archived', 'Unresolvable dependency resolving', BindingResolutionException::class],
            'a state the enum has no case for' => ['draft', false, 'pending_review', "['pending_review'] is not a case of [PublicationState].", InvalidArgumentException::class],
            'no state' => ['draft', false, null, '[NULL] is not a case of [PublicationState].', InvalidArgumentException::class],
        ];
    }

    /**
     * @param  class-string<Throwable>  $previous
     */
    #[DataProvider('unreplayableChanges')]
    #[Test]
    public function it_refuses_and_rolls_back_the_whole_write_when_the_state_change_cannot_run_its_trigger(string $stored, bool $locked, null|string $written, string $because, string $previous): void
    {
        $article = $this->article();
        DB::table('publications')->update(['state' => $stored, 'locked' => $locked]);
        $article->refresh();
        $this->recordMirroredEvents();

        $refusal = $this->assertWriteRefused(fn () => $article->update(['title' => 'Refused', 'status' => $written]), "refused this write, because it changes state of Publication #{$article->publication_id}");

        $this->assertStringContainsString($because, $refusal->because);
        $this->assertInstanceOf($previous, $refusal->getPrevious());
        $this->assertSame([$stored, 'Draft article'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
        $this->assertSame([], $this->seen);
        $this->assertSame([], $this->journal->entries);
        $this->assertNothingMirrored();
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_rolls_back_the_whole_write_when_the_trigger_throws(): void
    {
        $article = $this->article();
        $this->recordMirroredEvents();
        Event::listen(Transitioning::class, fn () => throw new RuntimeException('The before event failed.'));

        $this->assertThrows(fn () => DB::transaction(fn () => $article->update(['title' => 'Thrown', 'status' => 'published'])), RuntimeException::class, 'The before event failed.');

        $this->assertSame(['draft', 'Draft article', null], [DB::table('publications')->value('state'), DB::table('publications')->value('title'), DB::table('publications')->value('published_at')]);
        $this->assertSame([], $this->journal->entries);
        $this->assertNothingMirrored();
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_mirrors_an_explicit_save_inside_the_trigger_as_a_native_write(): void
    {
        $article = $this->article();
        DB::table('publications')->update(['state' => 'published']);
        $article->refresh();
        $this->recordMirroredEvents();

        $article->update(['status' => 'featured']);

        $this->assertMirrored(Article::class, ModelEvent::Updated, fn (Model $mirrored, RecordMirrored $event) => $event->source instanceof Publication && $mirrored->getAttribute('featured_at') !== null && ($event->rowBefore['featured_at'] ?? null) === null && ! $mirrored->wasChanged('status'));
        $this->assertMirrored(Publication::class, ModelEvent::Updated, fn (Model $mirrored) => $mirrored->wasChanged('state') && ! $mirrored->wasChanged('featured_at'));
        $this->assertCount(2, $this->mirroredEvents());
        $this->assertSame(['before published', 'after featured'], $this->seen);
        $this->assertNotNull($article->featured_at);
        $this->assertSame(['featured', true], [DB::table('publications')->value('state'), DB::table('publications')->value('featured_at') !== null]);
    }

    #[Test]
    public function it_reports_each_change_of_an_explicit_save_inside_the_trigger_once_per_side_as_a_native_trigger_does(): void
    {
        $native = $this->article();
        $article = $this->article();
        DB::table('publications')->update(['state' => 'published']);
        $native->refresh();
        $article->refresh();
        Event::listen('eloquent.updated: '.Publication::class, fn (Publication $publication) => $this->seen[] = 'publication: '.$this->changed($publication, ['state', 'title', 'featured_at']));
        Event::listen('eloquent.updated: '.Article::class, fn (Article $updated) => $this->seen[] = 'article: '.$this->changed($updated, ['status', 'title', 'featured_at']));

        Publication::query()->findOrFail($native->publication_id)->state->feature()->now();
        $natively = $this->seen;
        $this->seen = [];
        DB::transaction(fn () => $article->update(['status' => 'featured', 'title' => 'Featured article']));

        $this->assertSame(['before published', 'article: featured_at', 'publication: featured_at', 'article: status', 'publication: state', 'after featured'], $natively);
        $this->assertSame(['before published', 'article: featured_at', 'publication: featured_at', 'publication: state, title', 'article: status, title', 'after featured'], $this->seen);
        $this->assertNotNull($article->featured_at);
    }

    #[Test]
    public function it_reports_a_dependent_model_saved_inside_the_trigger_once_per_side_as_a_native_trigger_does(): void
    {
        $this->mirrorBulletins();
        $native = Bulletin::query()->create(['title' => 'Native bulletin', 'slot' => 'side'])->refresh();
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        Event::listen('eloquent.updated: '.Publication::class, fn (Publication $publication) => $this->seen[] = 'publication: '.$this->changed($publication, ['review']));
        Event::listen('eloquent.updated: '.Placement::class, fn (Placement $placement) => $this->seen[] = 'placement: '.$this->changed($placement, ['slot']));
        Event::listen('eloquent.updated: '.Bulletin::class, fn (Bulletin $updated) => $this->seen[] = 'bulletin: '.$this->changed($updated, ['review_status', 'slot']));

        Publication::query()->findOrFail($native->publication_id)->review->approve()->now();
        $natively = $this->seen;
        $this->seen = [];
        $bulletin->update(['review_status' => 'approved']);

        $this->assertSame(['bulletin: slot', 'placement: slot', 'bulletin: review_status', 'publication: review'], $natively);
        $this->assertSame(['bulletin: slot', 'placement: slot', 'publication: review', 'bulletin: review_status'], $this->seen);
        $this->assertSame('front', $bulletin->getAttribute('slot'));
    }

    #[Test]
    public function it_mirrors_a_native_transition_to_a_previous_model_that_casts_the_mapped_state_column_to_the_state_machine(): void
    {
        $this->mirrorBulletins(StatefulBulletin::class);
        $bulletin = StatefulBulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        $this->recordMirroredEvents();

        Publication::query()->findOrFail($bulletin->publication_id)->state->publish()->now();

        $this->assertMirrored(StatefulBulletin::class, ModelEvent::Updated, fn (Model $mirrored) => $mirrored->wasChanged('status') && $mirrored->getRawOriginal('status') === 'published');
        $this->assertSame(['before draft', 'after published'], $this->seen);
        $this->assertSame('published', DB::table('bulletins')->value('status'));
    }

    #[Test]
    public function it_runs_the_trigger_of_a_state_change_through_a_previous_model_that_casts_the_mapped_state_column_to_the_state_machine(): void
    {
        $this->mirrorBulletins(StatefulBulletin::class);
        $bulletin = StatefulBulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();

        $bulletin->update(['status' => PublicationState::Published]);

        $this->assertSame(['before draft', 'after published'], $this->seen);
        $this->assertSame(['Publication '.json_encode($bulletin->publication_id)], $this->journal->entries);
        $this->assertSame(['published', 'published', true], [DB::table('bulletins')->value('status'), DB::table('publications')->value('state'), DB::table('publications')->value('published_at') !== null]);
    }

    #[Test]
    public function it_mirrors_a_state_written_directly_on_the_current_model_without_running_a_trigger(): void
    {
        $article = $this->article();
        DB::table('publications')->update(['state' => 'published']);
        $article->refresh();
        $this->recordMirroredEvents();

        Publication::query()->findOrFail($article->publication_id)->update(['state' => PublicationState::Draft]);

        $this->assertMirrored(Article::class, ModelEvent::Updated, fn (Model $mirrored) => $mirrored->wasChanged('status') && $mirrored->getAttribute('status') === 'draft');
        $this->assertSame([], $this->seen);
        $this->assertSame([], $this->journal->entries);
        $this->assertSame(['draft', 'draft'], [DB::table('publications')->value('state'), DB::table('articles')->value('status')]);
    }

    #[Test]
    public function it_repairs_a_state_through_the_previous_model_inside_without_mirroring_without_running_a_trigger(): void
    {
        $article = $this->article();
        DB::table('publications')->update(['state' => 'published']);
        $article->refresh();
        $this->recordMirroredEvents();
        Event::listen('eloquent.updated: '.Publication::class, fn () => $this->seen[] = 'publication updated');

        $this->withoutEventMirroring(fn () => $article->update(['status' => 'draft']));

        $this->assertNothingMirrored();
        $this->assertSame([], $this->seen);
        $this->assertSame([], $this->journal->entries);
        $this->assertSame('draft', DB::table('publications')->value('state'));
    }

    #[Test]
    public function it_runs_the_trigger_of_each_state_column_one_write_moves(): void
    {
        $this->mirrorBulletins();
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        DB::table('publications')->update(['state' => 'published']);
        $bulletin->refresh();
        Event::listen(Reviewed::class, fn (Reviewed $event) => $this->seen[] = "reviewed {$event->publication->getAttribute('review')}");
        Event::listen('eloquent.updated: '.Publication::class, fn (Publication $publication) => $this->seen[] = 'publication: '.$this->changed($publication, ['state', 'review', 'featured_at']));

        $bulletin->update(['status' => 'featured', 'review_status' => 'approved']);

        $this->assertSame(['before published', 'publication: featured_at', 'publication: state, review', 'after featured', 'reviewed approved'], $this->seen);
        $this->assertSame(['featured', 'approved', true], [DB::table('publications')->value('state'), DB::table('publications')->value('review'), DB::table('publications')->value('featured_at') !== null]);
        $this->assertSame('front', DB::table('placements')->value('slot'));
    }

    #[Test]
    public function it_leaves_the_updated_at_of_the_current_model_as_the_write_left_it_when_the_trigger_saves_nothing(): void
    {
        $this->mirrorBulletins();
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        DB::table('publications')->update(['updated_at' => '2020-01-01 00:00:00']);
        $stored = DB::table('publications')->value('updated_at');
        $this->recordMirroredEvents();
        DB::listen(fn (QueryExecuted $query) => $this->statements[] = $query->sql);

        $bulletin->update(['review_status' => 'approved']);

        $this->assertMirrored(Publication::class, ModelEvent::Updated, fn (Model $publication) => $publication->wasChanged('review') && ! $publication->wasChanged('updated_at'));
        $this->assertSame($stored, DB::table('publications')->value('updated_at'));
        $this->assertSame([], array_values(array_filter($this->statements, fn (string $sql) => str_starts_with($sql, 'update "publications"'))));
    }

    #[Test]
    public function it_fires_no_after_event_and_keeps_the_stored_state_when_the_callers_transaction_fails_after_the_trigger(): void
    {
        $article = $this->article();

        try {
            DB::transaction(function () use ($article): void {
                $article->update(['status' => 'published']);

                throw new RuntimeException('The caller failed.');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('The caller failed.', $e->getMessage());
        }

        $this->assertSame(['before draft'], $this->seen);
        $this->assertSame(['Publication '.json_encode($article->publication_id)], $this->journal->entries);
        $this->assertSame(['draft', null], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at')]);
    }

    #[Test]
    public function it_runs_no_trigger_for_a_write_through_the_previous_model_that_leaves_the_state_alone(): void
    {
        $article = $this->article();
        $this->recordMirroredEvents();
        DB::listen(fn (QueryExecuted $query) => $this->statements[] = $query->sql);

        $article->update(['title' => 'Renamed']);

        $this->assertMirrored(Publication::class, ModelEvent::Updated, fn (Model $publication) => $publication->getAttribute('title') === 'Renamed');
        $this->assertSame([ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved], $this->mirroredEvents()[0]->events);
        $this->assertSame([], $this->seen);
        $this->assertSame([], array_values(array_filter($this->statements, fn (string $sql) => str_starts_with($sql, 'update "publications"'))));
    }

    #[Test]
    public function it_leaves_the_mirrored_events_of_a_map_without_a_state_machine_unchanged(): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new WidgetCompatibilityMap));
        $widget = Widget::query()->create(['title' => 'Blue gadget', 'street' => '1 Main St']);
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed', 'status' => 'Live']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertCount(1, $this->mirroredEvents());
        $this->assertSame([ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved], $this->mirroredEvents()[0]->events);
        $this->assertSame([], $this->seen);
    }

    private function article(): Article
    {
        return Article::query()->create(['title' => 'Draft article'])->refresh();
    }

    /**
     * @param  class-string<Model>  $bulletin
     */
    private function mirrorBulletins(string $bulletin = Bulletin::class): void
    {
        resolve(Installer::class)->install(new BulletinCompatibilityMap($bulletin));
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new BulletinCompatibilityMap($bulletin)));
    }

    /**
     * @param  list<string>  $attributes
     */
    private function changed(Model $model, array $attributes): string
    {
        return implode(', ', array_values(array_filter($attributes, fn (string $attribute) => $model->wasChanged($attribute))));
    }
}
