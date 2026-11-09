<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
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

        parent::tearDown();
    }

    #[Test]
    public function it_inserts_the_retained_row_with_its_identity_and_the_initializer_values_inside_the_create_transaction(): void
    {
        $this->installListings();
        $next = (int) DB::scalar('select last_value + 1 from listings_id_seq');

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
