<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\WriteFileMimeType;
use Tests\Fixtures\Support\Listings\ConfiguredJob;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\LeaveTitleUnchanged;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(RoundTrip::class)]
final class RoundTripTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        ListingTables::drop();
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_refuses_a_write_of_a_long_numeric_value_that_reads_back_another_value_past_the_precision_of_a_float(): void
    {
        DB::statement('create table tallies (id serial primary key, name text not null, total numeric(30, 2), created_at timestamptz, updated_at timestamptz)');
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null, settings jsonb, total numeric(30, 2))');
        DB::table('tallies')->insert([['name' => 'Oak', 'total' => '1']]);
        $tally = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'tallies';

            protected $guarded = [];

            public static function compatibility(Compatibility $tally): Compatibility
            {
                return $tally
                    ->retainedIn('tallies_retained')
                    ->servedFrom(root: ConfiguredJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'name', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'total', definition: fn (Attribute $attribute) => $attribute->from('total')));
            }
        };
        Event::listen('eloquent.saving: '.ConfiguredJob::class, function (ConfiguredJob $job): void {
            if ($job->getAttribute('total') === '12345678901234567890') {
                $job->setAttribute('total', '12345678901234567891');
            }
        });

        try {
            Compatibility::prepare($tally::class);
            DB::statement('update tallies set job_id = gen_random_uuid()');
            DB::statement('insert into configured_jobs (id, name, total) select job_id, name, total from tallies');
            Compatibility::install(
                model: $tally::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

            try {
                $tally->newQuery()->findOrFail(1)->update(['total' => '12345678901234567890']);
                $refusal = 'no refusal';
            } catch (CompatibilityWriteRefused $exception) {
                $refusal = $exception->getMessage();
            }

            $total = DB::table('configured_jobs')->value('total');
            $rounded = $tally->newQuery()->findOrFail(1)->update(['total' => '7']);
        } finally {
            DB::statement('drop view if exists tallies');
            DB::statement('drop table if exists tallies, tallies_retained, tallies_backfill, configured_jobs cascade');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type IN ('tallies'); END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }

        $this->assertStringContainsString("cannot take this write of \$total. Why: The write stores '12345678901234567890', and the view now serves total as '12345678901234567891.00'", $refusal);
        $this->assertSame(['1.00', true], [$total, $rounded]);
    }

    #[Test]
    public function it_refuses_and_rolls_back_an_operation_that_leaves_the_read_unchanged(): void
    {
        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $listing = $this->untitled()::query()->findOrFail(1);
        $before = DB::table('listings_retained')->where('id', 1)->value('mls_number');

        try {
            $listing->update(['title' => 'Oak Manor', 'mls_number' => 'MLS-1B']);
            $this->fail('The write was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringStartsWith("Tests\Fixtures\Support\Listings\Listing record 1 cannot take this write of \$title. Why: The write stores 'Oak Manor', and the view now serves title as 'Oak House'", str_replace(get_class($listing), Listing::class, $refusal->getMessage()));
        }

        $this->assertSame(['Oak House', $before], [DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('name'), DB::table('listings_retained')->where('id', 1)->value('mls_number')]);
    }

    #[Test]
    public function it_evaluates_a_computed_read_against_the_reloaded_successor_of_a_live_write(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $photo = Photo::query()->findOrFail(1);

        $photo->update(['mime_type' => 'image/webp']);

        $file = File::query()->whereKey(FileTables::fileOf(table: 'photos', id: 1))->firstOrFail();
        $this->assertSame(['image/webp', 'image/webp'], [$file->mime_type, File::metadataFor(new Collection([$file]))[$file->id]->mimeType]);
    }

    #[Test]
    public function it_refuses_and_rolls_back_a_live_write_whose_computed_read_returns_another_value(): void
    {
        FileTables::create();
        FileTables::seed();
        FileTables::install();
        $photo = $this->constantMime()::query()->findOrFail(1);
        $before = DB::table('files')->where('id', FileTables::fileOf(table: 'photos', id: 1))->value('mime_type');

        try {
            $photo->update(['mime_type' => 'image/webp']);
            $this->fail('The write was not refused.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringContainsString("cannot take this write of \$mime_type. Why: The write stores 'image/webp', and the view now serves mime_type as 'image/png'", $refusal->getMessage());
        }

        $this->assertSame($before, DB::table('files')->where('id', FileTables::fileOf(table: 'photos', id: 1))->value('mime_type'));
    }

    private function untitled(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'title', definition: fn (Attribute $attribute) => $attribute->from('name')->writtenBy(LeaveTitleUnchanged::class))
                        ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at')));
            }
        };
    }

    private function constantMime(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'photos';

            protected $guarded = [];

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return $photo
                    ->retainedIn('photos_retained')
                    ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
                        ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at'))
                        ->attribute(name: 'mime_type', definition: fn (Attribute $attribute) => $attribute
                            ->computedAs(read: fn (Collection $files): array => $files->mapWithKeys(fn (Model $file) => [$file->getKey() => (new FileMetadata('image/png'))->mimeType])->all())
                            ->writtenBy(WriteFileMimeType::class)));
            }
        };
    }
}
