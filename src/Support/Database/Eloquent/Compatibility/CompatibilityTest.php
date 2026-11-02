<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\TestCase;

#[CoversClass(Compatibility::class)]
final class CompatibilityTest extends TestCase
{
    #[Test]
    public function it_resolves_the_listing_map_without_a_database_connection(): void
    {
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }

        $map = Compatibility::of(Listing::class)->resolve();

        $this->assertSame([], DB::getConnections());
        $this->assertSame(['title', 'company_id', 'price', 'status', 'street', 'city', 'latitude', 'created_at', 'updated_at', 'deleted_at'], array_keys($map->attributes));
    }

    #[Test]
    public function it_resolves_each_map_over_a_shared_successor_without_a_database_connection(): void
    {
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }

        $aliases = array_map(fn (string $model) => Compatibility::of($model)->resolve()->membership?->alias, [Photo::class, FloorPlan::class, LegacyFile::class]);

        $this->assertSame([], DB::getConnections());
        $this->assertSame(['photo', 'floorplan', 'legacy_file'], $aliases);
    }

    #[Test]
    public function it_resolves_a_map_over_two_successors_without_a_database_connection(): void
    {
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }

        $map = Compatibility::of(Video::class)->resolve();

        $this->assertSame([], DB::getConnections());
        $this->assertSame([[File::class, Link::class], ['original_file_name', 'mime_type', 'size_kb', 'youtube_embed_url', 'created_at', 'updated_at']], [array_keys($map->branches), array_keys($map->attributes)]);
    }

    #[Test]
    public function it_refuses_to_install_recompile_or_uninstall_a_map_over_two_successors_before_reading_the_database(): void
    {
        $refusals = [];
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach (['install' => Compatibility::install(...), 'recompile' => Compatibility::recompile(...), 'uninstall' => Compatibility::uninstall(...)] as $operation) {
            try {
                $operation(Video::class);
            } catch (InvalidCompatibilityMap $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $this->assertSame([
            "Compatibility map 'videos' cannot install: it serves videos from File and Link, and install() serves a map from one successor only.",
            "Compatibility map 'videos' cannot recompile: it serves videos from File and Link, and recompile() serves a map from one successor only.",
            "Compatibility map 'videos' cannot uninstall: it serves videos from File and Link, and uninstall() serves a map from one successor only.",
        ], $refusals);
        $this->assertSame(0, $queries);
    }

    #[Test]
    public function it_resolves_a_map_built_directly_for_a_model(): void
    {
        $map = (new Compatibility(Listing::class))
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(Job::class, fn (Successor $job) => $job->attribute('title', fn (Attribute $attribute) => $attribute->from('name')))
            ->resolve();

        $this->assertSame([Listing::class, 'listings', ['id'], ['title'], [], [], []], [$map->model, $map->table, array_keys($map->identifiers), array_keys($map->attributes), $map->hides, $map->guards, $map->withoutForwarding]);
    }

    #[Test]
    public function it_leaves_every_successor_model_free_of_the_package(): void
    {
        $package = fn (string $name) => str_starts_with($name, 'Support\\Database\\Eloquent\\Compatibility\\');

        foreach ([Job::class, Place::class, JobSite::class, File::class, Link::class] as $successor) {
            $this->assertSame([], array_values(array_filter([...class_implements($successor), ...class_uses_recursive($successor)], $package)), $successor);
        }
    }

    #[Test]
    public function it_refuses_a_model_that_declares_no_map(): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('jobs', 'Job does not implement '.Contracts\Compatible::class.', so it declares no map'));

        Compatibility::of(Job::class);
    }

    #[Test]
    public function it_refuses_each_schema_operation_for_a_model_that_declares_no_map_before_reading_the_database(): void
    {
        $refusals = [];
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach ([Compatibility::prepare(...), Compatibility::install(...), Compatibility::recompile(...), Compatibility::uninstall(...)] as $operation) {
            try {
                $operation(Job::class);
            } catch (InvalidCompatibilityMap $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $this->assertSame(array_fill(0, 4, "Compatibility map 'jobs' is declared wrongly: Job does not implement ".Contracts\Compatible::class.', so it declares no map.'), $refusals);
        $this->assertSame(0, $queries);
    }

    #[Test]
    public function it_refuses_a_class_that_is_no_model(): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because(SyncListingToMls::class, 'it names no Eloquent model'));

        new Compatibility(SyncListingToMls::class);
    }

    /**
     * @return iterable<string, array{Closure(Compatibility): mixed, string}>
     */
    public static function invalidDeclarations(): iterable
    {
        $identifier = fn (Identifier $identifier) => $identifier->integer()->from('legacy_id');
        $successor = fn (Successor $job) => $job;

        yield 'an identifier twice' => [fn (Compatibility $listing) => $listing->identifiedBy('id', $identifier)->identifiedBy('id', $identifier), "identifier 'id' is declared twice"];
        yield 'a retained table twice' => [fn (Compatibility $listing) => $listing->retainedIn('listings_retained')->retainedIn('listings_kept'), 'retainedIn() is declared twice'];
        yield 'an empty retained key' => [fn (Compatibility $listing) => $listing->retainedIn('listings_retained', key: ''), 'retainedIn() names an empty table or key'];
        yield 'a discriminator twice' => [fn (Compatibility $listing) => $listing->discriminatedBy('legacy_type')->discriminatedBy('kind'), 'discriminatedBy() is declared twice'];
        yield 'a discriminator in another table' => [fn (Compatibility $listing) => $listing->discriminatedBy('jobs.legacy_type'), "discriminatedBy() names 'jobs.legacy_type', which names no column of the primary successor's own table"];
        yield 'a successor twice' => [fn (Compatibility $listing) => $listing->servedFrom(Job::class, $successor)->servedFrom(Job::class, $successor), 'servedFrom() is declared twice'];
        yield 'a successor that is no model' => [fn (Compatibility $listing) => $listing->servedFrom(SyncListingToMls::class, $successor), 'servedFrom() names '.SyncListingToMls::class.', which is not an Eloquent model'];
        yield 'a selector with a definition' => [fn (Compatibility $listing) => $listing->servedFrom(fn (Listing $listing): string => Job::class, $successor), 'servedFrom() takes a selector closure alone; declare each successor it chooses with as()'];
        yield 'a successor without a closure' => [fn (Compatibility $listing) => $listing->servedFrom(Job::class), 'servedFrom() names '.Job::class.' without a closure over it'];
        yield 'a selector after a successor' => [fn (Compatibility $listing) => $listing->servedFrom(Job::class, $successor)->servedFrom(fn (Listing $listing): string => Job::class), 'servedFrom() is declared twice'];
        yield 'a branch without a selector' => [fn (Compatibility $listing) => $listing->as(Job::class, $successor), 'as() declares a successor for the servedFrom() selector, and no servedFrom() selector precedes it'];
        yield 'a branch after a successor' => [fn (Compatibility $listing) => $listing->servedFrom(Job::class, $successor)->as(Place::class, $successor), 'as() declares a successor for the servedFrom() selector, and no servedFrom() selector precedes it'];
        yield 'a branch twice' => [fn (Compatibility $listing) => $listing->servedFrom(fn (Listing $listing): string => Job::class)->as(Job::class, $successor)->as(Job::class, $successor), 'as('.Job::class.') is declared twice'];
        yield 'a hide rule without a reason' => [fn (Compatibility $listing) => $listing->hide(fn (Builder $jobs) => $jobs, because: ''), 'hide() gives no reason; it takes the reason the legacy model never shows these records'];
        yield 'a guard without a remedy' => [fn (Compatibility $listing) => $listing->guard('frozen', fn (Model $after, Model $before) => false, because: 'Frozen.', instead: ' '), "guard 'frozen' needs a name, a reason and what to do instead"];
        yield 'a guard twice' => [fn (Compatibility $listing) => $listing->guard('frozen', fn (Model $after, Model $before) => false, 'Frozen.', 'Wait.')->guard('frozen', fn (Model $after, Model $before) => true, 'Frozen.', 'Wait.'), "guard 'frozen' is declared twice"];
        yield 'an unknown listener' => [fn (Compatibility $listing) => $listing->withoutForwardingTo('App\\Listeners\\Missing', because: 'Gone.'), 'withoutForwardingTo() names App\\Listeners\\Missing, which is not a class'];
        yield 'a listener without a reason' => [fn (Compatibility $listing) => $listing->withoutForwardingTo(SyncListingToMls::class, because: ''), 'withoutForwardingTo('.SyncListingToMls::class.') gives no reason'];
        yield 'a listener twice' => [fn (Compatibility $listing) => $listing->withoutForwardingTo(SyncListingToMls::class, 'Own edits.')->withoutForwardingTo(SyncListingToMls::class, 'Own edits.'), 'withoutForwardingTo('.SyncListingToMls::class.') is declared twice'];
    }

    /**
     * @param  Closure(Compatibility): mixed  $declare
     */
    #[DataProvider('invalidDeclarations')]
    #[Test]
    public function it_refuses_an_invalid_declaration_and_names_the_map(Closure $declare, string $problem): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', $problem));

        $declare(new Compatibility(Listing::class));
    }

    #[Test]
    public function it_refuses_each_closure_that_captures_a_variable(): void
    {
        $merged = 'merged_into_job_id';
        $refusals = [];

        foreach ([
            fn (Compatibility $listing) => $listing->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from($merged)),
            fn (Compatibility $listing) => $listing->servedFrom(Job::class, fn (Successor $job) => $job->attribute($merged, fn (Attribute $attribute) => $attribute)),
            fn (Compatibility $listing) => $listing->servedFrom(fn (Listing $listing): string => $listing->getAttribute($merged) === null ? Job::class : Place::class),
            fn (Compatibility $listing) => $listing->hide(fn (Builder $jobs) => $jobs->whereNotNull($merged), because: 'Merged.'),
            fn (Compatibility $listing) => $listing->guard('merged', fn (Model $after, Model $before) => $after->getAttribute($merged) !== null, because: 'Merged.', instead: 'Unmerge.'),
        ] as $declare) {
            try {
                $declare(new Compatibility(Listing::class));
            } catch (InvalidCompatibilityMap $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $this->assertSame([
            "Compatibility map 'listings' is declared wrongly: the closure of identifier 'id' captures \$merged, and a map reads nothing but its own arguments.",
            "Compatibility map 'listings' is declared wrongly: the servedFrom() closure captures \$merged, and a map reads nothing but its own arguments.",
            "Compatibility map 'listings' is declared wrongly: the servedFrom() selector captures \$merged, and a map reads nothing but its own arguments.",
            "Compatibility map 'listings' is declared wrongly: a hide() scope captures \$merged, and a map reads nothing but its own arguments.",
            "Compatibility map 'listings' is declared wrongly: guard 'merged' captures \$merged, and a map reads nothing but its own arguments.",
        ], $refusals);
    }
}
