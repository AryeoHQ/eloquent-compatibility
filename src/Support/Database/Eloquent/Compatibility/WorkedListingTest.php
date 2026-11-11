<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Carbon\CarbonInterval;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Testing\AssertsServedCompatibility;
use Support\Database\Eloquent\Compatibility\Write\Refusal;
use Tests\Fixtures\Support\WorkedListing\Job;
use Tests\Fixtures\Support\WorkedListing\JobWriteTransaction;
use Tests\Fixtures\Support\WorkedListing\Listing;
use Tests\Fixtures\Support\WorkedListing\ListingMap;
use Tests\Fixtures\Support\WorkedListing\WorkedListingTables;
use Tests\TestCase;

#[CoversClass(Compatibility::class)]
final class WorkedListingTest extends TestCase
{
    use AssertsServedCompatibility;

    /** @var list<string> */
    protected $connectionsToTransact = [];

    /** @var class-string<Listing&Compatible> */
    private string $listing;

    protected function setUp(): void
    {
        parent::setUp();

        WorkedListingTables::create();
        WorkedListingTables::seed();
        JobWriteTransaction::$received = [];
        $this->listing = (new class extends Listing implements Compatible
        {
            use ServesCompatibility;

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return ListingMap::define($listing);
            }
        })::class;
        $this->app->instance(ServedModels::class, $this->servedOnly());
    }

    protected function tearDown(): void
    {
        WorkedListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_backfills_the_seeded_listings_through_their_operations_and_serves_the_same_rows_after_install(): void
    {
        Compatibility::prepare($this->listing);
        $this->artisan(
            command: 'compatibility:backfill',
            parameters: ['model' => $this->listing],
        )->assertSuccessful();
        $legacy = $this->rows();

        Compatibility::install(
            model: $this->listing,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame($legacy, $this->rows());
        $this->assertSame([
            ['Oak House', 'published', 'paid', false, null, null],
            ['Pine Flat', 'draft', 'unpaid', false, null, '2026-02-01 00:00:00+00'],
        ], array_map(fn (int $listing) => array_values((array) DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: $this->jobOf($listing),
        )->first(['name', 'lifecycle_status', 'payment_status', 'opted_in_to_zma', 'are_customer_downloads_enabled', 'deleted_at'])), [1, 2]));
        $this->assertSame([
            [WorkedListingTables::OAK_PLACE, '1 Oak St', true, WorkedListingTables::OAK_SITE, 47.6062, -122.3321],
            [WorkedListingTables::PINE_PLACE, '2 Pine Rd', false, null, null, null],
        ], array_map(fn (int $listing) => [
            ...array_values((array) DB::table('places')->where(
                column: 'job_id',
                operator: '=',
                value: $this->jobOf($listing),
            )->first(['id', 'name'])),
            DB::table('listing_compatibility_states')->where(
                column: 'job_id',
                operator: '=',
                value: $this->jobOf($listing),
            )->value('downloads_enabled_fallback'),
            ...array_values((array) (DB::table('job_sites')->where(
                column: 'job_id',
                operator: '=',
                value: $this->jobOf($listing),
            )->first(['id', 'latitude', 'longitude']) ?? ['id' => null, 'latitude' => null, 'longitude' => null])),
        ], [1, 2]));
        $this->assertSame([[1, 'served']], DB::table('job_sites_view')->get(['listing_id', 'listings'])->map(fn (object $row) => array_values((array) $row))->all());
    }

    #[Test]
    public function it_creates_a_listing_through_the_legacy_model_after_install(): void
    {
        $this->install();

        $created = $this->listing::query()->create([
            'uuid' => Listing::makeUuid(),
            'title' => 'Elm Cottage',
            'company_id' => WorkedListingTables::COMPANY,
            'address_id' => 3,
            'is_draft' => false,
            'downloads_enabled' => true,
        ]);
        $job = $this->jobOf($created->id);

        $this->assertSame(
            [3, 'Elm Cottage', 3, false, true, WorkedListingTables::COMPANY],
            [$created->id, ...array_values($this->listing::query()->findOrFail($created->id)->only(['title', 'address_id', 'is_draft', 'downloads_enabled', 'company_id']))],
        );
        $this->assertSame(
            ['Elm Cottage', 'published', 'unpaid', false, null],
            array_values((array) DB::table('jobs')->where(
                column: 'id',
                operator: '=',
                value: $job,
            )->first(['name', 'lifecycle_status', 'payment_status', 'opted_in_to_zma', 'are_customer_downloads_enabled'])),
        );
        $this->assertSame(
            ['3 Elm Ave', true, 45.5152, -122.6784],
            [
                DB::table('places')->where(
                    column: 'job_id',
                    operator: '=',
                    value: $job,
                )->value('name'),
                DB::table('listing_compatibility_states')->where(
                    column: 'job_id',
                    operator: '=',
                    value: $job,
                )->value('downloads_enabled_fallback'),
                ...array_values((array) DB::table('job_sites')->where(
                    column: 'job_id',
                    operator: '=',
                    value: $job,
                )->first(['latitude', 'longitude'])),
            ],
        );
    }

    #[Test]
    public function it_publishes_or_drafts_the_job_after_its_save_and_keeps_the_updated_at_the_legacy_write_stores(): void
    {
        $this->install();
        $oak = $this->listing::query()->findOrFail(1);

        $oak->update(['is_draft' => true]);
        $drafted = [DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: $this->jobOf(1),
        )->value('lifecycle_status'), $this->listing::query()->findOrFail(1)->is_draft];
        $oak->update(['is_draft' => false, 'updated_at' => '2026-03-01 08:00:00']);

        $this->assertSame(['draft', true], $drafted);
        $this->assertSame(
            ['published', false, '2026-03-01T08:00:00+00:00'],
            [
                DB::table('jobs')->where(
                    column: 'id',
                    operator: '=',
                    value: $this->jobOf(1),
                )->value('lifecycle_status'),
                $this->listing::query()->findOrFail(1)->is_draft,
                $this->listing::query()->findOrFail(1)->updated_at?->toIso8601String(),
            ],
        );
        $this->assertSame([[$this->jobOf(1)], [$this->jobOf(1)]], JobWriteTransaction::$received);
    }

    #[Test]
    public function it_writes_downloads_enabled_to_the_fallback_while_the_job_column_is_null_and_to_the_job_column_once_it_holds_a_value(): void
    {
        $this->install();
        $job = $this->jobOf(1);

        $this->listing::query()->findOrFail(1)->update(['downloads_enabled' => false]);
        $fallback = [
            DB::table('jobs')->where(
                column: 'id',
                operator: '=',
                value: $job,
            )->value('are_customer_downloads_enabled'),
            DB::table('listing_compatibility_states')->where(
                column: 'job_id',
                operator: '=',
                value: $job,
            )->value('downloads_enabled_fallback'),
            $this->listing::query()->findOrFail(1)->downloads_enabled,
        ];
        DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: $job,
        )->update(['are_customer_downloads_enabled' => false]);
        $this->listing::query()->findOrFail(1)->update(['downloads_enabled' => true]);

        $this->assertSame([null, false, false], $fallback);
        $this->assertSame(
            [true, false, true],
            [
                DB::table('jobs')->where(
                    column: 'id',
                    operator: '=',
                    value: $job,
                )->value('are_customer_downloads_enabled'),
                DB::table('listing_compatibility_states')->where(
                    column: 'job_id',
                    operator: '=',
                    value: $job,
                )->value('downloads_enabled_fallback'),
                $this->listing::query()->findOrFail(1)->downloads_enabled,
            ],
        );
    }

    #[Test]
    public function it_moves_the_place_to_a_new_address_deletes_the_job_site_without_coordinates_and_refuses_a_null_address(): void
    {
        $this->install();
        $job = $this->jobOf(1);

        $this->listing::query()->findOrFail(1)->update(['address_id' => 4]);
        $uncoordinated = [
            ...array_values($this->listing::query()->findOrFail(1)->only(['address_id', 'place_id', 'job_site_id'])),
            DB::table('places')->where(
                column: 'job_id',
                operator: '=',
                value: $job,
            )->value('name'),
            DB::table('job_sites')->where(
                column: 'job_id',
                operator: '=',
                value: $job,
            )->exists(),
        ];
        $this->listing::query()->findOrFail(1)->update(['address_id' => 3]);
        $site = DB::table('job_sites')->where(
            column: 'job_id',
            operator: '=',
            value: $job,
        )->first(['id', 'latitude', 'longitude']);

        try {
            $this->listing::query()->findOrFail(1)->update(['address_id' => null]);
            $refusal = 'no refusal';
        } catch (Refusal $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame([4, WorkedListingTables::OAK_PLACE, null, '4 Ash Ln', false], $uncoordinated);
        $this->assertSame(
            [3, WorkedListingTables::OAK_PLACE, $site?->id, 45.5152, -122.6784],
            [...array_values($this->listing::query()->findOrFail(1)->only(['address_id', 'place_id', 'job_site_id'])), $site?->latitude, $site?->longitude],
        );
        $this->assertSame($this->listing.' record 1 cannot take this write of $address_id, because ChangeListingAddress refused it: A listing keeps its address.', $refusal);
        $this->assertSame(3, $this->listing::query()->findOrFail(1)->address_id);
    }

    #[Test]
    public function it_soft_deletes_the_job_and_rolls_back_to_a_legacy_table_holding_the_served_rows(): void
    {
        $this->install();

        $this->listing::query()->findOrFail(1)->delete();
        $deleted = [
            $this->listing::query()->find(1),
            DB::table('jobs')->where(
                column: 'id',
                operator: '=',
                value: $this->jobOf(1),
            )->whereNotNull('deleted_at')->exists(),
        ];
        $served = $this->rows();
        Compatibility::rollback($this->listing);

        $this->assertSame([null, true], $deleted);
        $this->assertNotNull($served[0]['deleted_at']);
        $this->assertSame(['r', false], [DB::scalar("select relkind::text from pg_class where oid = 'listings'::regclass"), Schema::hasTable('listings_retained')]);
        $this->assertSame($served, $this->rows());
        $this->assertSame([[1, 'legacy']], DB::table('job_sites_view')->get(['listing_id', 'listings'])->map(fn (object $row) => array_values((array) $row))->all());
    }

    #[Test]
    public function it_serves_compatibility_for_a_job_created_natively(): void
    {
        $this->install();

        $this->assertServesCompatibility(
            model: $this->listing,
            nativeCreate: fn () => Job::query()->create([
                'name' => 'Birch Loft',
                'company_id' => WorkedListingTables::COMPANY,
            ]),
        );
    }

    private function install(): void
    {
        Compatibility::prepare($this->listing);
        $this->artisan(
            command: 'compatibility:backfill',
            parameters: ['model' => $this->listing],
        )->assertSuccessful();
        Compatibility::install(
            model: $this->listing,
            statementTimeout: CarbonInterval::seconds(30),
        );
        JobWriteTransaction::$received = [];
    }

    private function jobOf(int $listing): string
    {
        return (string) DB::table(Schema::hasTable('listings_retained') ? 'listings_retained' : 'listings')->where(
            column: 'id',
            operator: '=',
            value: $listing,
        )->value('job_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->listing::query()->withTrashed()->orderBy('id')->get()->map(function (Listing $listing): array {
            $attributes = $listing->attributesToArray();
            ksort($attributes);

            return $attributes;
        })->values()->all();
    }

    private function servedOnly(): ServedModels
    {
        $files = new Filesystem;
        $cache = (string) tempnam(
            directory: sys_get_temp_dir(),
            prefix: 'compatibility-served-',
        );
        $files->put(
            path: $cache,
            contents: '<?php return '.var_export(
                value: [$this->listing],
                return: true,
            ).';',
        );
        $served = new ServedModels(
            cache: $cache,
            scans: false,
            withoutCache: false,
            files: $files,
        );
        $served->all();
        $files->delete($cache);

        return $served;
    }
}
