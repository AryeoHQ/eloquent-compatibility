<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Console\DriftCheck;
use Support\Database\Eloquent\Compatibility\Console\DriftFinding;
use Support\Database\Eloquent\Compatibility\Console\DriftQueries;
use Support\Database\Eloquent\Compatibility\Definition\DefinedMap;
use Support\Database\Eloquent\Compatibility\Definition\RelationCompiler;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Schema\Compiler;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Write\WriteRouter;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingRetained;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\TestCase;

#[CoversClass(Compatibility::class)]
#[CoversClass(DefinedMap::class)]
#[CoversClass(RelationCompiler::class)]
final class CompatibilityTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        SyncListingToMls::$synced = [];
    }

    #[Test]
    public function it_compiles_a_model_first_definition_into_a_compatibility_map(): void
    {
        $map = Compatibility::of(Listing::class)->toMap();
        $previous = $map->previousModel();
        [$places, $sites] = $map->dependentCurrentModels();
        $attributes = array_column(array_map(fn (Attribute $attribute) => [$attribute->column, $attribute], $map->attributes()), 1, 0);

        $this->assertSame(['listings', 'listings', 'listings_retained', 'listings_retained', 'id', 'job_id', ListingRetained::class], [$map->name(), $previous->view, $previous->table, $previous->alias, $previous->keyColumn, $previous->primaryForeignKey, $previous->retainedModel]);
        $this->assertSame([Job::class, 'jobs'], [$map->primaryCurrentModel()->model, $map->primaryCurrentModel()->alias]);
        $this->assertSame([Place::class, 'places', 'job_id', '"places"."parent_id" is null and "places"."deleted_at" is null', '"id" asc'], [$places->model, $places->alias, $places->primaryForeignKey, $places->readableWhen, $places->orderedBy]);
        $this->assertSame('place_id', $places->designationColumn);
        $this->assertSame([JobSite::class, 'job_sites', 'true', null], [$sites->model, $sites->alias, $sites->readableWhen, $sites->orderedBy]);
        $this->assertSame(['jobs', 'name', null], [$attributes['title']->alias, $attributes['title']->storedAs, $attributes['title']->type]);
        $this->assertSame(['places', 'street'], [$attributes['street']->alias, $attributes['street']->storedAs]);
        $this->assertSame(['jobs', 'created_at', 'jobs', 'deleted_at'], [$attributes['created_at']->alias, $attributes['created_at']->storedAs, $attributes['deleted_at']->alias, $attributes['deleted_at']->storedAs]);
        $this->assertTrue($attributes['mls_number']->retained);
        $this->assertSame(['Listing keeps the key other records hold.', 'Listing stays tied to its Job.', 'Partners hold the listing uuid.'], [$attributes['id']->readOnlyBecause, $attributes['job_id']->readOnlyBecause, $attributes['uuid']->readOnlyBecause]);
        $this->assertSame([[SyncListingToMls::class, 'MLS sync follows only edits made through the Listing itself.']], array_map(fn ($listener) => [$listener->listener, $listener->because], $map->excludedListeners()));
    }

    #[Test]
    public function it_registers_from_the_model_class_without_reading_the_database(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        resolve(WriteRouter::class)->register(Listing::class);

        $this->assertSame(0, $queries);
        $this->assertSame('listings', resolve(CompatibilityMaps::class)->get('listings')->previousModel->view);
    }

    #[Test]
    public function it_compiles_the_hide_scope_and_the_relation_constraints_into_the_view(): void
    {
        $sql = (new Compiler(DB::connection()))->compile(Listing::class)->statements()[0];

        $this->assertStringContainsString('(SELECT places."street" FROM "places" places WHERE places."job_id" = jobs."id" AND places."id" = listings_retained."place_id" AND ("places"."parent_id" is null and "places"."deleted_at" is null) ORDER BY "id" asc, places."id" LIMIT 1) AS "street"', $sql);
        $this->assertStringContainsString("WHERE NOT COALESCE((listings_retained.\"id\" IN (select \"listings_retained\".\"id\" from \"listings_retained\" where \"job_id\" is null and \"status\" = 'Retired')), false)", $sql);
    }

    #[Test]
    public function it_creates_a_listing_through_the_jobs_relations(): void
    {
        $this->install();

        $listing = Listing::query()->create(['mls_number' => 'MLS-100', 'title' => '12 Oak Lane', 'price' => '450000.00', 'street' => '12 Oak Lane', 'city' => 'Seattle', 'latitude' => 47.61, 'is_published' => true]);

        $job = Job::query()->sole();
        $place = Place::query()->sole();
        $this->assertSame(['12 Oak Lane', 45000000, 'legacy-listing'], [$job->name, $job->price_cents, $job->getAttribute('source')]);
        $this->assertNotNull($job->getAttribute('published_at'));
        $this->assertSame(['12 Oak Lane', 'Seattle', null], [$place->getAttribute('street'), $place->getAttribute('city'), $place->parent_id]);
        $this->assertSame(47.61, (float) JobSite::query()->sole()->getAttribute('latitude'));
        $this->assertSame($place->id, DB::table('listings_retained')->value('place_id'));

        $read = Listing::query()->findOrFail($listing->id);
        $this->assertSame(['450000.00', true, '12 Oak Lane', 'MLS-100', 'Draft'], [$read->price, $read->is_published, $read->street, $read->getAttribute('mls_number'), $read->status]);
    }

    #[Test]
    public function it_creates_a_dependent_only_when_create_when_allows(): void
    {
        $this->install();

        Listing::query()->create(['title' => 'No site yet', 'street' => '1 Main St']);

        $this->assertSame([1, 0], [Place::query()->count(), JobSite::query()->count()]);
    }

    #[Test]
    public function it_reads_and_writes_the_dependent_row_the_relation_selects(): void
    {
        $this->install();
        $listing = Listing::query()->create(['title' => 'Two places', 'street' => '1 Root St']);
        $root = Place::query()->sole();
        Place::query()->create(['job_id' => $root->job_id, 'parent_id' => $root->id, 'street' => 'Unit 2']);

        $listing->update(['street' => '1 Root Street']);

        $this->assertSame(['1 Root Street', 'Unit 2'], [$root->fresh()?->getAttribute('street'), Place::query()->whereNotNull('parent_id')->value('street')]);
        $this->assertSame('1 Root Street', $listing->fresh()?->street);
    }

    #[Test]
    public function it_refuses_a_guarded_update_for_every_write_through_the_listing(): void
    {
        $this->install();
        $listing = Listing::query()->create(['title' => 'Sold', 'status' => 'Sold', 'price' => '500000']);

        $this->assertWriteRefused(fn () => $listing->update(['price' => '1']), 'refused sold price frozen');
        $this->assertWriteRefused(fn () => Listing::query()->whereKey($listing->id)->update(['price' => 2]), 'A sold listing keeps the price it sold for.');

        $this->assertSame(50000000, Job::query()->sole()->price_cents);
    }

    #[Test]
    public function it_refuses_a_place_its_predicate_describes_and_finds_one_raw_sql_left_behind(): void
    {
        $this->install();
        Listing::query()->create(['title' => 'Root', 'street' => '1 Root St']);
        $jobId = Job::query()->sole()->id;

        $refusal = $this->assertWriteRefused(fn () => Place::query()->create(['job_id' => $jobId, 'street' => '2 Other St']), 'refused second root place');

        $this->assertSame('Give the new place a parent_id, or soft-delete the current root place first.', $refusal->instead);
        $this->assertSame(1, Place::query()->count());

        DB::table('places')->insert(['id' => '00000000-0000-4000-8000-0000000000aa', 'job_id' => $jobId, 'street' => 'Raw root']);

        // Raw SQL skipped the check, and now both roots are in the state, so the drift check names both.
        $this->assertEqualsCanonicalizing(
            ['places#00000000-0000-4000-8000-0000000000aa', 'places#'.Place::query()->where('street', '1 Root St')->value('id')],
            array_map(fn (DriftFinding $finding) => $finding->recordId, array_values(array_filter($this->findings(), fn (DriftFinding $finding) => $finding->check === DriftCheck::RefusedState))),
        );
    }

    #[Test]
    public function it_creates_and_fills_a_listing_for_each_job_created_directly(): void
    {
        $this->install();
        $this->recordMirroredEvents();

        $job = Job::query()->create(['name' => 'New build', 'source' => 'api', 'archived' => true]);

        $this->assertSame(['New build', 'Archived'], [Listing::query()->sole()->getAttribute('title'), Listing::query()->sole()->status]);
        $this->assertSame($job->id, ListingRetained::query()->sole()->getAttribute('job_id'));
        $this->assertMirrored(Listing::class, ModelEvent::Created);
    }

    #[Test]
    public function it_hides_the_retained_rows_its_scope_selects(): void
    {
        $this->install();
        DB::table('listings_retained')->insert([['status' => 'Retired'], ['status' => 'Draft']]);

        $this->assertSame(0, Listing::query()->count());
        $this->assertSame(['listings_retained#2'], array_map(fn (DriftFinding $finding) => $finding->recordId, $this->findings()));
    }

    #[Test]
    public function it_mirrors_job_writes_to_listing_listeners_but_skips_an_excluded_one(): void
    {
        $this->install();
        Event::listen('eloquent.updated: '.Listing::class, SyncListingToMls::class);
        $listing = Listing::query()->create(['title' => 'Old']);
        $this->recordMirroredEvents();

        Job::query()->sole()->update(['name' => 'Renamed']);

        $this->assertMirrored(Listing::class, ModelEvent::Updated, fn (Listing $mirrored) => $mirrored->getAttribute('title') === 'Renamed');
        $this->assertSame([], SyncListingToMls::$synced);

        $listing->update(['title' => 'Through the listing']);

        $this->assertSame([$listing->id], SyncListingToMls::$synced);
    }

    #[Test]
    public function it_reports_an_installed_view_that_no_longer_matches_the_definition(): void
    {
        $this->install();
        $this->assertSame([], $this->findings());

        $query = (new Compiler(DB::connection()))->viewQuery(Listing::class);
        DB::statement('drop view listings');
        DB::statement('create view listings as '.$query.' AND false');

        $this->assertSame(
            [[DriftCheck::ViewDefinition, 'listings', 'the installed view differs from the view the map compiles to now; run Installer::recompile()']],
            array_map(fn (DriftFinding $finding) => [$finding->check, $finding->recordId, $finding->detail], $this->findings()),
        );

        resolve(Installer::class)->recompile(Listing::class);

        $this->assertSame([], $this->findings());
    }

    #[Test]
    public function it_refuses_a_relation_that_cannot_serve_one_row(): void
    {
        $this->assertInvalid(['street' => 'job.places.street'], Job::class.'::places() must return a HasOne relation');
        $this->assertInvalid(['street' => 'job.tenantPlace.street'], 'whose global scope tenant would be compiled into the compatibility view');
        $this->assertInvalid(['street' => 'job.nothing.street'], Job::class.' has no relation nothing()');
        $this->assertInvalid(['street' => 'job.rootPlace.parent.street'], 'deeper than a dependent of the primary current model');
    }

    #[Test]
    public function it_refuses_a_definition_without_its_parts(): void
    {
        $this->assertThrows(fn () => Compatibility::of(Job::class), InvalidCompatibilityMap::class, 'does not implement');
        $this->assertThrows(fn () => (new Compatibility(Listing::class))->attributes(['title' => 'job.name'])->toMap(), InvalidCompatibilityMap::class, 'it says no identifiedBy()');
        $this->assertThrows(fn () => (new Compatibility(Listing::class))->identifiedBy(ListingRetained::class)->attributes(['status' => 'status'])->toMap(), InvalidCompatibilityMap::class, 'no attribute reads through a relation');
        $this->assertThrows(fn () => (new Compatibility(Listing::class))->identifiedBy(ListingRetained::class)->attributes(['title' => 'job.name'])->refuse('x', Model::class, fn () => true, 'b', 'i')->toMap(), InvalidCompatibilityMap::class, "refused state 'x' checks ".Model::class);
    }

    private function install(): void
    {
        resolve(Installer::class)->install(Listing::class);
        resolve(WriteRouter::class)->register(Listing::class);
    }

    /**
     * @return list<DriftFinding>
     */
    private function findings(): array
    {
        return resolve(DriftQueries::class)->find(ResolvedMap::of(Compatibility::map(Listing::class)), 100);
    }

    /**
     * @param  array<string, string>  $paths
     */
    private function assertInvalid(array $paths, string $message): void
    {
        $this->assertThrows(
            fn () => ResolvedMap::of((new Compatibility(Listing::class))->identifiedBy(ListingRetained::class)->attributes(['title' => 'job.name', ...$paths])->toMap()),
            InvalidCompatibilityMap::class,
            $message,
        );
    }
}
