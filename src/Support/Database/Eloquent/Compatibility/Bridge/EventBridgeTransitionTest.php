<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Closure;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Support\Actions\Bus\Exceptions\Prevented;
use Support\Actions\Pipeline\Exceptions\Interrupted;
use Support\Database\Eloquent\Compatibility\Bridge\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityEventMirrorBypassed;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Exceptions\Invalid;
use Support\Database\Eloquent\StateMachines\Triggers\Exceptions\Duplicate;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Article;
use Tests\Fixtures\Support\Workbench\ArticleCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Bulletin;
use Tests\Fixtures\Support\Workbench\BulletinCompatibilityMap;
use Tests\Fixtures\Support\Workbench\CastBulletin;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\NotifyingPublication;
use Tests\Fixtures\Support\Workbench\Placement;
use Tests\Fixtures\Support\Workbench\Publication;
use Tests\Fixtures\Support\Workbench\PublicationChanged;
use Tests\Fixtures\Support\Workbench\PublicationSaved;
use Tests\Fixtures\Support\Workbench\PublicationState\Events\Transitioned;
use Tests\Fixtures\Support\Workbench\PublicationState\Events\Transitioning;
use Tests\Fixtures\Support\Workbench\PublicationState\PublicationState;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Archive;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Feature;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Pin;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Reserve;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Schedule;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Withdraw;
use Tests\Fixtures\Support\Workbench\ReviewState\Events\Reviewed;
use Tests\Fixtures\Support\Workbench\StatefulBulletin;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;
use Throwable;
use TypeError;

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

    /** @var list<string> */
    private array $audited = [];

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
            'two transitions whose triggers share a class basename' => ['scheduled', false, 'published', 'Multiple [release] triggers defined on [Scheduled]', Duplicate::class],
            'a blocked transition' => ['draft', true, 'published', 'Invalid transition from [Draft] to [Published] on [Publication].', Invalid::class],
            'a trigger the container cannot build' => ['draft', false, 'archived', 'Unresolvable dependency resolving', BindingResolutionException::class],
            'a trigger that takes a model argument without a default' => ['draft', false, 'scheduled', '[Schedule] takes the model argument [$placement] without a default, and the container builds it as an empty [Placement].', InvalidArgumentException::class],
            'a trigger that takes a nullable model argument without a default' => ['draft', false, 'pinned', '[Pin] takes the model argument [$placement] without a default, and the container builds it as an empty [Placement].', InvalidArgumentException::class],
            'a trigger whose middleware declines' => ['draft', false, 'embargoed', 'The [Embargo] operation was interrupted by the [Decline] middleware.', Interrupted::class],
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

        $refusal = $this->assertWriteRefused(fn () => $article->update(['title' => 'Refused', 'status' => $written]), "refused this write, because it changes status of Article, stored as state of Publication #{$article->publication_id}, in a way");

        $this->assertStringContainsString($because, $refusal->because);
        $this->assertInstanceOf($previous, $refusal->getPrevious());
        $this->assertSame([$stored, 'Draft article'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
        $this->assertSame([], $this->seen);
        $this->assertSame([], $this->journal->entries);
        $this->assertNothingMirrored();
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_refuses_and_rolls_back_the_whole_write_when_another_run_holds_the_unique_lock_of_the_trigger(): void
    {
        $article = $this->article();
        $this->recordMirroredEvents();
        Cache::lock(UniqueLock::getKey(new Reserve), 10)->get();

        $refusal = $this->assertWriteRefused(fn () => $article->update(['title' => 'Refused', 'status' => 'reserved']), "state of Publication #{$article->publication_id}");

        $this->assertInstanceOf(Prevented::class, $refusal->getPrevious());
        $this->assertSame(['draft', 'Draft article'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
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
    public function it_runs_the_trigger_with_the_arguments_the_map_builds_from_the_current_and_the_previous_model(): void
    {
        $received = [];
        $this->mirrorBulletins(publication: $this->archivedWith(['reason' => function (Publication $publication, Bulletin $bulletin) use (&$received): string {
            $received = [$publication, $bulletin, $publication->getAttributes()['state'] ?? null, $publication->getAttribute('title')];

            return "retired from {$bulletin->getAttribute('slot')}";
        }]));
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();

        $bulletin->update(['title' => 'Retired bulletin', 'status' => 'archived']);

        $this->assertSame(['archived', 'Retired bulletin (retired from side)'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
        $this->assertInstanceOf(Publication::class, $received[0]);
        $this->assertSame($bulletin->publication_id, $received[0]->getKey());
        $this->assertSame([$bulletin, 'draft', 'Retired bulletin'], array_slice($received, 1));
        $this->assertSame(['before draft', 'after archived'], $this->seen);
    }

    #[Test]
    public function it_registers_and_runs_a_trigger_whose_other_parameter_the_container_resolves_through_a_contextual_attribute(): void
    {
        config(['bulletins.desk' => 'the news desk']);
        $this->mirrorBulletins(publication: PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication')->triggerArguments(Withdraw::class, ['reason' => fn (): string => 'withdrawn']));
        $bulletin = Bulletin::query()->create(['title' => 'Published bulletin', 'slot' => 'side'])->refresh();
        DB::table('publications')->update(['state' => 'published']);
        $bulletin->refresh();

        $bulletin->update(['status' => 'archived']);

        $this->assertSame(['archived', 'Published bulletin (withdrawn by the news desk)'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
    }

    #[Test]
    public function it_runs_a_trigger_the_map_declares_no_arguments_for_without_arguments(): void
    {
        $calls = 0;
        $this->mirrorBulletins(publication: $this->archivedWith(['reason' => function () use (&$calls): string {
            $calls++;

            return 'unused';
        }]));
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();

        $bulletin->update(['status' => 'published']);

        $this->assertSame(0, $calls);
        $this->assertSame(['before draft', 'after published'], $this->seen);
        $this->assertSame(['Publication '.json_encode($bulletin->publication_id)], $this->journal->entries);
        $this->assertSame(['published', 'Draft bulletin'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
    }

    #[Test]
    public function it_refuses_and_rolls_back_the_whole_write_when_a_declared_argument_throws(): void
    {
        $this->mirrorBulletins(publication: $this->archivedWith(['reason' => fn (): string => throw new RuntimeException('No reason.')]));
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        $this->recordMirroredEvents();

        $refusal = $this->assertWriteRefused(fn () => $bulletin->update(['title' => 'Refused', 'status' => 'archived']), "Compatibility map 'bulletins' refused this write, because its triggerArguments() closure for argument 'reason' of ".Archive::class." threw for Publication #{$bulletin->publication_id}");

        $this->assertInstanceOf(RuntimeException::class, $refusal->getPrevious());
        $this->assertStringContainsString('No reason', $refusal->because);
        $this->assertStringContainsString("Fix the closure for 'reason' in the map's triggerArguments() for ".Archive::class, $refusal->instead);
        $this->assertSame(['draft', 'Draft bulletin'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
        $this->assertSame([], $this->seen);
        $this->assertNothingMirrored();
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_refuses_and_rolls_back_the_whole_write_when_a_declared_argument_returns_a_value_the_trigger_cannot_take(): void
    {
        $this->mirrorBulletins(publication: $this->archivedWith(['reason' => fn () => null]));
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        $this->recordMirroredEvents();

        $refusal = $this->assertWriteRefused(fn () => $bulletin->update(['title' => 'Refused', 'status' => 'archived']), "refused this write, because it changes status of Bulletin, stored as state of Publication #{$bulletin->publication_id}, in a way");

        $this->assertInstanceOf(TypeError::class, $refusal->getPrevious());
        $this->assertStringContainsString('($reason) must be of type string, null given', $refusal->because);
        $this->assertSame(['draft', 'Draft bulletin'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
        $this->assertSame([], $this->seen);
        $this->assertNothingMirrored();
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_rolls_back_the_whole_write_and_passes_a_type_error_from_the_running_trigger_to_the_caller_unchanged(): void
    {
        $this->mirrorBulletins(publication: $this->archivedWith(['reason' => fn (): string => 'retired']));
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        $this->recordMirroredEvents();
        Event::listen(Transitioning::class, fn () => throw new TypeError('The before event failed.'));

        $this->assertThrows(fn () => DB::transaction(fn () => $bulletin->update(['title' => 'Thrown', 'status' => 'archived'])), TypeError::class, 'The before event failed.');

        $this->assertSame(['draft', 'Draft bulletin'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
        $this->assertNothingMirrored();
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    #[TestWith(['placement'])]
    #[TestWith(['PLACEMENT'])]
    public function it_runs_a_trigger_whose_model_argument_without_a_default_the_map_declares(string $argument): void
    {
        $this->mirrorBulletins(publication: PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication')->triggerArguments(Schedule::class, [
            $argument => fn (Publication $publication): Placement => Placement::query()->where('publication_id', $publication->getKey())->sole(),
        ]));
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();

        $bulletin->update(['status' => 'scheduled']);

        $this->assertSame(['scheduled', 'Draft bulletin (side)'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
        $this->assertSame(['before draft', 'after scheduled'], $this->seen);
        $this->assertSame(['Publication '.json_encode($bulletin->publication_id)], $this->journal->entries);
    }

    #[Test]
    public function it_refuses_a_trigger_whose_model_argument_without_a_default_the_map_declares_only_for_another_trigger(): void
    {
        $this->mirrorBulletins(publication: PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication')->triggerArguments(Pin::class, [
            'placement' => fn (Publication $publication): Placement => Placement::query()->where('publication_id', $publication->getKey())->sole(),
        ]));
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();

        $refusal = $this->assertWriteRefused(fn () => $bulletin->update(['status' => 'scheduled']), "stored as state of Publication #{$bulletin->publication_id}");

        $this->assertStringContainsString('[Schedule] takes the model argument [$placement] without a default', $refusal->because);
        $this->assertSame([], $this->journal->entries);
        $this->assertSame('draft', DB::table('publications')->value('state'));
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

    #[Test]
    public function it_keeps_the_cast_object_the_caller_holds_and_reports_its_change_when_the_trigger_saves_a_mapped_column(): void
    {
        $this->mirrorBulletins(CastBulletin::class);
        $bulletin = CastBulletin::query()->create(['title' => [], 'slot' => 'side'])->refresh();
        $held = $bulletin->title;
        $held['source'] = 'import';

        $bulletin->update(['review_status' => 'approved']);

        $this->assertTrue($bulletin->wasChanged('title'));
        $this->assertSame($held, $bulletin->title);
        $this->assertSame('front', (string) $bulletin->slot);

        $held['revision'] = 2;
        $bulletin->save();

        $this->assertSame(['source' => 'import', 'revision' => 2], json_decode((string) DB::table('publications')->value('title'), true));
    }

    #[Test]
    public function it_drops_the_cached_cast_of_a_column_the_trigger_changes_so_a_later_save_keeps_the_triggers_value(): void
    {
        $this->mirrorBulletins(CastBulletin::class);
        $bulletin = CastBulletin::query()->create(['title' => [], 'slot' => 'side'])->refresh();
        $this->assertSame('side', (string) $bulletin->slot);

        $bulletin->update(['review_status' => 'approved']);

        $this->assertSame('front', (string) $bulletin->slot);

        $bulletin->save();

        $this->assertSame('front', DB::table('placements')->value('slot'));
    }

    #[Test]
    public function it_skips_a_listener_the_map_excludes_for_every_event_of_the_triggers_own_save_and_runs_it_for_a_native_save(): void
    {
        $this->mirrorBulletins(excludedListeners: [ExcludedListener::of(self::class, 'The audit records only writes through the current model.')]);
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        Event::listen('eloquent.saved: '.Publication::class, [$this, 'audit']);
        Event::listen('eloquent.saved: *', [$this, 'audit']);
        Event::listen(PublicationSaved::class, [$this, 'audit']);

        $bulletin->update(['status' => 'published']);

        $this->assertSame([], $this->audited);
        $this->assertSame(['published', true], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at') !== null]);

        Publication::query()->sole()->update(['title' => 'Renamed natively']);

        $this->assertSame(['PublicationSaved', 'Publication', 'wildcard Publication'], $this->audited);
    }

    #[Test]
    public function it_leaves_the_listeners_as_they_were_and_runs_the_trigger_while_the_map_excludes_a_saved_listener(): void
    {
        $this->mirrorBulletins(excludedListeners: [ExcludedListener::of(self::class.'@audit', 'The audit records only writes through the current model.')->forEvent(ModelEvent::Saved)]);
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        Event::listen('eloquent.saved: '.Publication::class, [$this, 'audit']);
        Event::listen(PublicationSaved::class, [$this, 'audit']);
        $listeners = $this->savedListeners();

        $bulletin->update(['status' => 'published']);

        $this->assertSame([], $this->audited);
        $this->assertSame(['before draft', 'after published'], $this->seen);
        $this->assertSame(['published', true], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at') !== null]);
        $this->assertSame($listeners, $this->savedListeners());
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[TestWith(['saved', ['PublicationChanged', 'updated published_at', 'saved published_at', 'PublicationChanged', 'updated state', 'saved state']])]
    #[TestWith(['updated', ['updated published_at', 'PublicationChanged', 'saved published_at', 'updated state', 'PublicationChanged', 'saved state']])]
    public function it_skips_a_listener_the_map_excludes_for_one_event_only_for_that_event_of_the_triggers_save_when_the_model_maps_both_to_one_class(string $excluded, array $expected): void
    {
        $this->mirrorBulletins(publication: NotifyingPublication::class, excludedListeners: [ExcludedListener::of(self::class.'@audit', 'The audit records only writes through the current model.')->forEvent(ModelEvent::from($excluded))]);
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        Event::listen(PublicationChanged::class, [$this, 'audit']);
        Event::listen('eloquent.updated: '.NotifyingPublication::class, fn (NotifyingPublication $publication) => $this->audited[] = 'updated '.$this->changed($publication, ['state', 'published_at']));
        Event::listen('eloquent.saved: '.NotifyingPublication::class, fn (NotifyingPublication $publication) => $this->audited[] = 'saved '.$this->changed($publication, ['state', 'published_at']));

        $bulletin->update(['status' => 'published']);

        $this->assertSame($expected, $this->audited);
        $this->assertSame(['published', true], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at') !== null]);
    }

    #[Test]
    public function it_refuses_and_rolls_back_the_whole_write_when_the_bus_fakes_the_trigger(): void
    {
        $article = $this->article();
        $this->recordMirroredEvents();
        Bus::fake();

        $this->assertThrows(fn () => DB::transaction(fn () => $article->update(['title' => 'Faked', 'status' => 'published'])), CompatibilityEventMirrorBypassed::class, 'Bus::fake()');

        Bus::assertNothingDispatched();
        $this->assertSame(['draft', 'Draft article', null], [DB::table('publications')->value('state'), DB::table('publications')->value('title'), DB::table('publications')->value('published_at')]);
        $this->assertSame([], $this->seen);
        $this->assertSame([], $this->journal->entries);
        $this->assertNothingMirrored();
    }

    #[Test]
    public function it_runs_the_trigger_under_a_bus_fake_that_fakes_other_jobs_only(): void
    {
        $article = $this->article();
        Bus::fake([Feature::class]);

        DB::transaction(fn () => $article->update(['status' => 'published']));

        $this->assertSame(['before draft', 'after published'], $this->seen);
        $this->assertSame(['Publication '.json_encode($article->publication_id)], $this->journal->entries);
        $this->assertSame(['published', true], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at') !== null]);
    }

    #[Test]
    public function it_reports_a_column_the_write_and_the_trigger_both_change_once_per_side_from_the_value_the_write_stored(): void
    {
        $article = $this->article();
        Event::listen('eloquent.updated: '.Publication::class, fn (Publication $publication) => $this->seen[] = 'publication: '.$this->changed($publication, ['state', 'published_at']).$this->publishedFrom($publication));
        Event::listen('eloquent.updated: '.Article::class, fn (Article $updated) => $this->seen[] = 'article: '.$this->changed($updated, ['status', 'published_at']).$this->publishedFrom($updated));

        DB::transaction(fn () => $article->update(['status' => 'published', 'published_at' => '2020-01-01 00:00:00']));

        $this->assertSame([
            'before draft',
            'article: published_at from 2020-01-01',
            'publication: published_at from 2020-01-01',
            'publication: state',
            'article: status',
            'after published',
        ], $this->seen);
        $this->assertArrayNotHasKey('published_at', $article->getChanges());
        $this->assertFalse($article->isDirty());
        $this->assertSame(DB::table('articles')->where('id', $article->id)->value('published_at'), $article->published_at);
        $this->assertStringStartsNotWith('2020', (string) $article->published_at);
    }

    #[Test]
    public function it_runs_the_trigger_of_each_state_column_on_an_instance_read_again_when_a_listener_refreshes_the_first(): void
    {
        $this->mirrorBulletins();
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        DB::table('publications')->update(['state' => 'published']);
        $bulletin->refresh();
        Event::listen(Transitioning::class, fn (Transitioning $event) => $event->publication->refresh());

        $bulletin->update(['status' => 'featured', 'review_status' => 'approved']);

        $this->assertSame(['before published', 'after featured'], $this->seen);
        $this->assertSame(['featured', 'approved', true], [DB::table('publications')->value('state'), DB::table('publications')->value('review'), DB::table('publications')->value('featured_at') !== null]);
        $this->assertSame('front', DB::table('placements')->value('slot'));
    }

    #[Test]
    public function it_reports_the_deletion_of_a_dependent_row_the_trigger_deletes_once_as_a_native_trigger_does(): void
    {
        $this->mirrorBulletins();
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        $this->recordMirroredEvents();
        Event::listen(Transitioning::class, fn (Transitioning $event) => Placement::query()->where('publication_id', $event->publication->getKey())->firstOrFail()->delete());
        Event::listen('eloquent.deleted: '.Placement::class, fn () => $this->seen[] = 'placement deleted');

        $bulletin->update(['status' => 'published']);

        $this->assertSame(['before draft', 'placement deleted', 'after published'], $this->seen);
        $this->assertSame([], array_values(array_filter($this->mirroredEvents(), fn (RecordMirrored $event) => $event->destination instanceof Placement)));
        $this->assertSame([0, 'published'], [DB::table('placements')->count(), DB::table('publications')->value('state')]);
    }

    #[Test]
    public function it_refuses_and_rolls_back_the_whole_write_when_a_listener_vetoes_the_triggers_save(): void
    {
        $article = $this->article();
        $this->recordMirroredEvents();
        Event::listen('eloquent.saving: '.Publication::class, fn (Publication $publication) => $publication->isDirty('published_at') ? false : null);

        $refusal = $this->assertWriteRefused(fn () => $article->update(['title' => 'Vetoed', 'status' => 'published']), "state of Publication #{$article->publication_id}");

        $this->assertStringEndsWith("the replay failed: The trigger's save of Publication was vetoed, as a saving or updating listener that returns false does.", $refusal->because);
        $this->assertSame(['draft', 'Draft article', null], [DB::table('publications')->value('state'), DB::table('publications')->value('title'), DB::table('publications')->value('published_at')]);
        $this->assertSame(['before draft'], $this->seen);
        $this->assertNothingMirrored();
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_refuses_and_rolls_back_the_whole_write_when_a_listener_vetoes_a_trigger_save_that_has_nothing_to_write(): void
    {
        $this->mirrorBulletins();
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();
        Event::listen('eloquent.saving: '.Publication::class, fn (Publication $publication) => $publication->isDirty() ? null : false);

        $refusal = $this->assertWriteRefused(fn () => $bulletin->update(['review_status' => 'approved']), "refused this write, because it changes review_status of Bulletin, stored as review of Publication #{$bulletin->publication_id},");

        $this->assertInstanceOf(RuntimeException::class, $refusal->getPrevious());
        $this->assertStringEndsWith("the replay failed: The trigger's save of Publication was vetoed, as a saving or updating listener that returns false does.", $refusal->because);
        $this->assertSame(['pending', 'side'], [DB::table('publications')->value('review'), DB::table('placements')->value('slot')]);
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_keeps_a_write_whose_saved_listener_changes_the_triggers_instance_without_saving_it_as_a_native_trigger_does(): void
    {
        $native = $this->article();
        $article = $this->article();
        Event::listen('eloquent.saved: '.Publication::class, fn (Publication $publication) => $publication->setAttribute('title', 'Changed after the save'));

        Publication::query()->findOrFail($native->publication_id)->state->publish()->now();
        $this->seen = [];
        DB::transaction(fn () => $article->update(['status' => 'published']));

        $this->assertSame(['before draft', 'after published'], $this->seen);
        $stored = DB::table('publications')->where('id', $article->publication_id);
        $this->assertSame(['published', 'Draft article', true], [$stored->value('state'), $stored->value('title'), $stored->value('published_at') !== null]);
    }

    #[Test]
    public function it_refuses_and_rolls_back_the_whole_write_when_a_listener_vetoes_the_triggers_last_save_after_handle_saved_the_instance(): void
    {
        $article = $this->article();
        DB::table('publications')->update(['state' => 'published']);
        $article->refresh();
        $this->recordMirroredEvents();
        Event::listen('eloquent.saving: '.Publication::class, fn (Publication $publication) => $publication->isDirty() ? null : false);

        $refusal = $this->assertWriteRefused(fn () => $article->update(['status' => 'featured']), "state of Publication #{$article->publication_id}");

        $this->assertStringEndsWith("the replay failed: The trigger's save of Publication was vetoed, as a saving or updating listener that returns false does.", $refusal->because);
        $this->assertSame(['published', null], [DB::table('publications')->value('state'), DB::table('publications')->value('featured_at')]);
        $this->assertSame(['published', null], [DB::table('articles')->value('status'), DB::table('articles')->value('featured_at')]);
        $this->assertSame(['before published'], $this->seen);
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_refuses_and_rolls_back_the_whole_write_when_a_saving_listener_saves_the_instance_and_then_vetoes_the_triggers_save(): void
    {
        $article = $this->article();
        $saved = false;
        Event::listen('eloquent.saving: '.Publication::class, function (Publication $publication) use (&$saved): null|false {
            if ($saved || ! $publication->isDirty('published_at')) {
                return null;
            }

            $saved = true;

            return $publication->save() ? false : null;
        });

        $refusal = $this->assertWriteRefused(fn () => $article->update(['status' => 'published']), "state of Publication #{$article->publication_id}");

        $this->assertTrue($saved);
        $this->assertStringEndsWith("the replay failed: The trigger's save of Publication was vetoed, as a saving or updating listener that returns false does.", $refusal->because);
        $this->assertSame(['draft', null], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at')]);
        $this->assertSame(['draft', null], [DB::table('articles')->value('status'), DB::table('articles')->value('published_at')]);
        $this->assertSame(['before draft'], $this->seen);
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    #[TestWith(['saving'])]
    #[TestWith(['updating'])]
    public function it_keeps_a_write_whose_trigger_saves_the_instance_once_vetoed_and_then_completes_its_own_save_as_a_native_trigger_does(string $vetoingEvent): void
    {
        $native = $this->article();
        $article = $this->article();
        DB::table('publications')->update(['state' => 'published']);
        $article->refresh();
        $vetoed = [];
        Event::listen("eloquent.{$vetoingEvent}: ".Publication::class, function (Publication $publication) use (&$vetoed): null|false {
            if (in_array($publication->getKey(), $vetoed, true) || ! $publication->isDirty('featured_at')) {
                return null;
            }

            $vetoed[] = $publication->getKey();

            return false;
        });

        Publication::query()->findOrFail($native->publication_id)->state->feature()->now();
        $this->seen = [];
        DB::transaction(fn () => $article->update(['status' => 'featured']));

        $this->assertSame([$native->publication_id, $article->publication_id], $vetoed);
        $this->assertSame(['before published', 'after featured'], $this->seen);

        foreach ([$native, $article] as $written) {
            $stored = DB::table('publications')->where('id', $written->publication_id);
            $this->assertSame(['featured', true], [$stored->value('state'), $stored->value('featured_at') !== null]);
        }
    }

    #[Test]
    public function it_keeps_a_write_whose_saved_listener_saves_the_instance_again_and_has_that_save_vetoed_as_a_native_trigger_does(): void
    {
        $native = $this->article();
        $article = $this->article();
        $resaves = [];
        Event::listen('eloquent.saved: '.Publication::class, function (Publication $publication) use (&$resaves): void {
            if (array_key_exists($publication->getKey(), $resaves) || ! $publication->wasChanged('published_at')) {
                return;
            }

            $resaves[$publication->getKey()] = null;
            $resaves[$publication->getKey()] = $publication->save();
        });
        Event::listen('eloquent.saving: '.Publication::class, function (Publication $publication) use (&$resaves): null|false {
            return array_key_exists($publication->getKey(), $resaves) && $resaves[$publication->getKey()] === null ? false : null;
        });

        Publication::query()->findOrFail($native->publication_id)->state->publish()->now();
        $this->seen = [];
        DB::transaction(fn () => $article->update(['status' => 'published']));

        $this->assertSame([$native->publication_id => false, $article->publication_id => false], $resaves);
        $this->assertSame(['before draft', 'after published'], $this->seen);

        foreach ([$native, $article] as $written) {
            $stored = DB::table('publications')->where('id', $written->publication_id);
            $this->assertSame(['published', true], [$stored->value('state'), $stored->value('published_at') !== null]);
        }
    }

    #[Test]
    public function it_refuses_and_rolls_back_the_whole_write_when_a_saving_listener_saves_the_instance_through_a_vetoed_increment_and_then_vetoes_the_triggers_save(): void
    {
        $article = $this->article();
        $step = 'final save';
        $results = [];
        Event::listen('eloquent.saving: '.Publication::class, function (Publication $publication) use (&$step, &$results): null|false {
            if ($step === 'final save' && $publication->isDirty('published_at')) {
                $step = 'nested save';
                $results[] = $publication->save();
                $step = 'done';

                return false;
            }

            if ($step === 'nested save') {
                $step = 'increment';
                $results[] = $publication->increment('locked', 0);
                $step = 'nested write';
            }

            return null;
        });
        Event::listen('eloquent.updating: '.Publication::class, function () use (&$step): null|false {
            return $step === 'increment' ? false : null;
        });

        $refusal = $this->assertWriteRefused(fn () => $article->update(['status' => 'published']), "state of Publication #{$article->publication_id}");

        $this->assertSame([false, true], $results);
        $this->assertStringEndsWith("the replay failed: The trigger's save of Publication was vetoed, as a saving or updating listener that returns false does.", $refusal->because);
        $this->assertSame(['draft', null], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at')]);
        $this->assertSame(['draft', null], [DB::table('articles')->value('status'), DB::table('articles')->value('published_at')]);
        $this->assertSame(['before draft'], $this->seen);
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_keeps_a_write_whose_saved_listener_after_a_clean_trigger_save_has_an_increment_vetoed_as_a_native_trigger_does(): void
    {
        $native = $this->article();
        $article = $this->article();
        DB::table('publications')->update(['state' => 'featured']);
        $article->refresh();
        $increments = [];
        Event::listen('eloquent.saved: '.Publication::class, function (Publication $publication) use (&$increments): void {
            if (array_key_exists($publication->getKey(), $increments)) {
                return;
            }

            $increments[$publication->getKey()] = null;
            $increments[$publication->getKey()] = $publication->increment('locked', 0);
        });
        Event::listen('eloquent.updating: '.Publication::class, function (Publication $publication) use (&$increments): null|false {
            return array_key_exists($publication->getKey(), $increments) && $increments[$publication->getKey()] === null ? false : null;
        });

        Publication::query()->findOrFail($native->publication_id)->state->returnToPublished()->now();
        $this->seen = [];
        DB::transaction(fn () => $article->update(['status' => 'published']));

        $this->assertSame([$native->publication_id => false, $article->publication_id => false], $increments);
        $this->assertSame(['before featured', 'after published'], $this->seen);
        $this->assertSame(['published', 'published'], DB::table('publications')->orderBy('id')->pluck('state')->all());
    }

    #[Test]
    public function it_keeps_a_write_whose_saved_listeners_return_false_as_a_native_trigger_does(): void
    {
        $native = $this->article();
        $article = $this->article();
        Event::listen(['eloquent.saved: '.Publication::class, PublicationSaved::class], fn () => false);

        Publication::query()->findOrFail($native->publication_id)->state->publish()->now();
        $this->seen = [];
        DB::transaction(fn () => $article->update(['status' => 'published']));

        $this->assertSame(['before draft', 'after published'], $this->seen);
        $stored = DB::table('publications')->where('id', $article->publication_id);
        $this->assertSame(['published', true], [$stored->value('state'), $stored->value('published_at') !== null]);
    }

    #[Test]
    public function it_counts_a_save_whose_custom_saved_event_listener_returns_a_value_as_a_save(): void
    {
        $article = $this->article();
        Event::listen(PublicationSaved::class, fn (PublicationSaved $saved) => $saved->publication->getKey());

        DB::transaction(fn () => $article->update(['status' => 'published']));

        $this->assertSame(['before draft', 'after published'], $this->seen);
        $this->assertSame(['published', true], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at') !== null]);
    }

    #[Test]
    public function it_counts_a_save_whose_saved_events_a_fake_records_as_a_save(): void
    {
        $article = $this->article();
        Event::fake(['eloquent.saved: '.Publication::class, PublicationSaved::class]);

        DB::transaction(fn () => $article->update(['status' => 'published']));

        Event::assertDispatched('eloquent.saved: '.Publication::class);
        $this->assertSame(['before draft', 'after published'], $this->seen);
        $this->assertSame(['published', true], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at') !== null]);
    }

    #[Test]
    #[TestWith([Invalid::class])]
    #[TestWith([BindingResolutionException::class])]
    public function it_refuses_and_rolls_back_the_whole_write_when_handle_throws_a_failure_of_the_transition(string $failure): void
    {
        $article = $this->article();
        $this->recordMirroredEvents();
        $this->journal->throws = $failure === Invalid::class
            ? new Invalid(Publication::query()->findOrFail($article->publication_id), PublicationState::Published, 'state')
            : new BindingResolutionException('Target [Mailer] is not instantiable.');

        $refusal = $this->assertWriteRefused(fn () => $article->update(['title' => 'Refused', 'status' => 'published']), "state of Publication #{$article->publication_id}");

        $this->assertSame($this->journal->throws, $refusal->getPrevious());
        $this->assertSame(['draft', 'Draft article', null], [DB::table('publications')->value('state'), DB::table('publications')->value('title'), DB::table('publications')->value('published_at')]);
        $this->assertSame(['before draft'], $this->seen);
        $this->assertNothingMirrored();
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_runs_failed_of_a_trigger_that_throws_while_the_new_state_is_stored_and_rolls_back_what_failed_writes(): void
    {
        $article = $this->article();
        DB::table('publications')->update(['state' => 'featured', 'featured_at' => '2020-01-01 00:00:00']);
        $article->refresh();
        Event::listen(Transitioning::class, fn () => throw new RuntimeException('The before event failed.'));

        $this->assertThrows(fn () => $article->update(['status' => 'published']), RuntimeException::class, 'The before event failed.');

        $this->assertSame(['failed in published: The before event failed.'], $this->journal->entries);
        $this->assertSame(['featured', 'Draft article', true], [DB::table('publications')->value('state'), DB::table('publications')->value('title'), DB::table('publications')->value('featured_at') !== null]);
        $this->assertSame(1, DB::transactionLevel());
    }

    public function audit(mixed ...$arguments): void // @phpstan-ignore phpunit.testMethodMustHaveTestAttribute
    {
        $wildcard = is_string($arguments[0] ?? null);
        $subject = $wildcard ? (is_array($arguments[1] ?? null) ? $arguments[1][0] ?? null : null) : $arguments[0] ?? null;

        if ($subject instanceof Publication || $subject instanceof PublicationSaved || $subject instanceof PublicationChanged) {
            $this->audited[] = ($wildcard ? 'wildcard ' : '').class_basename($subject);
        }
    }

    /**
     * @return list<mixed>
     */
    private function savedListeners(): array
    {
        $listeners = Event::getRawListeners();

        return [$listeners['eloquent.saved: '.Publication::class] ?? [], $listeners[PublicationSaved::class] ?? []];
    }

    private function article(): Article
    {
        return Article::query()->create(['title' => 'Draft article'])->refresh();
    }

    /**
     * @param  class-string<Model>  $bulletin
     * @param  list<ExcludedListener>  $excludedListeners
     * @param  class-string<Publication>|PrimaryCurrentModel  $publication
     */
    private function mirrorBulletins(string $bulletin = Bulletin::class, array $excludedListeners = [], string|PrimaryCurrentModel $publication = Publication::class): void
    {
        resolve(Installer::class)->install(new BulletinCompatibilityMap($bulletin, $publication));
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new BulletinCompatibilityMap($bulletin, $publication), $excludedListeners));
    }

    private function publishedFrom(Model $model): string
    {
        return $model->wasChanged('published_at') ? ' from '.substr((string) $model->getRawOriginal('published_at'), 0, 10) : '';
    }

    /**
     * @param  array<string, Closure>  $arguments
     */
    private function archivedWith(array $arguments): PrimaryCurrentModel
    {
        return PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication')->triggerArguments(Archive::class, $arguments);
    }

    /**
     * @param  list<string>  $attributes
     */
    private function changed(Model $model, array $attributes): string
    {
        return implode(', ', array_values(array_filter($attributes, fn (string $attribute) => $model->wasChanged($attribute))));
    }
}
