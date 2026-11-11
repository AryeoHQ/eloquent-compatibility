<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
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
use Tests\Fixtures\Support\Listings\Place;
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
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null, created_at timestamptz, settings jsonb, total numeric(30, 2))');
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
            if (in_array($job->getAttribute('total'), ['12345678901234567890', '12345678901234567890e0'], true)) {
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

            try {
                $tally->newQuery()->findOrFail(1)->update(['total' => '12345678901234567890e0']);
                $exponent = 'no refusal';
            } catch (CompatibilityWriteRefused $exception) {
                $exponent = $exception->getMessage();
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
        $this->assertStringContainsString("cannot take this write of \$total. Why: The write stores '12345678901234567890e0', and the view now serves total as '12345678901234567891.00'", $exponent);
        $this->assertSame(['1.00', true], [$total, $rounded]);
    }

    #[Test]
    public function it_refuses_a_text_write_whose_successor_listener_drops_its_leading_zeros(): void
    {
        $this->installListings();
        Event::listen('eloquent.saving: '.Job::class, function (Job $job): void {
            $job->name = ltrim($job->name, '0');
        });

        try {
            Listing::query()->findOrFail(1)->update(['title' => '00123']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringStartsWith(Listing::class." record 1 cannot take this write of \$title. Why: The write stores '00123', and the view now serves title as '123'", $refusal);
        $this->assertSame('Oak House', DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('name'));
    }

    #[Test]
    public function it_passes_a_json_write_whose_object_keys_the_jsonb_column_reorders(): void
    {
        $holder = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'holders';

            protected $guarded = [];

            protected $casts = ['settings' => 'array'];

            public static function compatibility(Compatibility $holder): Compatibility
            {
                return $holder
                    ->retainedIn('holders_retained')
                    ->servedFrom(root: ConfiguredJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'name', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'settings', definition: fn (Attribute $attribute) => $attribute->from('settings')));
            }
        };

        $stored = $this->holding(
            holder: $holder,
            columns: 'settings jsonb',
            row: ['name' => 'Oak', 'settings' => '{"a": 1}'],
            copied: ['settings'],
            write: function () use ($holder): mixed {
                $holder->newQuery()->findOrFail(1)->update(['settings' => ['zeta' => 1, 'a' => 2]]);

                return DB::table('configured_jobs')->value('settings');
            },
        );

        $this->assertSame('{"a": 2, "zeta": 1}', $stored);
    }

    #[Test]
    public function it_passes_a_date_time_value_written_to_an_attribute_the_legacy_model_does_not_cast_as_a_date(): void
    {
        $holder = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'holders';

            protected $guarded = [];

            public static function compatibility(Compatibility $holder): Compatibility
            {
                return $holder
                    ->retainedIn('holders_retained')
                    ->servedFrom(root: ConfiguredJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'name', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'stamped_at', definition: fn (Attribute $attribute) => $attribute->from('stamped_at')));
            }
        };

        $stored = $this->holding(
            holder: $holder,
            columns: 'stamped_at timestamp(0) without time zone',
            row: ['name' => 'Oak', 'stamped_at' => '2026-01-01 00:00:00'],
            copied: ['stamped_at'],
            write: function () use ($holder): array {
                $stored = [$holder->newQuery()->whereKey(1)->update(['stamped_at' => Date::parse('2026-05-07 12:00:00')])];
                $stored[] = DB::table('configured_jobs')->value('stamped_at');
                $record = $holder->newQuery()->findOrFail(1);
                $record->setAttribute('stamped_at', Date::parse('2026-05-08 12:00:00'));
                $stored[] = $record->save();
                $stored[] = DB::table('configured_jobs')->value('stamped_at');

                return $stored;
            },
        );

        $this->assertSame([1, '2026-05-07 12:00:00', true, '2026-05-08 12:00:00'], $stored);
    }

    #[Test]
    public function it_refuses_a_write_whose_successor_listener_removes_the_record_it_wrote(): void
    {
        $this->installListings();
        Event::listen('eloquent.saved: '.Job::class, function (Job $job): void {
            $job->forceDelete();
        });

        try {
            Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame(Listing::class.' record 1 cannot take this write, because no row holds the record after it. Why: Read without its hide() rules, no row serves record 1 once the write has saved, so a listener or a trigger removed the record the write stored. Instead: Keep the successors of the record in place while a write through Listing runs.', $refusal);
        $this->assertSame('Oak House', Listing::query()->find(1)?->title);
    }

    #[Test]
    public function it_refuses_a_write_whose_cast_cannot_read_the_served_value_and_keeps_the_cast_failure_as_the_cause(): void
    {
        $holder = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'holders';

            protected $guarded = [];

            protected $casts = ['name' => 'encrypted'];

            public static function compatibility(Compatibility $holder): Compatibility
            {
                return $holder
                    ->retainedIn('holders_retained')
                    ->servedFrom(root: ConfiguredJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'name', definition: fn (Attribute $attribute) => $attribute->from('name')));
            }
        };
        Event::listen('eloquent.saving: '.ConfiguredJob::class, function (ConfiguredJob $job): void {
            $job->setRawAttributes(array_replace($job->getAttributes(), ['name' => 'tampered']));
        });

        $refusal = $this->holding(
            holder: $holder,
            columns: 'note text',
            row: ['name' => Crypt::encryptString('Oak')],
            copied: [],
            write: function () use ($holder): null|CompatibilityWriteRefused {
                try {
                    $holder->newQuery()->findOrFail(1)->update(['name' => 'Oak Lodge']);
                } catch (CompatibilityWriteRefused $exception) {
                    return $exception;
                }

                return null;
            },
        );

        $this->assertStringContainsString("cannot take this write of \$name. Why: The write stores '", $refusal?->getMessage() ?? 'no refusal');
        $this->assertInstanceOf(DecryptException::class, $refusal?->getPrevious());
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

    #[Test]
    public function it_refuses_and_rolls_back_a_write_whose_successor_listener_hides_the_record_and_loses_the_written_value(): void
    {
        $this->installListings();
        $elm = Job::query()->whereKey(ListingTables::jobOf(3))->value('id');
        Event::listen('eloquent.saving: '.Job::class, function (Job $job) use ($elm): void {
            $job->merged_into_job_id = $elm;
            $job->name = 'Tampered';
        });

        try {
            Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringStartsWith(Listing::class." record 1 cannot take this write of \$title. Why: The write stores 'Oak Manor', and the record, which a hide() rule now hides, reads title as 'Tampered'", $refusal);
        $this->assertSame(['Oak House', null, 'Oak House'], [DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('name'), DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('merged_into_job_id'), Listing::query()->find(1)?->title]);
    }

    #[Test]
    public function it_passes_a_write_whose_successor_listener_hides_the_record_and_keeps_every_written_value_reading_it_in_one_query(): void
    {
        $this->installListings();
        $elm = Job::query()->whereKey(ListingTables::jobOf(3))->value('id');
        Event::listen('eloquent.saving: '.Job::class, function (Job $job) use ($elm): void {
            $job->merged_into_job_id = $elm;
        });
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $written = [Listing::query()->findOrFail(1)->update(['title' => 'Oak Manor'])];
        $statements = [];
        $written[] = Listing::query()->findOrFail(3)->update(['title' => 'Elm Lodge']);
        $reads = array_map(fn (string $pattern) => count(array_filter($statements, fn (string $sql) => preg_match($pattern, $sql) === 1)), ['/^SELECT \* FROM \(SELECT/', '/pg_attribute|domain_chain/']);

        $this->assertSame([[true, true], [1, 0]], [$written, $reads]);
        $this->assertSame([['Oak Manor', 'Elm Lodge'], null, null], [[DB::table('jobs')->where('id', ListingTables::jobOf(1))->value('name'), DB::table('jobs')->where('id', ListingTables::jobOf(3))->value('name')], Listing::query()->find(1), Listing::query()->find(3)]);
    }

    #[Test]
    public function it_passes_a_write_that_moves_its_own_record_into_a_hide_rule_and_refuses_one_whose_listener_rewrites_the_written_value_into_it(): void
    {
        ListingTables::create();
        ListingTables::seed();
        $withheld = $this->withheld();
        Compatibility::prepare($withheld::class);
        ListingTables::backfill();
        Compatibility::install(
            model: $withheld::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Event::listen('eloquent.saving: '.Place::class, function (Place $place): void {
            if ($place->city === 'Tacoma') {
                $place->city = 'Withheld';
            }
        });
        $city = fn (int $listing): mixed => DB::table('places')->where('job_id', ListingTables::jobOf($listing))->value('city');

        $written = $withheld::query()->findOrFail(1)->update(['city' => 'Withheld']);

        try {
            $withheld::query()->findOrFail(3)->update(['city' => 'Tacoma']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertSame([true, 'Withheld', null], [$written, $city(1), $withheld::query()->find(1)]);
        $this->assertStringContainsString("record 3 cannot take this write of \$city. Why: The write stores 'Tacoma', and the record, which a hide() rule now hides, reads city as 'Withheld'", $refusal);
        $this->assertSame([null, 3], [$city(3), $withheld::query()->find(3)?->getKey()]);
    }

    #[Test]
    public function it_refuses_and_rolls_back_a_record_and_a_mass_increment_whose_successor_listener_stores_another_sum(): void
    {
        $this->installListings();
        Event::listen('eloquent.saving: '.Job::class, function (Job $job): void {
            if ($job->isDirty('price_cents')) {
                $job->price_cents = min($job->price_cents, 125100);
            }
        });
        $job = ListingTables::jobOf(1);

        $record = $this->refused(fn () => Listing::query()->findOrFail(1)->increment('price', 5));
        $mass = $this->refused(fn () => Listing::query()->whereKey(1)->increment('price', 5));

        foreach ([$record, $mass] as $refusal) {
            $this->assertStringStartsWith(Listing::class." record 1 cannot take this write of \$price. Why: The write stores '1255.50', and the view now serves price as '1251.00'", $refusal);
        }

        $this->assertSame(125050, DB::table('jobs')->where('id', $job)->value('price_cents'));
    }

    #[Test]
    public function it_compares_a_successor_boolean_with_the_value_an_uncast_legacy_attribute_writes(): void
    {
        $holder = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'holders';

            protected $guarded = [];

            public static function compatibility(Compatibility $holder): Compatibility
            {
                return $holder
                    ->retainedIn('holders_retained')
                    ->servedFrom(root: ConfiguredJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(name: 'name', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'lit', definition: fn (Attribute $attribute) => $attribute->from('lit')));
            }
        };
        Event::listen('eloquent.saving: '.ConfiguredJob::class, function (ConfiguredJob $job): void {
            if ($job->getAttribute('name') === 'Negated') {
                $job->setAttribute('lit', ! $job->getAttribute('lit'));
            }
        });

        $read = $this->holding(
            holder: $holder,
            columns: 'lit boolean',
            row: ['name' => 'Oak', 'lit' => false],
            copied: ['lit'],
            write: function () use ($holder): array {
                $holder->newQuery()->findOrFail(1)->update(['lit' => '1']);
                $read = [$holder->newQuery()->findOrFail(1)->getAttribute('lit')];
                $holder->newQuery()->findOrFail(1)->update(['lit' => 0]);
                $read[] = $holder->newQuery()->findOrFail(1)->getAttribute('lit');
                $read[] = $this->refused(fn () => $holder->newQuery()->findOrFail(1)->update(['name' => 'Negated', 'lit' => '1']));
                $read[] = DB::table('configured_jobs')->value('lit');

                return $read;
            },
        );

        $this->assertSame([true, false], [$read[0], $read[1]]);
        $this->assertStringContainsString("record 1 cannot take this write of \$lit. Why: The write stores '1', and the view now serves lit as false", (string) $read[2]);
        $this->assertFalse($read[3]);
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function refused(Closure $write): string
    {
        try {
            $write();
        } catch (CompatibilityWriteRefused $refusal) {
            return $refusal->getMessage();
        }

        $this->fail('The write was not refused.');
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $copied
     * @param  Closure(): mixed  $write
     */
    private function holding(Model $holder, string $columns, array $row, array $copied, Closure $write): mixed
    {
        DB::statement('create table holders (id serial primary key, name text not null, '.$columns.', created_at timestamptz, updated_at timestamptz)');
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null, created_at timestamptz, settings jsonb, stamped_at timestamp(0) without time zone, lit boolean)');
        DB::table('holders')->insert($row);
        $copy = implode('', array_map(fn (string $column) => ', '.$column, $copied));

        try {
            Compatibility::prepare($holder::class);
            DB::statement('update holders set job_id = gen_random_uuid()');
            DB::statement('insert into configured_jobs (id, name'.$copy.') select job_id, name'.$copy.' from holders');
            Compatibility::install(
                model: $holder::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

            return $write();
        } finally {
            DB::statement('drop view if exists holders');
            DB::statement('drop table if exists holders, holders_retained, holders_backfill, configured_jobs cascade');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type IN ('holders'); END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }
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

    private function withheld(): Model
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
                        ->attribute(name: 'title', definition: fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute(name: 'company_id', definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A job never changes company.', ignoreWrites: false))
                        ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at'))
                        ->related(
                            relation: fn (Job $job) => $job->rootPlace(),
                            successor: fn (Successor $place) => $place
                                ->atMostOne(because: 'A listing has one address.')
                                ->attribute(name: 'city', definition: fn (Attribute $attribute) => $attribute->from('city')),
                        ))
                    ->hide(fn (Builder $jobs) => $jobs->whereHas('rootPlace', fn (Builder $places) => $places->where('city', 'Withheld')), because: 'A withheld address keeps its listing off the site.');
            }
        };
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
