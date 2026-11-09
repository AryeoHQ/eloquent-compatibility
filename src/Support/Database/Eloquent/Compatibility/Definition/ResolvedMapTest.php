<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\LeaveTitleUnchanged;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\Fixtures\Support\Listings\TransitionListingStatus;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\Video as PlaceVideo;
use Tests\TestCase;

#[CoversClass(ResolvedMap::class)]
final class ResolvedMapTest extends TestCase
{
    private static string $sharedFile = '';

    #[Test]
    public function it_resolves_the_listing_map_into_its_retained_table_successor_key_and_successors(): void
    {
        $map = Compatibility::of(Listing::class)->resolve();

        $this->assertSame([Listing::class, 'listings', 'listings_retained', 'job_id', Job::class], [$map->model, $map->table, $map->retainedTable, $map->successorKey, $map->primary->model]);
        $this->assertSame([['jobs', 'places'], ['jobs', 'job_sites']], array_map(fn (Related $related) => [$related->parentTable, $related->table], $map->related));
    }

    #[Test]
    public function it_resolves_every_listing_attribute_to_the_successor_column_it_reads(): void
    {
        $map = Compatibility::of(Listing::class)->resolve();

        $this->assertSame([
            'created_at' => '"jobs"."created_at"',
            'updated_at' => '"jobs"."updated_at"',
            'deleted_at' => '"jobs"."deleted_at"',
            'title' => '"jobs"."name"',
            'company_id' => '"jobs"."company_id"',
            'price' => '(CAST("jobs"."price_cents" AS numeric) / 100)',
            'status' => "(CASE CAST(\"jobs\".\"lifecycle_status\" AS text) WHEN 'drafting' THEN 'Draft' WHEN 'live' THEN 'Active' WHEN 'closed' THEN 'Sold' END)",
            'street' => '"places"."street"',
            'city' => '"places"."city"',
            'latitude' => '"job_sites"."latitude"',
        ], array_map(fn (Attribute $attribute) => $attribute->read($map->owners[$attribute->name]->table), $map->attributes));
        $this->assertSame(['A job never changes company.', true, 'A listing has one address.'], [
            $map->attributes['company_id']->readOnlyBecause(),
            $map->attributes['status']->operation() === TransitionListingStatus::class,
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

        $this->assertSame(['id', 'uuid', 'job_id', 'mls_number', 'internal_notes'], $map->legacyOnly(['id', 'uuid', 'job_id', 'title', 'mls_number', 'price', 'deleted_at', 'internal_notes']));
    }

    #[Test]
    public function it_keeps_the_when_of_the_root_as_its_membership_and_lets_a_read_only_attribute_serve_the_discriminator(): void
    {
        $map = (new Compatibility(Photo::class))
            ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
                ->when(fn (Builder $files) => $files->where(
                    column: 'legacy_type',
                    operator: '=',
                    value: 'photo',
                ))
                ->attribute(
                    name: 'kind',
                    definition: fn (Attribute $attribute) => $attribute->from('legacy_type')->readOnly(because: 'A legacy write never moves a record to another legacy model.', ignoreWrites: false),
                ))
            ->resolve();

        $this->assertSame([true, ['kind']], [$map->primary->membershipScope() !== null, array_keys($map->attributes)]);
    }

    #[Test]
    public function it_keeps_the_successor_key_servedfrom_names_with_or_without_a_retained_table(): void
    {
        $retained = (new Compatibility(Listing::class))->retainedIn('listings_retained')->servedFrom(root: Job::class, key: 'job_id', successor: self::titled(...))->resolve();
        $unretained = (new Compatibility(Listing::class))->servedFrom(root: Job::class, key: 'bridge_id', successor: self::titled(...))->resolve();

        $this->assertSame([['listings_retained', 'job_id'], [null, 'bridge_id']], [[$retained->retainedTable, $retained->successorKey], [$unretained->retainedTable, $unretained->successorKey]]);
    }

    /**
     * @return iterable<string, array{Closure(Compatibility): mixed, string}>
     */
    public static function invalidMaps(): iterable
    {
        yield 'no successor' => [fn (Compatibility $listing) => $listing, 'it says no servedFrom(), so it names no successor'];
        yield 'at most one primary row' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->atMostOne('One job.')), 'atMostOne() refuses a second related row, and servedFrom() reads the successor Job by its own row'];
        yield 'an attribute on the legacy key' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
            name: 'id',
            definition: fn (Attribute $attribute) => $attribute->from('id'),
        )), "attribute 'id' maps the key of Listing, and legacy identity stays on the legacy table"];
        yield 'an attribute on the successor key' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
            name: 'job_id',
            definition: fn (Attribute $attribute) => $attribute->from('id'),
        )), "attribute 'job_id' maps the successor key that servedFrom() names, and the retained table holds it"];
        yield 'two relations to one table' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->related(
            relation: fn (Job $job) => $job->rootPlace(),
            successor: fn (Successor $place) => $place,
        )->related(
            relation: fn (Job $job) => $job->hasOne(Place::class),
            successor: fn (Successor $place) => $place,
        )), 'the relation from Job to Place reaches the table places, which the relation from Job to Place already reaches; map each table through one relation'];
        yield 'a relation back to the primary table' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->related(
            relation: fn (Job $job) => $job->rootPlace(),
            successor: fn (Successor $place) => $place->related(
                relation: fn (Place $place) => $place->job(),
                successor: fn (Successor $job) => $job,
            ),
        )), 'the relation from Place to Job reaches the table jobs, which the successor Job already reaches; map each table through one relation'];
        yield 'a successor on the legacy table' => [fn (Compatibility $listing) => $listing->servedFrom(root: Listing::class, key: 'listing_id', successor: self::titled(...)), 'the successor Listing reaches the table listings, which the legacy model Listing already reaches; map each table through one relation'];
        yield 'an attribute in two successors' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => self::titled($job)->related(
            relation: fn (Job $job) => $job->rootPlace(),
            successor: fn (Successor $place) => $place->attribute(
                name: 'title',
                definition: fn (Attribute $attribute) => $attribute->from('street'),
            ),
        )), "attribute 'title' is declared twice"];
        yield 'two writers of one column' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => self::titled($job)->attribute(
            name: 'headline',
            definition: fn (Attribute $attribute) => $attribute->from('name'),
        )), "attribute 'headline' writes jobs.name, which attribute 'title' already owns; make one of them readOnly()"];
        yield 'raw sql that cannot be written' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
            name: 'title',
            definition: fn (Attribute $attribute) => $attribute->readAs('upper(name)'),
        )), "attribute 'title' has no inverse, so its read says no write; declare writtenBy() or readOnly()"];
        yield 'an attribute without a column' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
            name: 'title',
            definition: fn (Attribute $attribute) => $attribute,
        )), "attribute 'title' names no column; give it from(), fixed(), readAs() or computedAs()"];
        yield 'retained in a successor table' => [fn (Compatibility $listing) => $listing->retainedIn('places')->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->related(
            relation: fn (Job $job) => $job->rootPlace(),
            successor: fn (Successor $place) => $place,
        )), 'retainedIn() names the table places, which the map already reads as another table'];
        yield 'retained in the legacy table' => [fn (Compatibility $listing) => $listing->retainedIn('listings')->servedFrom(root: Job::class, key: 'job_id', successor: self::titled(...)), 'retainedIn() names the table listings, which the map already reads as another table'];
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
        $map = (new Compatibility(Listing::class))
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
                name: 'title',
                definition: fn (Attribute $attribute) => $attribute
                    ->readAs('upper(name)')
                    ->writtenBy(LeaveTitleUnchanged::class),
            ))
            ->resolve();

        $this->assertSame(LeaveTitleUnchanged::class, $map->attributes['title']->operation());
    }

    #[Test]
    public function it_resolves_one_target_map_for_each_morph_successor_and_reads_every_target_table(): void
    {
        $map = Compatibility::of(PlaceVideo::class)->resolve();

        $this->assertSame([AssetFile::class, AssetLink::class], array_keys($map->morphs));
        $this->assertSame(['place_videos', 'place_videos_retained', 'place_assets', 'asset_files', 'asset_links'], $map->tables());
        $this->assertSame([['place_assets', 'asset_files', 'place_asset_file'], ['place_assets', 'asset_links', 'place_asset_link']], array_map(fn (Related $related) => [$related->parentTable, $related->table, $related->morphAlias], $map->related));
        $this->assertSame(
            [AssetFile::class => [['asset_files'], 'place_asset_file', 'filename'], AssetLink::class => [['asset_links'], 'place_asset_link', null]],
            array_map(fn (ResolvedMap $target) => [array_map(fn (Related $related) => $related->table, $target->related), $target->target?->morphAlias, $target->attributes['original_file_name']->column()], $map->morphs),
        );
        $this->assertSame(['created_at' => false, 'updated_at' => false, 'title' => false, 'original_file_name' => true, 'size_kb' => true, 'youtube_embed_url' => true], array_map(
            callback: fn (Attribute $attribute) => $map->isMorphed($attribute->name),
            array: $map->attributes,
        ));
        $this->assertSame(
            [$map->morphs[AssetLink::class], $map->morphs[AssetFile::class], $map->morphs[AssetLink::class], null],
            [$map->morphFor(new PlaceVideo(['youtube_embed_url' => 'https://youtu.be/oak'])), $map->morphFor(new PlaceVideo), $map->morphHolding('place_asset_link'), $map->morphHolding('place_asset_retired')],
        );
    }

    #[Test]
    public function it_reads_the_one_target_a_morph_declares(): void
    {
        $map = Compatibility::of(Image::class)->resolve();

        $this->assertSame([[AssetFile::class], $map->morphs[AssetFile::class], ['mime_type']], [array_keys($map->morphs), $map->morphFor(new Image), $map->externals()]);
        $this->assertSame(AssetFile::class, $map->owners['mime_type']->model);
    }

    #[Test]
    public function it_resolves_an_external_attribute_on_a_successor_related_reaches(): void
    {
        $map = (new Compatibility(Listing::class))
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => self::titled($job)->related(
                relation: fn (Job $job) => $job->rootPlace(),
                successor: fn (Successor $place) => $place->attribute(
                    name: 'city',
                    definition: fn (Attribute $attribute) => $attribute->computedAs(read: fn (mixed $places) => $places)->readOnly(because: 'The map reads it from elsewhere.', ignoreWrites: false),
                ),
            ))
            ->resolve();

        $this->assertSame([['city'], Place::class], [$map->externals(), $map->owners['city']->model]);
    }

    #[Test]
    public function it_refuses_a_choice_that_returns_a_target_no_successor_declares(): void
    {
        $map = (new Compatibility(PlaceVideo::class))
            ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (PlaceVideo $video): string => Photo::class,
                targets: fn (Targets $targets) => $targets
                    ->as(target: AssetFile::class, successor: fn (Successor $file) => $file)
                    ->as(target: AssetLink::class, successor: fn (Successor $link) => $link)))
            ->resolve();

        $this->expectExceptionObject(InvalidCompatibilityMap::because(
            map: 'place_videos',
            problem: 'the relatedMorph() choose: closure returns '.Photo::class.', and targets: declares only '.AssetFile::class.' and '.AssetLink::class,
        ));

        $map->morphFor(new PlaceVideo);
    }

    /**
     * @return iterable<string, array{Closure(Compatibility): mixed, string}>
     */
    public static function invalidMorphMaps(): iterable
    {
        $sharedFile = new class extends Model
        {
            protected $table = 'asset_files';
        };
        self::$sharedFile = $sharedFile::class;

        yield 'two targets over one table' => [
            fn (Compatibility $video) => $video->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (PlaceVideo $video): string => AssetFile::class,
                targets: fn (Targets $targets) => $targets
                    ->as(target: AssetFile::class, successor: fn (Successor $file) => $file)
                    ->as(target: self::$sharedFile, successor: fn (Successor $file) => $file))),
            'relatedMorph() reaches the table asset_files through '.class_basename($sharedFile::class).' and through AssetFile; give each targets: class a table of its own',
        ];
        yield 'an attribute one target cannot read' => [
            fn (Compatibility $video) => $video->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (PlaceVideo $video): string => AssetFile::class,
                targets: fn (Targets $targets) => $targets
                    ->as(target: AssetFile::class, successor: fn (Successor $file) => $file->attribute(
                        name: 'original_file_name',
                        definition: fn (Attribute $attribute) => $attribute->from('filename'),
                    ))
                    ->as(target: AssetLink::class, successor: fn (Successor $link) => $link))),
            "attribute 'original_file_name' is readable in the AssetFile target and not in the AssetLink target; give it from(), computedAs() or fixed() there",
        ];
        yield 'a computed attribute another target reads from sql' => [
            fn (Compatibility $video) => $video->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (PlaceVideo $video): string => AssetFile::class,
                targets: fn (Targets $targets) => $targets
                    ->as(target: AssetFile::class, successor: fn (Successor $file) => $file->attribute(
                        name: 'original_file_name',
                        definition: fn (Attribute $attribute) => $attribute->computedAs(read: fn (mixed $files) => $files)->readOnly(because: 'The library names it.', ignoreWrites: false),
                    ))
                    ->as(target: AssetLink::class, successor: fn (Successor $link) => $link->attribute(
                        name: 'original_file_name',
                        definition: fn (Attribute $attribute) => $attribute->from('url'),
                    )))),
            "attribute 'original_file_name' is computedAs() in the AssetFile target and reads SQL in the AssetLink target, and the view serves a computed attribute from no target; declare it computedAs() or fixed() there",
        ];
        yield 'an attribute on the place asset and a target' => [
            fn (Compatibility $video) => $video->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset->attribute(
                name: 'title',
                definition: fn (Attribute $attribute) => $attribute->from('title'),
            )->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (Model $legacy): string => AssetLink::class,
                targets: fn (Targets $targets) => $targets->as(target: AssetLink::class, successor: fn (Successor $link) => $link->attribute(
                    name: 'title',
                    definition: fn (Attribute $attribute) => $attribute->from('url'),
                )))),
            "attribute 'title' is declared twice",
        ];
        yield 'a writer of the morph type' => [
            fn (Compatibility $video) => $video->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset->attribute(
                name: 'kind',
                definition: fn (Attribute $attribute) => $attribute->from('asset_type'),
            )->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (Model $legacy): string => AssetLink::class,
                targets: fn (Targets $targets) => $targets->as(target: AssetLink::class, successor: fn (Successor $link) => $link))),
            "attribute 'kind' writes place_assets.asset_type, which relatedMorph() already owns; make one of them readOnly()",
        ];
        yield 'a morph through a related successor' => [
            fn (Compatibility $video) => $video->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->related(
                relation: fn (Job $job) => $job->hasOne(
                    related: PlaceAsset::class,
                    foreignKey: 'title',
                    localKey: 'name',
                ),
                successor: fn (Successor $asset) => $asset->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                    choose: fn (Model $legacy): string => AssetLink::class,
                    targets: fn (Targets $targets) => $targets->as(target: AssetLink::class, successor: fn (Successor $link) => $link)),
            )),
            'relatedMorph() on the relation from Job to PlaceAsset follows a MorphTo of a successor related() reaches, and only the servedFrom() successor Job carries a relatedMorph(); declare it there',
        ];
    }

    /**
     * @param  Closure(Compatibility): mixed  $declare
     */
    #[DataProvider('invalidMorphMaps')]
    #[Test]
    public function it_refuses_an_invalid_map_through_a_morph_and_names_it(Closure $declare, string $problem): void
    {
        $video = new Compatibility(PlaceVideo::class);

        $this->expectExceptionObject(InvalidCompatibilityMap::because('place_videos', $problem));

        $declare($video);
        $video->resolve();
    }

    private static function titled(Successor $job): Successor
    {
        return $job->attribute('title', fn (Attribute $attribute) => $attribute->from('name'));
    }
}
