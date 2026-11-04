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
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\Video as PlaceVideo;
use Tests\TestCase;

#[CoversClass(ResolvedMap::class)]
final class ResolvedMapTest extends TestCase
{
    private static string $other = '';

    private static string $untimed = '';

    private static string $sharedFile = '';

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
            'status' => "(CASE CAST(\"jobs\".\"lifecycle_status\" AS text) WHEN 'drafting' THEN 'Draft' WHEN 'live' THEN 'Active' WHEN 'closed' THEN 'Sold' END)",
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
    public function it_resolves_a_discriminated_map_into_its_membership_and_lets_a_read_only_attribute_serve_the_discriminator(): void
    {
        $map = self::identified(new Compatibility(Photo::class))
            ->discriminatedBy('legacy_type')
            ->servedFrom(File::class, fn (Successor $file) => $file->attribute('kind', fn (Attribute $attribute) => $attribute->from('legacy_type')->readOnly(because: 'A legacy write never moves a record to another legacy model.')))
            ->resolve();

        $this->assertSame(['files', 'legacy_type', 'photo', ['kind']], [$map->membership?->table, $map->membership?->column, $map->membership?->alias, array_keys($map->attributes)]);
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
        yield 'a writer of the discriminator' => [fn (Compatibility $listing) => self::identified($listing)->discriminatedBy('legacy_type')->servedFrom(Job::class, fn (Successor $job) => $job->attribute('kind', fn (Attribute $attribute) => $attribute->from('legacy_type'))), "attribute 'kind' writes jobs.legacy_type, which discriminatedBy() already owns; make one of them readOnly()"];
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
    public function it_resolves_a_two_successor_map_into_one_map_per_successor_that_share_its_identity(): void
    {
        $map = Compatibility::of(Video::class)->resolve();
        $branches = array_map(fn (ResolvedMap $branch) => [
            $branch->primary->table,
            $branch->branch,
            $branch->retainedKey,
            $branch->membership?->alias,
            array_map(fn (Identifier $identifier) => $identifier->column(), $branch->identifiers),
            array_map(fn (Attribute $attribute) => $attribute->column(), $branch->attributes),
        ], $map->branches);

        $this->assertSame([File::class, Link::class], array_keys($map->branches));
        $this->assertSame([
            File::class => ['files', true, 'id', 'video', ['id' => 'legacy_id', 'uuid' => 'legacy_uuid'], ['original_file_name' => 'filename', 'mime_type' => null, 'size_kb' => 'size_bytes', 'youtube_embed_url' => null, 'created_at' => 'created_at', 'updated_at' => 'updated_at']],
            Link::class => ['links', true, 'id', 'video', ['id' => 'legacy_id', 'uuid' => 'legacy_uuid'], ['original_file_name' => null, 'mime_type' => null, 'size_kb' => null, 'youtube_embed_url' => 'url', 'created_at' => 'created_at', 'updated_at' => 'updated_at']],
        ], $branches);
        $this->assertSame([false, 'videos_retained', 'id'], [$map->branch, $map->retainedTable, $map->retainedKey]);
    }

    #[Test]
    public function it_chooses_the_branch_the_selector_returns_for_a_legacy_record(): void
    {
        $map = Compatibility::of(Video::class)->resolve();

        $this->assertSame(
            [$map->branches[Link::class], $map->branches[File::class]],
            [$map->branchFor(new Video(['youtube_embed_url' => 'https://youtu.be/oak'])), $map->branchFor(new Video(['original_file_name' => 'tour.mp4']))],
        );
    }

    #[Test]
    public function it_refuses_a_selector_that_returns_a_successor_no_branch_declares(): void
    {
        $map = self::branched(new Compatibility(Video::class), fn (Video $video): string => Photo::class)->resolve();

        $this->expectExceptionObject(InvalidCompatibilityMap::because('videos', 'the servedFrom() choose: closure returns '.Photo::class.', and successors: declares only '.File::class.' and '.Link::class));

        $map->branchFor(new Video);
    }

    /**
     * @return iterable<string, array{Closure(Compatibility): mixed, string}>
     */
    public static function invalidBranchedMaps(): iterable
    {
        $other = new class extends Model
        {
            protected $table = 'files';
        };
        $untimed = new class extends Model
        {
            public $timestamps = false;

            protected $table = 'untimed_links';
        };
        self::$other = $other::class;
        self::$untimed = $untimed::class;

        yield 'a choice without a successor' => [fn (Compatibility $video) => self::identified($video)->discriminatedBy('legacy_type')->servedFrom(choose: fn (Video $video): string => File::class, successors: fn (Successors $successors) => $successors), 'the successors: of servedFrom() declare no successor; declare each with as()'];
        yield 'branches without a discriminator' => [fn (Compatibility $video) => self::branched(self::identified($video)->retainedIn('videos_retained'), fn (Video $video): string => File::class, discriminated: false), 'servedFrom() chooses between File and Link, and they share one morph identity on one identifier sequence only through discriminatedBy()'];
        yield 'an attribute one branch cannot read' => [
            fn (Compatibility $video) => self::identified($video)
                ->discriminatedBy('legacy_type')
                ->servedFrom(
                    choose: fn (Video $video): string => File::class,
                    successors: fn (Successors $successors) => $successors
                        ->as(File::class, fn (Successor $file) => $file->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename')))
                        ->as(Link::class, fn (Successor $link) => $link)),
            "attribute 'original_file_name' is readable in the File branch and not in the Link branch; give it from(), external() or fixed() there",
        ];
        yield 'an external attribute another branch reads from sql' => [
            fn (Compatibility $video) => self::identified($video)
                ->discriminatedBy('legacy_type')
                ->servedFrom(
                    choose: fn (Video $video): string => File::class,
                    successors: fn (Successors $successors) => $successors
                        ->as(File::class, fn (Successor $file) => $file->attribute('mime_type', fn (Attribute $attribute) => $attribute->external(read: fn (mixed $files) => $files, write: fn (mixed $file, mixed $value) => null)))
                        ->as(Link::class, fn (Successor $link) => $link->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('url')))),
            "attribute 'mime_type' is external() in the File branch and reads SQL in the Link branch, and the view serves an external attribute from no branch; declare it external() or fixed() there",
        ];
        yield 'two branches over one table' => [
            fn (Compatibility $video) => self::identified($video)
                ->discriminatedBy('legacy_type')
                ->servedFrom(
                    choose: fn (Video $video): string => File::class,
                    successors: fn (Successors $successors) => $successors
                        ->as(File::class, fn (Successor $file) => $file)
                        ->as(self::$other, fn (Successor $file) => $file)),
            'as('.class_basename($other::class).') reads the table files, which as(File) already reads',
        ];
        yield 'a branch timestamps cannot read' => [
            fn (Compatibility $video) => self::identified($video)
                ->discriminatedBy('legacy_type')
                ->timestamps()
                ->servedFrom(
                    choose: fn (Video $video): string => File::class,
                    successors: fn (Successors $successors) => $successors
                        ->as(File::class, fn (Successor $file) => $file)
                        ->as(self::$untimed, fn (Successor $link) => $link)),
            'timestamps() reads the timestamps of '.class_basename($untimed).', which uses none',
        ];
    }

    /**
     * @param  Closure(Compatibility): mixed  $declare
     */
    #[DataProvider('invalidBranchedMaps')]
    #[Test]
    public function it_refuses_an_invalid_two_successor_map_and_names_it(Closure $declare, string $problem): void
    {
        $video = new Compatibility(Video::class);

        $this->expectExceptionObject(InvalidCompatibilityMap::because('videos', $problem));

        $declare($video);
        $video->resolve();
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
        $this->assertSame(['title' => false, 'original_file_name' => true, 'size_kb' => true, 'youtube_embed_url' => true, 'created_at' => false, 'updated_at' => false], array_map(fn (Attribute $attribute) => $map->isMorphed($attribute->name), $map->attributes));
        $this->assertSame(
            [$map->morphs[AssetLink::class], $map->morphs[AssetFile::class], $map->morphs[AssetLink::class], null],
            [$map->morphFor(new PlaceVideo(['youtube_embed_url' => 'https://youtu.be/oak'])), $map->morphFor(new PlaceVideo), $map->morphHolding('place_asset_link'), $map->morphHolding('place_asset_retired')],
        );
    }

    #[Test]
    public function it_reads_the_one_target_a_morph_declares_without_a_choice(): void
    {
        $map = Compatibility::of(Image::class)->resolve();

        $this->assertSame([[AssetFile::class], $map->morphs[AssetFile::class], ['mime_type']], [array_keys($map->morphs), $map->morphFor(new Image), $map->externals()]);
        $this->assertSame(AssetFile::class, $map->owners['mime_type']->model);
    }

    #[Test]
    public function it_resolves_an_external_attribute_on_a_successor_related_reaches(): void
    {
        $map = self::identified(new Compatibility(Listing::class))
            ->servedFrom(Job::class, fn (Successor $job) => self::titled($job)->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place->attribute('city', fn (Attribute $attribute) => $attribute->external(read: fn (mixed $places) => $places, write: fn (mixed $place, mixed $city) => null))))
            ->resolve();

        $this->assertSame([['city'], Place::class], [$map->externals(), $map->owners['city']->model]);
    }

    #[Test]
    public function it_refuses_a_choice_that_returns_a_target_no_successor_declares(): void
    {
        $map = self::identified(new Compatibility(PlaceVideo::class))
            ->discriminatedBy('legacy_type')
            ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (PlaceVideo $video): string => Photo::class,
                successors: fn (Successors $successors) => $successors
                    ->as(AssetFile::class, fn (Successor $file) => $file)
                    ->as(AssetLink::class, fn (Successor $link) => $link)))
            ->resolve();

        $this->expectExceptionObject(InvalidCompatibilityMap::because('place_videos', 'the relatedMorph() choose: closure returns '.Photo::class.', and successors: declares only '.AssetFile::class.' and '.AssetLink::class));

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
            fn (Compatibility $video) => self::identified($video)->discriminatedBy('legacy_type')->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (PlaceVideo $video): string => AssetFile::class,
                successors: fn (Successors $successors) => $successors
                    ->as(AssetFile::class, fn (Successor $file) => $file)
                    ->as(self::$sharedFile, fn (Successor $file) => $file))),
            'relatedMorph() reaches the table asset_files through '.class_basename($sharedFile::class).' and through AssetFile; give each successors: class a table of its own',
        ];
        yield 'an attribute one target cannot read' => [
            fn (Compatibility $video) => self::identified($video)->discriminatedBy('legacy_type')->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (PlaceVideo $video): string => AssetFile::class,
                successors: fn (Successors $successors) => $successors
                    ->as(AssetFile::class, fn (Successor $file) => $file->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename')))
                    ->as(AssetLink::class, fn (Successor $link) => $link))),
            "attribute 'original_file_name' is readable in the AssetFile successor and not in the AssetLink successor; give it from(), external() or fixed() there",
        ];
        yield 'an external attribute another target reads from sql' => [
            fn (Compatibility $video) => self::identified($video)->discriminatedBy('legacy_type')->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (PlaceVideo $video): string => AssetFile::class,
                successors: fn (Successors $successors) => $successors
                    ->as(AssetFile::class, fn (Successor $file) => $file->attribute('original_file_name', fn (Attribute $attribute) => $attribute->external(read: fn (mixed $files) => $files, write: fn (mixed $file, mixed $value) => null)))
                    ->as(AssetLink::class, fn (Successor $link) => $link->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('url'))))),
            "attribute 'original_file_name' is external() in the AssetFile successor and reads SQL in the AssetLink successor, and the view serves an external attribute from no successor; declare it external() or fixed() there",
        ];
        yield 'an attribute on the place asset and a target' => [
            fn (Compatibility $video) => self::identified($video)->discriminatedBy('legacy_type')->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset->attribute('title', fn (Attribute $attribute) => $attribute->from('title'))->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                successors: fn (Successors $successors) => $successors->as(AssetLink::class, fn (Successor $link) => $link->attribute('title', fn (Attribute $attribute) => $attribute->from('url'))))),
            "attribute 'title' is declared twice",
        ];
        yield 'a writer of the morph type' => [
            fn (Compatibility $video) => self::identified($video)->discriminatedBy('legacy_type')->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset->attribute('kind', fn (Attribute $attribute) => $attribute->from('asset_type'))->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                successors: fn (Successors $successors) => $successors->as(AssetLink::class, fn (Successor $link) => $link))),
            "attribute 'kind' writes place_assets.asset_type, which relatedMorph() already owns; make one of them readOnly()",
        ];
        yield 'an identifier in the morph id' => [
            fn (Compatibility $video) => $video->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('asset_id'))->discriminatedBy('legacy_type')->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                successors: fn (Successors $successors) => $successors->as(AssetLink::class, fn (Successor $link) => $link))),
            "identifier 'uuid' reads place_assets.asset_id, which relatedMorph() already reads",
        ];
        yield 'a discriminator in the morph type' => [
            fn (Compatibility $video) => self::identified($video)->discriminatedBy('asset_type')->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                successors: fn (Successors $successors) => $successors->as(AssetLink::class, fn (Successor $link) => $link))),
            'discriminatedBy() names place_assets.asset_type, which relatedMorph() reads through PlaceAsset; give the discriminator a column of its own',
        ];
        yield 'a morph in one of two successors' => [
            fn (Compatibility $video) => self::identified($video)->discriminatedBy('legacy_type')->servedFrom(
                choose: fn (PlaceVideo $video): string => PlaceAsset::class,
                successors: fn (Successors $successors) => $successors
                    ->as(PlaceAsset::class, fn (Successor $asset) => $asset->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(), successors: fn (Successors $targets) => $targets->as(AssetLink::class, fn (Successor $link) => $link)))
                    ->as(File::class, fn (Successor $file) => $file)),
            'relatedMorph() on PlaceAsset, one of the successors servedFrom() chooses between, would choose a second time for each record; serve the record from one successor with relatedMorph(), or from several without it',
        ];
        yield 'a morph through a related successor' => [
            fn (Compatibility $video) => self::identified($video)->servedFrom(Job::class, fn (Successor $job) => $job->related(fn (Job $job) => $job->hasOne(PlaceAsset::class, 'title', 'name'), fn (Successor $asset) => $asset->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                successors: fn (Successors $successors) => $successors->as(AssetLink::class, fn (Successor $link) => $link)))),
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

    private static function identified(Compatibility $listing): Compatibility
    {
        return $listing
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'));
    }

    /**
     * @param  Closure(Video): string  $selector
     */
    private static function branched(Compatibility $video, Closure $selector, bool $discriminated = true): Compatibility
    {
        return ($discriminated ? self::identified($video)->discriminatedBy('legacy_type') : $video)
            ->servedFrom(
                choose: $selector,
                successors: fn (Successors $successors) => $successors
                    ->as(File::class, fn (Successor $file) => $file->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->fixed(null)))
                    ->as(Link::class, fn (Successor $link) => $link->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url'))));
    }

    private static function titled(Successor $job): Successor
    {
        return $job->attribute('title', fn (Attribute $attribute) => $attribute->from('name'));
    }
}
