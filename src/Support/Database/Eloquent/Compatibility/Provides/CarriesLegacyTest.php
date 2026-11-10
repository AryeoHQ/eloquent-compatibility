<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Listings\CarriedJob;
use Tests\Fixtures\Support\Listings\InitializeLegacyListing;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\PromotedJob;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\Brochure;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\InteractiveContent;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\Fixtures\Support\PlaceAssets\Video;
use Tests\TestCase;

#[CoversTrait(CarriesLegacy::class)]
final class CarriesLegacyTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        ListingTables::drop();
        PlaceAssetTables::drop();
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_inserts_the_retained_row_with_its_identity_and_the_initializer_values_inside_the_create_transaction(): void
    {
        $this->installListings();
        $next = (int) DB::scalar('select last_value + 1 from listings_id_seq');
        InitializeLegacyListing::$seen = [];

        $seen = DB::transaction(function (): array {
            $job = Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]);
            $row = (array) DB::table('listings_retained')->where('job_id', $job->id)->first(['id', 'uuid', 'mls_number']);

            return [$job->id, $row];
        });

        [$job, $row] = $seen;
        $this->assertSame($next, $row['id']);
        $this->assertIsString($row['uuid']);
        $this->assertSame('JOB-Birch House', $row['mls_number']);
        $this->assertSame(['Birch House', 'JOB-Birch House'], [Listing::query()->findOrFail($next)->title, Listing::query()->findOrFail($next)->mls_number]);
        $this->assertSame($job, ListingTables::jobOf($next));
        $this->assertSame([[$next, $job]], InitializeLegacyListing::$seen);

        DB::beginTransaction();
        $rolledBack = Job::query()->create(['name' => 'Cedar House', 'company_id' => ListingTables::COMPANY]);
        DB::rollBack();

        $this->assertFalse(DB::table('listings_retained')->where('job_id', $rolledBack->id)->exists());
        $this->assertFalse(DB::table('jobs')->where('id', $rolledBack->id)->exists());
    }

    #[Test]
    public function it_inserts_the_retained_row_of_a_native_create_outside_any_transaction_inside_a_transaction_of_its_own(): void
    {
        $this->installListings();
        $levels = [];

        foreach (['creating', 'created'] as $event) {
            Event::listen("eloquent.{$event}: ".Job::class, function () use (&$levels, $event): void {
                $levels[$event] = DB::transactionLevel();
            });
        }

        $job = Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame(['creating' => 1, 'created' => 1], $levels);
        $this->assertSame([0, 'JOB-Birch House'], [DB::transactionLevel(), DB::table('listings_retained')->where('job_id', $job->id)->value('mls_number')]);
    }

    #[Test]
    public function it_keeps_no_root_of_a_native_create_outside_any_transaction_whose_retained_row_insert_fails(): void
    {
        $this->installListings();
        DB::statement("alter table listings_retained add column required_note text not null default 'none'");
        DB::statement('alter table listings_retained alter column required_note drop default');

        try {
            Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]);
            $this->fail('The create did not fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23502', $exception->getCode());
        }

        $this->assertFalse(DB::table('jobs')->where('name', 'Birch House')->exists());
    }

    #[Test]
    public function it_keeps_no_root_of_a_native_save_or_ignore_outside_any_transaction_whose_retained_row_insert_fails(): void
    {
        $this->installListings();
        DB::statement("alter table listings_retained add column required_note text not null default 'none'");
        DB::statement('alter table listings_retained alter column required_note drop default');

        try {
            (new Job(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]))->saveOrIgnore();
            $this->fail('The create did not fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23502', $exception->getCode());
        }

        $this->assertFalse(DB::table('jobs')->where('name', 'Birch House')->exists());
    }

    #[Test]
    public function it_keeps_no_root_of_a_native_create_outside_any_transaction_whose_legacy_model_installs_before_the_insert_and_whose_retained_row_insert_fails(): void
    {
        ListingTables::create();
        ListingTables::seed();
        DB::statement("alter table listings add column required_note text not null default 'none'");
        DB::statement('alter table listings alter column required_note drop default');
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        $installed = false;
        Event::listen('eloquent.creating: '.Job::class, function () use (&$installed): void {
            if ($installed) {
                return;
            }

            $installed = true;
            Compatibility::install(
                model: Listing::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
        });

        try {
            Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]);
            $this->fail('The create did not fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23502', $exception->getCode());
        }

        $this->assertTrue($installed);
        $this->assertFalse(DB::table('jobs')->where('name', 'Birch House')->exists());
    }

    #[Test]
    public function it_hands_the_legacy_initializer_the_related_successors_loaded_and_locked(): void
    {
        $this->installListings();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            if (preg_match('/^select .* from "(places|job_sites)"/s', $query->sql, $match) === 1) {
                $statements[] = [$match[1], str_ends_with($query->sql, ' for update')];
            }
        });

        DB::transaction(fn () => Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]));

        $this->assertSame([['places', true], ['job_sites', true]], $statements);
    }

    #[Test]
    public function it_inserts_nothing_before_install(): void
    {
        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);

        $job = Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        $this->assertFalse(DB::table('listings')->where('job_id', $job->id)->exists());
    }

    #[Test]
    public function it_locks_only_the_legacy_tables_whose_when_the_new_root_matches(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        PlaceAssetTables::install();

        $locked = DB::transaction(function (): array {
            $file = AssetFile::query()->create(['filename' => 'side.jpg', 'mime_type' => 'image/jpeg']);
            PlaceAsset::query()->create(['legacy_type' => 'place_image', 'asset_type' => $file->getMorphClass(), 'asset_id' => $file->id, 'title' => 'Side']);

            return DB::table('pg_locks')->join('pg_class', 'pg_class.oid', '=', 'pg_locks.relation')
                ->where('pg_locks.pid', DB::scalar('select pg_backend_pid()'))
                ->where('pg_locks.mode', 'AccessShareLock')
                ->whereIn('pg_class.relname', ['place_images', 'place_videos', 'place_interactive_content', 'floorplans'])
                ->orderBy('pg_class.relname')
                ->pluck('pg_class.relname')
                ->all();
        });

        $this->assertSame(['place_images'], $locked);
        $this->assertSame(['Side'], DB::table('place_images')->where('title', 'Side')->pluck('title')->all());
        $this->assertSame([], DB::table('floorplans')->whereIn('place_asset_id', DB::table('place_assets')->where('title', 'Side')->select('id'))->pluck('id')->all());
    }

    #[Test]
    public function it_inserts_no_legacy_row_for_a_successor_that_no_map_serves_from(): void
    {
        $this->installListings();
        $unserved = new class extends Model
        {
            use CarriesLegacy;
            use HasUuids;

            protected $table = 'jobs';

            protected $guarded = [];
        };

        $created = DB::transaction(fn () => $unserved::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]));

        $this->assertTrue(DB::table('jobs')->where('id', $created->getKey())->exists());
        $this->assertFalse(DB::table('listings_retained')->where('job_id', $created->getKey())->exists());
    }

    #[Test]
    public function it_inserts_the_retained_row_of_a_native_create_through_a_subclass_of_the_root_its_map_serves_from(): void
    {
        DB::statement('create table holders (id serial primary key, name text not null, created_at timestamptz, updated_at timestamptz)');
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null)');
        DB::table('holders')->insert([['name' => 'Oak']]);
        $holder = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'holders';

            protected $guarded = [];

            public static function compatibility(Compatibility $holder): Compatibility
            {
                return $holder
                    ->retainedIn('holders_retained')
                    ->servedFrom(root: CarriedJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'name', definition: fn (Attribute $attribute) => $attribute->from('name')));
            }
        };
        $files = new Filesystem;
        $cache = (string) tempnam(
            directory: sys_get_temp_dir(),
            prefix: 'compatibility-served-',
        );
        $files->put(
            path: $cache,
            contents: '<?php return '.var_export(
                value: [$holder::class],
                return: true,
            ).';',
        );
        $this->app->instance(ServedModels::class, new ServedModels(
            cache: $cache,
            scans: false,
            withoutCache: false,
            files: $files,
        ));

        try {
            Compatibility::prepare($holder::class);
            DB::statement('update holders set job_id = gen_random_uuid()');
            DB::statement('insert into configured_jobs (id, name) select job_id, name from holders');
            Compatibility::install(
                model: $holder::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

            $created = PromotedJob::query()->create(['name' => 'Birch']);
            $carried = [DB::table('holders_retained')->where('job_id', $created->getKey())->exists(), DB::table('holders')->where('name', 'Birch')->exists()];
        } finally {
            $files->delete($cache);
            DB::statement('drop view if exists holders');
            DB::statement('drop table if exists holders, holders_retained, holders_backfill, configured_jobs cascade');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type IN ('holders'); END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }

        $this->assertSame([true, true], $carried);
    }

    #[Test]
    public function it_raises_the_legacy_events_of_a_native_create_on_a_shared_root_only_to_the_member_a_successor_creating_listener_makes_it(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        PlaceAssetTables::install();
        $seen = [];
        $pending = null;
        Event::listen('eloquent.creating: '.PlaceAsset::class, function (PlaceAsset $asset) use (&$seen, &$pending): void {
            $seen[] = ['PlaceAsset creating', $asset->legacy_type];
            $asset->legacy_type ??= 'place_image';
            $pending = $asset;
        });

        foreach ([Image::class, Video::class, InteractiveContent::class, Brochure::class, FloorPlan::class] as $model) {
            foreach (['saving', 'creating', 'created', 'saved'] as $event) {
                Event::listen("eloquent.{$event}: {$model}", function (Model $legacy) use (&$seen, &$pending, $event): void {
                    $seen[] = [class_basename($legacy)." {$event}", $pending?->legacy_type, $legacy->getAttribute('title')];
                });
            }
        }

        $file = AssetFile::query()->create(['filename' => 'side.jpg', 'mime_type' => 'image/jpeg']);
        $asset = PlaceAsset::query()->create(['asset_type' => $file->getMorphClass(), 'asset_id' => $file->id, 'title' => 'Side']);

        $this->assertSame([
            ['PlaceAsset creating', null],
            ['Image saving', 'place_image', 'Side'],
            ['Image creating', 'place_image', 'Side'],
            ['Image created', 'place_image', 'Side'],
            ['Image saved', 'place_image', 'Side'],
        ], $seen);
        $this->assertSame(['place_image', true], [DB::table('place_assets')->where('id', $asset->id)->value('legacy_type'), DB::table('place_images_retained')->where('place_asset_id', $asset->id)->exists()]);
    }

    #[Test]
    public function it_raises_the_deferred_legacy_events_of_a_native_create_on_a_shared_root_once_when_a_retry_follows_a_successor_creating_listener_that_threw(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $seen = new Collection;

        foreach (['saving', 'creating', 'created', 'saved'] as $event) {
            Event::listen("eloquent.{$event}: ".Photo::class, function () use ($seen, $event): void {
                $seen->push($event);
            });
        }

        $throws = true;
        Event::listen('eloquent.creating: '.File::class, function () use (&$throws): void {
            if ($throws) {
                $throws = false;

                throw new RuntimeException('The first create fails.');
            }
        });
        $file = new File(['filename' => 'birch.jpg', 'mime_type' => 'image/jpeg']);

        try {
            $file->save();
        } catch (RuntimeException) {
        }

        $failed = $seen->all();
        $file->save();

        $this->assertSame([[], ['saving', 'creating', 'created', 'saved']], [$failed, $seen->slice(count($failed))->values()->all()]);
    }

    #[Test]
    public function it_inserts_nothing_for_a_native_create_on_a_shared_root_that_a_legacy_saving_or_creating_listener_refuses(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        PlaceAssetTables::install();
        foreach (['saving', 'creating'] as $event) {
            Event::listen("eloquent.{$event}: ".Image::class, fn (Image $image): null|false => $image->getAttribute('title') === "Side {$event}" ? false : null);
        }

        $file = AssetFile::query()->create(['filename' => 'side.jpg', 'mime_type' => 'image/jpeg']);
        $retained = DB::table('place_images_retained')->count();
        $outcomes = [];

        foreach (['saving', 'creating'] as $event) {
            $asset = PlaceAsset::query()->create(['legacy_type' => 'place_image', 'asset_type' => $file->getMorphClass(), 'asset_id' => $file->id, 'title' => "Side {$event}"]);
            $outcomes[$event] = [$asset->exists, DB::table('place_assets')->where('title', "Side {$event}")->exists()];
        }

        $this->assertSame(['saving' => [false, false], 'creating' => [false, false]], $outcomes);
        $this->assertSame($retained, DB::table('place_images_retained')->count());
    }

    #[Test]
    public function it_refuses_a_native_create_on_a_shared_root_whose_insert_joins_another_legacy_model_than_its_creating_event_chose(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        PlaceAssetTables::install();
        DB::unprepared("create or replace function compatibility_reclassify() returns trigger language plpgsql as \$\$ begin if new.title = 'Garden' then new.legacy_type := 'place_video'; end if; return new; end \$\$");
        DB::statement('create trigger compatibility_reclassify before insert on place_assets for each row execute function compatibility_reclassify()');
        $seen = [];

        foreach (['saving', 'creating', 'created', 'saved'] as $event) {
            Event::listen("eloquent.{$event}: ".Image::class, function () use (&$seen, $event): void {
                $seen[] = $event;
            });
        }

        $file = AssetFile::query()->create(['filename' => 'garden.jpg', 'mime_type' => 'image/jpeg']);
        $retained = [DB::table('place_images_retained')->count(), DB::table('place_videos_retained')->count()];

        try {
            PlaceAsset::query()->create(['legacy_type' => 'place_image', 'asset_type' => $file->getMorphClass(), 'asset_id' => $file->id, 'title' => 'Garden']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        } finally {
            DB::statement('drop function if exists compatibility_reclassify() cascade');
        }

        $this->assertSame(PlaceAsset::class.' cannot take this create, because its legacy model changed between its creating event and its insert. Why: The package raised the legacy saving and creating events to Image, and the inserted PlaceAsset row joins Video, so a column that when() reads changed after the creating event. Instead: Set every column that when() reads before the insert, in the create\'s attributes or in a listener of PlaceAsset, rather than in a database default or trigger.', $refusal);
        $this->assertSame(['saving', 'creating'], $seen);
        $this->assertSame([false, $retained], [DB::table('place_assets')->where('title', 'Garden')->exists(), [DB::table('place_images_retained')->count(), DB::table('place_videos_retained')->count()]]);
    }

    private function installListings(): void
    {
        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
    }
}
