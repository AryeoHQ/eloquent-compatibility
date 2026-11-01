<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\TestCase;

#[CoversClass(ResolvedMap::class)]
final class ResolvedMapTest extends TestCase
{
    #[Test]
    public function it_resolves_the_listing_map_into_its_identifiers_retained_table_and_successors(): void
    {
        $map = Compatibility::of(Listing::class)->resolve();

        $this->assertSame([Listing::class, 'listings', 'listings_retained', 'job_id', Job::class], [$map->model, $map->table, $map->retainedTable, $map->retainedKey, $map->primary->model]);
        $this->assertSame(['id' => 'legacy_id', 'uuid' => 'legacy_uuid'], array_map(fn (Identifier $identifier) => $identifier->column(), $map->identifiers));
        $this->assertSame([true, false], [$map->identifiers['id']->isInteger(), $map->identifiers['uuid']->isInteger()]);
        $this->assertSame([['jobs', 'places'], ['jobs', 'job_sites']], array_map(fn (Related $related) => [$related->parentTable, $related->table], $map->related));
    }

    #[Test]
    public function it_resolves_every_listing_attribute_to_the_successor_column_it_reads(): void
    {
        $map = Compatibility::of(Listing::class)->resolve();

        $this->assertSame([
            'title' => '"jobs"."name"',
            'company_id' => '"jobs"."company_id"',
            'price' => '(CAST("jobs"."price_cents" AS numeric) / 100)',
            'status' => "(CASE \"jobs\".\"lifecycle_status\" WHEN 'drafting' THEN 'Draft' WHEN 'live' THEN 'Active' WHEN 'closed' THEN 'Sold' END)",
            'street' => '"places"."street"',
            'city' => '"places"."city"',
            'latitude' => '"job_sites"."latitude"',
            'created_at' => '"jobs"."created_at"',
            'updated_at' => '"jobs"."updated_at"',
            'deleted_at' => '"jobs"."deleted_at"',
        ], array_map(fn (Attribute $attribute) => $attribute->read($map->owners[$attribute->name]->table), $map->attributes));
        $this->assertSame(['A job never changes company.', true, 'A listing has one address.'], [
            $map->attributes['company_id']->readOnlyBecause(),
            $map->attributes['status']->runsTransitions(),
            $map->related[0]->successor->atMostOneBecause(),
        ]);
    }

    #[Test]
    public function it_resolves_the_listing_hide_rule_guard_and_unforwarded_listener(): void
    {
        $map = Compatibility::of(Listing::class)->resolve();

        $this->assertSame(['A merged job lives on as the job it merged into.'], array_map(fn (Hide $hide) => $hide->because, $map->hides));
        $this->assertSame(['sold price frozen' => ['A sold listing keeps the price it sold for.', 'Change the price before the sale.']], array_map(fn (Guard $guard) => [$guard->because, $guard->instead], $map->guards));
        $this->assertSame([SyncListingToMls::class => 'MLS sync follows only edits made through the Listing itself.'], $map->withoutForwarding);
    }

    #[Test]
    public function it_names_the_legacy_columns_the_map_does_not_serve_as_legacy_only(): void
    {
        $map = Compatibility::of(Listing::class)->resolve();

        $this->assertSame(['mls_number', 'internal_notes'], $map->legacyOnly(['id', 'uuid', 'title', 'mls_number', 'price', 'deleted_at', 'internal_notes']));
    }

    #[Test]
    public function it_keys_the_retained_table_by_the_successor_foreign_key_unless_told_otherwise(): void
    {
        $retained = self::identified(new Compatibility(Listing::class))->retainedIn('listings_retained')->servedFrom(Job::class, self::titled(...))->resolve();
        $unretained = self::identified(new Compatibility(Listing::class))->servedFrom(Job::class, self::titled(...))->resolve();

        $this->assertSame([['listings_retained', 'job_id'], [null, null]], [[$retained->retainedTable, $retained->retainedKey], [$unretained->retainedTable, $unretained->retainedKey]]);
    }

    #[Test]
    public function it_maps_deleted_at_only_when_the_successor_soft_deletes_too(): void
    {
        $map = self::identified(new Compatibility(Listing::class))->timestamps()->servedFrom(JobSite::class, fn (Successor $site) => $site)->resolve();

        $this->assertSame(['created_at', 'updated_at'], array_keys($map->attributes));
    }

    /**
     * @return iterable<string, array{Closure(Compatibility): mixed, string}>
     */
    public static function invalidMaps(): iterable
    {
        $untimed = new class extends Model
        {
            public $timestamps = false;

            protected $table = 'untimed_jobs';
        };

        yield 'no successor' => [fn (Compatibility $listing) => self::identified($listing), 'it says no servedFrom(), so it names no successor'];
        yield 'at most one primary row' => [fn (Compatibility $listing) => self::identified($listing)->servedFrom(Job::class, fn (Successor $job) => $job->atMostOne('One job.')), 'atMostOne() refuses a second related row, and servedFrom() reads the successor Job by its own row'];
        yield 'no identifier for the key' => [fn (Compatibility $listing) => $listing->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))->servedFrom(Job::class, self::titled(...)), "it declares no identifiedBy('id'), so it serves no id for Listing"];
        yield 'an identifier on the successor key' => [fn (Compatibility $listing) => $listing->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('id'))->servedFrom(Job::class, self::titled(...)), "identifier 'id' reads jobs.id, the successor's own key; a legacy identifier lives in a column of its own"];
        yield 'two identifiers in one column' => [fn (Compatibility $listing) => $listing->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_id'))->servedFrom(Job::class, self::titled(...)), "identifier 'uuid' reads jobs.legacy_id, which identifier 'id' already reads"];
        yield 'two relations to one table' => [fn (Compatibility $listing) => self::identified($listing)->servedFrom(Job::class, fn (Successor $job) => $job->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place)->related(fn (Job $job) => $job->hasOne(Place::class), fn (Successor $place) => $place)), 'the relation from Job to Place reaches the table places, which the relation from Job to Place already reaches; map each table through one relation'];
        yield 'a relation back to the primary table' => [fn (Compatibility $listing) => self::identified($listing)->servedFrom(Job::class, fn (Successor $job) => $job->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place->related(fn (Place $place) => $place->job(), fn (Successor $job) => $job))), 'the relation from Place to Job reaches the table jobs, which the successor Job already reaches; map each table through one relation'];
        yield 'a successor on the legacy table' => [fn (Compatibility $listing) => self::identified($listing)->servedFrom(Listing::class, self::titled(...)), 'the successor Listing reaches the table listings, which the legacy model Listing already reaches; map each table through one relation'];
        yield 'an attribute that is an identifier' => [fn (Compatibility $listing) => self::identified($listing)->servedFrom(Job::class, fn (Successor $job) => $job->attribute('uuid', fn (Attribute $attribute) => $attribute->from('id'))), "attribute 'uuid' is also an identifier"];
        yield 'an attribute in two successors' => [fn (Compatibility $listing) => self::identified($listing)->servedFrom(Job::class, fn (Successor $job) => self::titled($job)->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place->attribute('title', fn (Attribute $attribute) => $attribute->from('street')))), "attribute 'title' is declared twice"];
        yield 'a timestamp declared as an attribute' => [fn (Compatibility $listing) => self::identified($listing)->timestamps()->servedFrom(Job::class, fn (Successor $job) => $job->attribute('created_at', fn (Attribute $attribute) => $attribute->from('created_at'))), "attribute 'created_at' is declared twice"];
        yield 'two writers of one column' => [fn (Compatibility $listing) => self::identified($listing)->servedFrom(Job::class, fn (Successor $job) => self::titled($job)->attribute('headline', fn (Attribute $attribute) => $attribute->from('name'))), "attribute 'headline' writes jobs.name, which attribute 'title' already owns; make one of them readOnly()"];
        yield 'a writer of an identifier column' => [fn (Compatibility $listing) => self::identified($listing)->servedFrom(Job::class, fn (Successor $job) => $job->attribute('legacy_number', fn (Attribute $attribute) => $attribute->from('legacy_id'))), "attribute 'legacy_number' writes jobs.legacy_id, which identifier 'id' already owns; make one of them readOnly()"];
        yield 'raw sql that cannot be written' => [fn (Compatibility $listing) => self::identified($listing)->servedFrom(Job::class, fn (Successor $job) => $job->attribute('title', fn (Attribute $attribute) => $attribute->readAs('upper(name)'))), "attribute 'title' reads as raw SQL with readAs(), and says neither writeTo() nor readOnly()"];
        yield 'an attribute without a column' => [fn (Compatibility $listing) => self::identified($listing)->servedFrom(Job::class, fn (Successor $job) => $job->attribute('title', fn (Attribute $attribute) => $attribute)), "attribute 'title' names no column; give it from(), fixed(), readAs() or external()"];
        yield 'timestamps from a successor without them' => [fn (Compatibility $listing) => self::identified($listing)->timestamps()->servedFrom($untimed::class, self::titled(...)), 'timestamps() reads the timestamps of '.class_basename($untimed).', which uses none'];
        yield 'retained in a successor table' => [fn (Compatibility $listing) => self::identified($listing)->retainedIn('places')->servedFrom(Job::class, fn (Successor $job) => $job->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place)), 'retainedIn() names the table places, which the map already reads as another table'];
        yield 'retained in the legacy table' => [fn (Compatibility $listing) => self::identified($listing)->retainedIn('listings')->servedFrom(Job::class, self::titled(...)), 'retainedIn() names the table listings, which the map already reads as another table'];
    }

    /**
     * @param  Closure(Compatibility): mixed  $declare
     */
    #[DataProvider('invalidMaps')]
    #[Test]
    public function it_refuses_an_invalid_map_and_names_it(Closure $declare, string $problem): void
    {
        $listing = new Compatibility(Listing::class);

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', $problem));

        $declare($listing);
        $listing->resolve();
    }

    #[Test]
    public function it_refuses_timestamps_for_a_legacy_model_without_them(): void
    {
        $untimed = new class extends Model
        {
            public $timestamps = false;

            protected $table = 'untimed_listings';
        };

        $this->expectExceptionObject(InvalidCompatibilityMap::because('untimed_listings', 'timestamps() maps the timestamps of '.class_basename($untimed).', which uses none'));

        self::identified(new Compatibility($untimed::class))->timestamps()->servedFrom(Job::class, self::titled(...))->resolve();
    }

    #[Test]
    public function it_names_every_variable_a_closure_captures(): void
    {
        $street = 'Oak';
        $city = 'Seattle';

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'a test closure captures $street, $city, and a map reads nothing but its own arguments'));

        ResolvedMap::refuseCaptures('listings', 'a test closure', fn () => $street.$city);
    }

    #[Test]
    public function it_accepts_a_closure_that_reads_only_its_arguments(): void
    {
        $this->expectNotToPerformAssertions();

        ResolvedMap::refuseCaptures('listings', 'a test closure', fn (string $street) => $street);
    }

    #[Test]
    public function it_accepts_the_declaration_object_php_captures_when_a_nested_closure_reuses_its_name(): void
    {
        $map = self::identified(new Compatibility(Listing::class))
            ->servedFrom(Job::class, fn (Successor $job) => $job->attribute('title', fn (Attribute $attribute) => $attribute
                ->from('name')
                ->readAs('upper(name)')
                ->writeTo(fn (mixed $job) => strtolower((string) $job))))
            ->resolve();

        $this->assertSame('oak lane', $map->attributes['title']->write('OAK LANE'));
    }

    private static function identified(Compatibility $listing): Compatibility
    {
        return $listing
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'));
    }

    private static function titled(Successor $job): Successor
    {
        return $job->attribute('title', fn (Attribute $attribute) => $attribute->from('name'));
    }
}
