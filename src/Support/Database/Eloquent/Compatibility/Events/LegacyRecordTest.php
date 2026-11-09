<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Events;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(LegacyRecord::class)]
final class LegacyRecordTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
    }

    protected function tearDown(): void
    {
        ListingTables::drop();
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_gives_a_listener_the_stored_record_as_its_original_and_the_converted_successor_write_as_its_attributes(): void
    {
        $seen = [];
        Event::listen('eloquent.updating: '.Listing::class, function (Listing $listing) use (&$seen): void {
            $seen[] = [$listing->getOriginal('price'), $listing->price, $listing->title, array_keys($listing->getDirty())];
        });
        Event::listen('eloquent.updated: '.Listing::class, function (Listing $listing) use (&$seen): void {
            $seen[] = [$listing->getOriginal('price'), $listing->price, $listing->title, array_keys($listing->getChanges())];
        });

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['price_cents' => 99]);

        $this->assertSame([['1250.50', '0.99', 'Oak House', ['price']], ['1250.50', '0.99', 'Oak House', ['price', 'updated_at']]], $seen);
    }

    #[Test]
    public function it_reads_the_view_once_before_the_successor_writes_and_once_after(): void
    {
        Event::listen('eloquent.updating: '.Listing::class, fn () => null);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        DB::enableQueryLog();

        $job->update(['name' => 'Oak Lodge']);

        $statements = array_map(fn (array $query) => (string) $query['query'], DB::getQueryLog());
        $write = array_search('update "jobs" set "name" = ?, "updated_at" = ? where "id" = ?', $statements, true);
        $reads = array_keys(array_filter($statements, fn (string $sql) => str_starts_with($sql, 'SELECT * FROM "listings"')));
        $computed = array_keys(array_filter($statements, fn (string $sql) => str_starts_with($sql, 'WITH "jobs" AS')));

        $this->assertIsInt($write);
        $this->assertCount(2, $reads);
        $this->assertCount(1, $computed);
        $this->assertSame([true, true, true], [$reads[0] < $computed[0], $computed[0] < $write, $reads[1] > $write]);

        DB::flushQueryLog();
        $job->update(['name' => 'Oak Manor']);

        $this->assertSame(
            ["select array_to_string(current_schemas(true), ',') as", 'SELECT * FROM "listings"', 'WITH "jobs" AS (SELECT', 'update "jobs" set "name"', 'SELECT * FROM "listings"'],
            array_map(fn (array $query) => implode(' ', array_slice(explode(' ', (string) $query['query']), 0, 4)), DB::getQueryLog()),
        );
    }

    #[Test]
    public function it_keeps_the_stored_value_before_a_raw_expression_writes_and_reads_the_written_one_after(): void
    {
        $seen = [];

        foreach (['updating', 'updated'] as $event) {
            Event::listen("eloquent.{$event}: ".Listing::class, function (Listing $listing) use (&$seen, $event): void {
                $seen[] = [$event, $listing->price];
            });
        }

        Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->update(['price_cents' => DB::raw('price_cents + 1')]);

        $this->assertSame([['updating', '1250.50'], ['updated', '1250.51']], $seen);
    }

    #[Test]
    public function it_forwards_the_creating_and_created_events_of_a_native_root_create_with_the_retained_row_it_carries(): void
    {
        $seen = [];

        foreach (['creating', 'created'] as $event) {
            Event::listen(
                events: "eloquent.{$event}: ".Listing::class,
                listener: function () use (&$seen, $event): void {
                    $seen[] = $event;
                },
            );
        }

        $job = DB::transaction(fn () => Job::query()->create(['name' => 'Birch House', 'company_id' => ListingTables::COMPANY]));

        $this->assertSame(['creating', 'created'], $seen);
        $this->assertSame(1, DB::table('listings_retained')->where(column: 'job_id', operator: '=', value: $job->id)->count());
    }

    #[Test]
    public function it_gives_a_dependent_write_the_record_with_the_values_the_dependent_writes(): void
    {
        $seen = [];
        Event::listen('eloquent.updating: '.Listing::class, function (Listing $listing) use (&$seen): void {
            $seen[] = [$listing->id, $listing->getOriginal('street'), $listing->street, array_keys($listing->getDirty())];
        });

        Place::query()->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->whereKey(ListingTables::jobOf(1))->value('id'),
        )->firstOrFail()->update(['street' => '5 Oak St']);
        Place::query()->create(['job_id' => Job::withTrashed()->whereKey(ListingTables::jobOf(2))->value('id'), 'street' => '2 Pine St']);

        $this->assertSame([[1, '1 Oak St', '5 Oak St', ['street']], [2, null, '2 Pine St', ['street']]], $seen);
    }

    #[Test]
    public function it_reads_an_external_attribute_of_a_forwarded_record_through_its_successor_and_keeps_it_for_a_delete(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $seen = [];

        foreach (['updating', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: ".Photo::class, function (Photo $photo) use (&$seen, $event): void {
                $seen[] = [$event, $photo->id, $photo->mime_type, $photo->exists];
            });
        }

        $file = File::query()->whereKey(DB::table('photos_retained')->where(column: 'id', operator: '=', value: 3)->value('file_id'))->firstOrFail();
        $file->update(['filename' => 'elm-2.png']);
        $calls = count(FakeMediaLibrary::bound()->calls);
        $file->delete();

        $this->assertSame([['updating', 3, 'image/png', true], ['deleted', 3, 'image/png', false]], $seen);
        $this->assertSame([['fetch', [(string) $file->zml_asset_id]]], array_slice(FakeMediaLibrary::bound()->calls, $calls));
    }

    #[Test]
    public function it_gives_an_after_event_the_external_value_the_written_successor_reads_and_keeps_the_stored_one_as_the_original(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $seen = [];
        Event::listen('eloquent.updating: '.Photo::class, function (Photo $photo) use (&$seen): void {
            $seen[] = ['updating', $photo->mime_type];
        });
        Event::listen('eloquent.updated: '.Photo::class, function (Photo $photo) use (&$seen): void {
            $seen[] = ['updated', $photo->mime_type, $photo->getOriginal('mime_type'), $photo->wasChanged('mime_type')];
        });
        $asset = FakeMediaLibrary::bound()->store(new FileMetadata('image/webp'));

        File::query()->whereKey(DB::table('photos_retained')->where(column: 'id', operator: '=', value: 3)->value('file_id'))->firstOrFail()->update(['zml_asset_id' => $asset]);

        $this->assertSame([['updating', 'image/png'], ['updated', 'image/webp', 'image/png', true]], $seen);
    }
}
