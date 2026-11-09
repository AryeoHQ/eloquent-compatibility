<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\Company;
use Tests\Fixtures\Support\Listings\ConfiguredJob;
use Tests\Fixtures\Support\Listings\DefaultedPlace;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(MassWriter::class)]
#[CoversClass(RetainedRows::class)]
final class MassWriterTest extends TestCase
{
    private const string OTHER_COMPANY = 'b2c3d4e5-0000-4000-8000-000000000002';

    /** @var list<string> */
    protected $connectionsToTransact = [];

    /** @var null|array{resource, array<int, resource>} */
    private null|array $concurrent = null;

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
        Schema::table('listings', function (Blueprint $table): void {
            $table->integer('views')->default(5);
        });
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
    }

    protected function tearDown(): void
    {
        if ($this->concurrent !== null) {
            proc_terminate($this->concurrent[0]);
            proc_close($this->concurrent[0]);
            $this->concurrent = null;
        }

        ListingTables::drop();
        Schema::dropIfExists('company_profiles');
        Schema::dropIfExists('companies');

        parent::tearDown();
    }

    #[Test]
    public function it_writes_each_owning_table_once_and_fires_no_model_events(): void
    {
        Event::fake();

        $updated = Listing::query()->where('price', '>', 50)->update(['title' => 'Renamed', 'price' => '10.25', 'city' => 'Tacoma', 'latitude' => 1.5, 'mls_number' => 'MLS-X']);

        $this->assertSame(2, $updated);
        $this->assertSame([['Renamed', 1025], ['Renamed', 1025]], DB::table('jobs')->join(
            table: 'listings_retained',
            first: 'listings_retained.job_id',
            operator: '=',
            second: 'jobs.id',
        )->whereIn(
            column: 'listings_retained.id',
            values: [1, 3],
        )->orderBy('listings_retained.id')->get(['jobs.name', 'jobs.price_cents'])->map(fn (object $job) => array_values((array) $job))->all());
        $this->assertSame(['Pine Flat', null], array_values((array) DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: ListingTables::jobOf(2),
        )->first(['name', 'price_cents'])));
        $this->assertSame(['Tacoma', 'Tacoma'], DB::table('places')->join(
            table: 'listings_retained',
            first: 'listings_retained.job_id',
            operator: '=',
            second: 'places.job_id',
        )->orderBy('listings_retained.id')->pluck('places.city')->all());
        $this->assertSame([1.5, 1.5], DB::table('job_sites')->join(
            table: 'listings_retained',
            first: 'listings_retained.job_id',
            operator: '=',
            second: 'job_sites.job_id',
        )->orderBy('listings_retained.id')->pluck('job_sites.latitude')->all());
        $this->assertSame(['MLS-X', 'MLS-X'], DB::table('listings_retained')->whereIn(
            column: 'listings_retained.id',
            values: [1, 3],
        )->orderBy('listings_retained.id')->pluck('mls_number')->all());
        $this->assertSame([], array_values(array_filter(array_keys(Event::dispatchedEvents()), fn (string $event) => str_starts_with($event, 'eloquent.'))));
    }

    #[Test]
    public function it_runs_the_same_number_of_statements_for_one_matching_row_and_for_a_hundred(): void
    {
        DB::table('jobs')->insert(array_fill(0, 100, ['name' => 'Bulk', 'company_id' => ListingTables::COMPANY, 'price_cents' => 100]));
        self::serveNativeJobs();
        DB::table('jobs')->insert(['name' => 'Single', 'company_id' => ListingTables::COMPANY, 'price_cents' => 100]);
        self::serveNativeJobs();
        $write = ['price' => '2.00', 'street' => '5 Elm St', 'latitude' => 2.5, 'mls_number' => 'MLS-B'];

        $one = $this->statements(fn () => Listing::query()->where('title', 'Single')->update($write));
        $hundred = $this->statements(fn () => Listing::query()->where('title', 'Bulk')->update($write));

        $this->assertSame([1, 100], [$one[0], $hundred[0]]);
        $this->assertSame($one[1], $hundred[1]);
        $this->assertSame(101, DB::table('jobs')->where('price_cents', 200)->count());
        $this->assertSame(101, DB::table('places')->where('street', '5 Elm St')->whereNull('parent_id')->count());
        $this->assertSame(101, DB::table('job_sites')->where('latitude', 2.5)->count());
        $this->assertSame(101, DB::table('listings_retained')->where('mls_number', 'MLS-B')->count());
    }

    #[Test]
    public function it_keeps_reaching_every_table_when_the_write_changes_the_attribute_it_filters_on(): void
    {
        $updated = Listing::query()->where('title', 'Oak House')->where('price', '>', 1000)->update(['title' => 'Oak Manor', 'price' => '1.00', 'street' => '2 Oak St', 'mls_number' => 'MLS-1B']);
        $job = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();

        $this->assertSame(1, $updated);
        $this->assertSame(['Oak Manor', 100], [$job->name, $job->price_cents]);
        $this->assertSame(['2 Oak St'], DB::table('places')->where('job_id', $job->id)->pluck('street')->all());
        $this->assertSame(['MLS-1B'], DB::table('listings_retained')->where('job_id', $job->id)->pluck('mls_number')->all());
    }

    #[Test]
    public function it_creates_the_related_rows_a_matching_record_lacks(): void
    {
        Listing::withTrashed()->whereKey([1, 2])->update(['street' => '7 Ash St', 'latitude' => 3.5]);
        $pine = Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail();

        $this->assertSame([['7 Ash St', null, null]], DB::table('places')->where('job_id', $pine->id)->get(['street', 'city', 'parent_id'])->map(fn (object $place) => array_values((array) $place))->all());
        $this->assertNotNull(DB::table('places')->where('job_id', $pine->id)->value('created_at'));
        $this->assertSame([3.5], DB::table('job_sites')->where('job_id', $pine->id)->pluck('latitude')->all());
        $this->assertSame(2, DB::table('places')->where('street', '7 Ash St')->count());
        $this->assertSame(['7 Ash St', 3.5], [Listing::withTrashed()->findOrFail(2)->street, Listing::withTrashed()->findOrFail(2)->latitude]);
    }

    #[Test]
    public function it_writes_each_value_as_its_column_type_when_a_later_schema_on_the_search_path_holds_the_tables(): void
    {
        $searchPath = DB::scalar("select current_setting('search_path')");
        DB::statement('create schema compatibility_tenant');
        DB::statement('create table compatibility_tenant.invoices (id serial primary key)');

        try {
            DB::select("select set_config('search_path', ?, false)", ['compatibility_tenant, '.$searchPath]);
            $schema = DB::scalar('select current_schema()');
            $updated = Listing::withTrashed()->whereKey([1, 2])->update(['latitude' => 3.5, 'featured' => false]);
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$searchPath]);
            DB::statement('drop schema compatibility_tenant cascade');
        }

        $pine = Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail();

        $this->assertSame(['compatibility_tenant', 2], [$schema, $updated]);
        $this->assertSame([3.5], DB::table('job_sites')->where('job_id', $pine->id)->pluck('latitude')->all());
        $this->assertSame([false, false], DB::table('listings_retained')->whereIn(
            column: 'listings_retained.id',
            values: [1, 2],
        )->pluck('featured')->all());
        $this->assertSame([[3.5, false], [3.5, false]], Listing::withTrashed()->whereKey([1, 2])->orderBy('id')->get()->map(fn (Listing $listing) => [$listing->latitude, $listing->featured])->all());
    }

    #[Test]
    public function it_creates_a_missing_dependent_with_the_attribute_defaults_of_its_model_as_a_record_write_does(): void
    {
        $defaulted = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'listings';

            protected $guarded = [];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->hasOne(DefaultedPlace::class),
                            definition: fn (Successor $place) => $place
                                ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                                ->attribute('city', fn (Attribute $attribute) => $attribute->from('city')),
                        ));
            }
        };
        DB::table('jobs')->insert([['name' => 'Birch Lodge', 'company_id' => ListingTables::COMPANY], ['name' => 'Cedar Lodge', 'company_id' => ListingTables::COMPANY]]);
        self::serveNativeJobs();
        $legacyId = fn (string $name): int => (int) DB::table('listings_retained')->where(
            column: 'job_id',
            operator: '=',
            value: Job::query()->where(
                column: 'name',
                operator: '=',
                value: $name,
            )->value('id'),
        )->value('id');
        $places = fn (int $legacyId): array => DB::table('places')->join(
            table: 'listings_retained',
            first: 'listings_retained.job_id',
            operator: '=',
            second: 'places.job_id',
        )->where(
            column: 'listings_retained.id',
            operator: '=',
            value: $legacyId,
        )->get(['places.street', 'places.city', 'places.created_at'])->map(fn (object $place) => [$place->street, $place->city, $place->created_at !== null])->all();

        $defaulted->newQuery()->whereKey(2)->update(['city' => 'Tacoma']);
        $defaulted->newQuery()->whereKey($legacyId('Birch Lodge'))->update(['street' => '9 Birch St']);
        $defaulted->newQuery()->findOrFail($legacyId('Cedar Lodge'))->update(['street' => '9 Cedar St']);

        $this->assertSame([['Unknown St', 'Tacoma', true]], $places(2));
        $this->assertSame([['9 Birch St', 'Unknown', true]], $places($legacyId('Birch Lodge')));
        $this->assertSame([['9 Cedar St', 'Unknown', true]], $places($legacyId('Cedar Lodge')));
    }

    #[Test]
    public function it_creates_no_dependent_for_a_record_whose_dependent_the_write_moves_out_of_its_relation(): void
    {
        $seattle = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->hasOne(Place::class)->where('city', 'Seattle'),
                            definition: fn (Successor $place) => $place
                                ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                                ->attribute('city', fn (Attribute $attribute) => $attribute->from('city')),
                        ));
            }
        };

        $updated = $seattle->newQuery()->whereKey(1)->update(['street' => '2 Oak St', 'city' => 'Tacoma']);
        $oak = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();

        $this->assertSame(1, $updated);
        $this->assertSame([['2 Oak St', 'Tacoma']], DB::table('places')->where('job_id', $oak->id)->get(['street', 'city'])->map(fn (object $place) => array_values((array) $place))->all());
    }

    #[Test]
    public function it_creates_one_dependent_for_each_parent_that_several_matching_records_share(): void
    {
        $this->createCompanies();
        DB::table('companies')->insert(['id' => ListingTables::COMPANY]);
        $profiled = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->belongsTo(Company::class, 'company_id'),
                            definition: fn (Successor $company) => $company
                                ->related(fn (Company $company) => $company->profile(), fn (Successor $profile) => $profile
                                    ->attribute('tagline', fn (Attribute $attribute) => $attribute->from('tagline'))),
                        ));
            }
        };

        $updated = $profiled->newQuery()->whereKey([1, 3])->update(['tagline' => 'Homes by the water']);

        $this->assertSame(2, $updated);
        $this->assertSame([[ListingTables::COMPANY, 'Homes by the water']], DB::table('company_profiles')->get(['company_id', 'tagline'])->map(fn (object $profile) => array_values((array) $profile))->all());
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function profilesBeforeACompanyChange(): iterable
    {
        yield 'an existing profile' => ['Old tagline'];
        yield 'a missing profile' => [null];
    }

    #[DataProvider('profilesBeforeACompanyChange')]
    #[Test]
    public function it_writes_the_dependent_the_record_reached_before_the_same_write_changed_the_key_that_reaches_it(null|string $tagline): void
    {
        $this->createCompanies();
        DB::table('companies')->insert([['id' => ListingTables::COMPANY], ['id' => self::OTHER_COMPANY]]);
        DB::table('company_profiles')->insert(array_values(array_filter([
            $tagline === null ? null : ['company_id' => ListingTables::COMPANY, 'tagline' => $tagline],
            ['company_id' => self::OTHER_COMPANY, 'tagline' => 'Other tagline'],
        ])));
        $profiled = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->belongsTo(Company::class, 'company_id'),
                            definition: fn (Successor $company) => $company
                                ->related(fn (Company $company) => $company->profile(), fn (Successor $profile) => $profile
                                    ->attribute('tagline', fn (Attribute $attribute) => $attribute->from('tagline'))),
                        ));
            }
        };

        $updated = $profiled->newQuery()->whereKey(1)->update(['company_id' => self::OTHER_COMPANY, 'tagline' => 'Homes by the water']);

        $this->assertSame(1, $updated);
        $this->assertSame(self::OTHER_COMPANY, Job::query()->whereKey(ListingTables::jobOf(1))->value('company_id'));
        $this->assertSame([[ListingTables::COMPANY, 'Homes by the water'], [self::OTHER_COMPANY, 'Other tagline']], DB::table('company_profiles')->orderBy('company_id')->get(['company_id', 'tagline'])->map(fn (object $profile) => array_values((array) $profile))->all());
    }

    #[Test]
    public function it_creates_a_dependent_under_a_parent_the_same_write_creates(): void
    {
        $sited = $this->sited();
        $pine = Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail();

        $updated = $sited->newQuery()->whereKey([1, 2])->update(['street' => '7 Ash St', 'latitude' => 3.5]);

        $this->assertSame(2, $updated);
        $this->assertSame(['7 Ash St'], DB::table('places')->where('job_id', $pine->id)->pluck('street')->all());
        $this->assertSame([3.5], DB::table('job_sites')->where('job_id', $pine->id)->pluck('latitude')->all());
        $this->assertSame([3.5, 3.5], DB::table('job_sites')->join(
            table: 'listings_retained',
            first: 'listings_retained.job_id',
            operator: '=',
            second: 'job_sites.job_id',
        )->whereIn(
            column: 'listings_retained.id',
            values: [1, 2],
        )->pluck('latitude')->all());
    }

    #[Test]
    public function it_writes_the_existing_dependent_that_a_parent_the_same_write_creates_reaches_rather_than_create_a_second(): void
    {
        $sited = $this->sited();
        $oak = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        $elm = Job::query()->whereKey(ListingTables::jobOf(3))->firstOrFail();
        DB::table('places')->whereIn('job_id', [$oak->id, $elm->id])->delete();

        $updated = [
            $sited->newQuery()->whereKey(1)->update(['street' => '7 Ash St', 'latitude' => 3.5]),
            $sited->newQuery()->findOrFail(3)->forceFill(['street' => '8 Ash St', 'latitude' => 4.5])->save(),
        ];

        $this->assertSame([1, true], $updated);
        $this->assertSame([[$oak->id, '7 Ash St'], [$elm->id, '8 Ash St']], DB::table('places')->whereIn('job_id', [$oak->id, $elm->id])->orderBy('street')->get(['job_id', 'street'])->map(fn (object $place) => [$place->job_id, $place->street])->all());
        $this->assertSame([[$oak->id, 3.5], [$elm->id, 4.5]], DB::table('job_sites')->whereIn('job_id', [$oak->id, $elm->id])->orderBy('latitude')->get(['job_id', 'latitude'])->map(fn (object $site) => [$site->job_id, $site->latitude])->all());
    }

    #[Test]
    public function it_creates_no_related_row_for_a_null_value(): void
    {
        Listing::withTrashed()->whereKey(2)->update(['street' => null, 'latitude' => null]);
        $pine = Job::withTrashed()->whereKey(ListingTables::jobOf(2))->firstOrFail();

        $this->assertSame([0, 0], [DB::table('places')->where('job_id', $pine->id)->count(), DB::table('job_sites')->where('job_id', $pine->id)->count()]);
    }

    #[Test]
    public function it_adds_an_amount_through_a_linear_conversion_and_to_a_legacy_only_column(): void
    {
        $oak = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        DB::table('listings_retained')->where('job_id', $oak->id)->update(['views' => 40]);
        $elm = Job::query()->whereKey(ListingTables::jobOf(3))->firstOrFail();
        DB::table('listings_retained')->where(
            column: 'job_id',
            operator: '=',
            value: $elm->id,
        )->update(['views' => 5]);

        Listing::withTrashed()->increment('price', 1.5);
        Listing::withTrashed()->incrementEach(['views' => 2], ['mls_number' => 'MLS-Z']);
        Listing::withTrashed()->decrement('latitude', 0.5);
        Listing::query()->findOrFail(3)->increment('views', 3);

        $this->assertSame([1, 125200, 2, null, 3, 10149], DB::table('jobs')->join(
            table: 'listings_retained',
            first: 'listings_retained.job_id',
            operator: '=',
            second: 'jobs.id',
        )->orderBy('listings_retained.id')->get(['listings_retained.id as legacy_id', 'jobs.price_cents'])->flatMap(fn (object $job) => array_values((array) $job))->all());
        $this->assertSame([42, 7, 10], Listing::withTrashed()->orderBy('id')->pluck('views')->all());
        $this->assertSame(['MLS-Z', 'MLS-Z', 'MLS-Z'], Listing::withTrashed()->orderBy('id')->pluck('mls_number')->all());
        $this->assertSame([47.1062, null, -122.8321], Listing::withTrashed()->orderBy('id')->pluck('latitude')->all());
    }

    #[Test]
    public function it_reads_a_raw_expression_for_a_legacy_only_column_by_the_alias_the_write_reads_the_legacy_table_by(): void
    {
        $updated = Listing::query()->withoutGlobalScopes()->toBase()->from('listings as legacy')->where('legacy.id', 1)->update(['mls_number' => DB::raw('legacy.title')]);
        $aliased = Listing::query()->withoutGlobalScopes();
        $aliased->getQuery()->from('listings AS legacy');
        $eloquent = $aliased->where('legacy.id', 2)->update(['mls_number' => DB::raw("legacy.title || '-' || legacy.views")]);
        $incremented = Listing::query()->withoutGlobalScopes()->getQuery()->from('listings as legacy')->where('legacy.id', 3)->incrementEach(['views' => 2], ['mls_number' => DB::raw("legacy.title || '-' || legacy.views")]);
        $retained = fn (int $legacyId): array => array_values((array) DB::table('listings_retained')->where(
            column: 'listings_retained.id',
            operator: '=',
            value: $legacyId,
        )->first(['mls_number', 'views']));

        $this->assertSame([1, 1, 1], [$updated, $eloquent, $incremented]);
        $this->assertSame([['Oak House', 5], ['Pine Flat-5', 5], ['Elm Cottage-5', 7]], [$retained(1), $retained(2), $retained(3)]);
    }

    #[Test]
    public function it_writes_a_json_path_into_the_column_that_owns_it_for_a_mapped_a_related_and_a_legacy_only_column(): void
    {
        DB::statement('create table settings_holders (id serial primary key, name text not null, settings jsonb, preferences jsonb, options jsonb, created_at timestamptz, updated_at timestamptz)');
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null, settings jsonb)');
        DB::statement('create table configured_sites (id uuid primary key default gen_random_uuid(), configured_job_id uuid not null unique references configured_jobs, options jsonb)');
        DB::table('settings_holders')->insert([
            ['name' => 'Oak', 'settings' => '{"color": "red", "enabled": false}', 'preferences' => '{"theme": "light"}', 'options' => '{"a": 1}'],
            ['name' => 'Pine', 'settings' => '{"color": "blue"}', 'preferences' => '{"theme": "light", "font": "serif"}', 'options' => null],
            ['name' => 'Elm', 'settings' => null, 'preferences' => null, 'options' => null],
        ]);
        $holder = $this->sitedJsonHolder();

        try {
            Compatibility::prepare($holder::class);
            DB::statement('update settings_holders set job_id = gen_random_uuid()');
            DB::statement('insert into configured_jobs (id, name, settings) select job_id, name, settings from settings_holders');
            DB::statement('insert into configured_sites (configured_job_id, options) select configured_jobs.id, settings_holders.options from settings_holders join configured_jobs on configured_jobs.id = settings_holders.job_id where settings_holders.options is not null');
            Compatibility::install(
                model: $holder::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

            $updated = [
                $holder->newQuery()->whereKey([1, 3])->update(['settings->enabled' => true, 'preferences->theme' => 'dark', 'options->b' => 2]),
                $holder->newQuery()->whereKey(2)->toBase()->update(['settings_holders.settings->size' => ['width' => 3], 'preferences->font' => 'mono']),
            ];
            $rows = DB::table('settings_holders')->orderBy('id')->get(['settings', 'preferences', 'options'])->map(fn (object $row) => array_map(fn (mixed $json) => json_decode((string) $json, true), array_values((array) $row)))->all();
            $sites = DB::table('configured_sites')->count();
        } finally {
            DB::statement('drop view if exists settings_holders');
            DB::statement('drop table if exists settings_holders, settings_holders_retained, settings_holders_backfill, configured_sites, configured_jobs cascade');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type = 'settings_holders'; IF NOT EXISTS (SELECT FROM compatibility_backfills) THEN DROP TABLE compatibility_backfills; END IF; END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }

        $this->assertSame([2, 1], $updated);
        $this->assertSame([
            [['color' => 'red', 'enabled' => true], ['theme' => 'dark'], ['a' => 1, 'b' => 2]],
            [['size' => ['width' => 3], 'color' => 'blue'], ['font' => 'mono', 'theme' => 'light'], null],
            [null, null, null],
        ], $rows);
        $this->assertSame(1, $sites);
    }

    #[Test]
    public function it_evaluates_the_json_paths_and_expressions_of_one_mass_write_against_the_values_before_the_write_as_a_native_update_does(): void
    {
        DB::statement('create table settings_holders (id serial primary key, name text not null, preferences jsonb, label text, created_at timestamptz, updated_at timestamptz)');
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null)');
        DB::table('settings_holders')->insert([
            ['name' => 'Oak', 'preferences' => '{"theme": "light"}', 'label' => 'serif'],
            ['name' => 'Pine', 'preferences' => '{"theme": "light"}', 'label' => 'serif'],
        ]);
        DB::statement('create table settings_natives as select * from settings_holders');
        $writes = [
            1 => ['preferences->theme' => 'dark', 'label' => DB::raw("preferences->>'theme'")],
            2 => ['preferences->font' => DB::raw('to_jsonb(label)'), 'label' => 'mono'],
        ];
        $rows = fn (string $table): array => DB::table($table)->orderBy('id')->get(['preferences', 'label'])->map(fn (object $row) => [json_decode((string) $row->preferences, true), $row->label])->all();
        $holder = $this->jsonHolder();

        try {
            foreach ($writes as $id => $values) {
                DB::table('settings_natives')->where('id', $id)->update($values);
            }

            Compatibility::prepare($holder::class);
            DB::statement('update settings_holders set job_id = gen_random_uuid()');
            DB::statement('insert into configured_jobs (id, name) select job_id, name from settings_holders');
            Compatibility::install(
                model: $holder::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

            $updated = array_map(fn (int $id) => $holder->newQuery()->whereKey($id)->update($writes[$id]), array_keys($writes));
            $served = $rows('settings_holders');
            $native = $rows('settings_natives');
        } finally {
            DB::statement('drop view if exists settings_holders');
            DB::statement('drop table if exists settings_holders, settings_holders_retained, settings_holders_backfill, settings_natives, configured_jobs cascade');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type = 'settings_holders'; IF NOT EXISTS (SELECT FROM compatibility_backfills) THEN DROP TABLE compatibility_backfills; END IF; END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }

        $this->assertSame([1, 1], $updated);
        $this->assertSame([[['theme' => 'dark'], 'light'], [['font' => 'serif', 'theme' => 'light'], 'mono']], $native);
        $this->assertSame($native, $served);
    }

    #[Test]
    public function it_writes_a_json_path_into_a_legacy_only_column_and_serves_no_successor_row_without_a_retained_row(): void
    {
        DB::statement("create table settings_holders (id serial primary key, name text not null, preferences jsonb not null default '{}'::jsonb, notes jsonb, created_at timestamptz, updated_at timestamptz)");
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null)');
        DB::table('settings_holders')->insert(['name' => 'Oak', 'preferences' => '{"theme": "light"}']);
        $holder = $this->jsonHolder();
        $rows = fn (): array => DB::table('settings_holders')->orderBy('id')->get(['id', 'preferences', 'notes'])->map(fn (object $row) => [$row->id, json_decode((string) $row->preferences, true), json_decode((string) $row->notes, true)])->all();

        try {
            Compatibility::prepare($holder::class);
            DB::statement('update settings_holders set job_id = gen_random_uuid()');
            DB::statement('insert into configured_jobs (id, name) select job_id, name from settings_holders');
            Compatibility::install(
                model: $holder::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            DB::table('configured_jobs')->insert(['name' => 'Ash']);

            $before = $rows();
            $updated = $holder->newQuery()->whereKey([1, 10])->update(['preferences->theme' => 'dark', 'notes->seen' => true]);
            $after = $rows();
        } finally {
            DB::statement('drop view if exists settings_holders');
            DB::statement('drop table if exists settings_holders, settings_holders_retained, settings_holders_backfill, configured_jobs cascade');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type = 'settings_holders'; IF NOT EXISTS (SELECT FROM compatibility_backfills) THEN DROP TABLE compatibility_backfills; END IF; END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }

        $this->assertSame([[1, ['theme' => 'light'], null]], $before);
        $this->assertSame(1, $updated);
        $this->assertSame([[1, ['theme' => 'dark'], null]], $after);
    }

    #[Test]
    public function it_writes_a_whole_json_value_into_a_mapped_and_a_related_column_for_records_with_and_without_the_related_row_as_a_native_update_does(): void
    {
        DB::statement('create table settings_holders (id serial primary key, name text not null, settings jsonb, preferences jsonb, options jsonb, created_at timestamptz, updated_at timestamptz)');
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null, settings jsonb)');
        DB::statement('create table configured_sites (id uuid primary key default gen_random_uuid(), configured_job_id uuid not null unique references configured_jobs, options jsonb)');
        DB::table('settings_holders')->insert([
            ['name' => 'Oak', 'options' => '{"a": 1}'],
            ['name' => 'Pine', 'options' => null],
        ]);
        DB::statement('create table settings_natives as select * from settings_holders');
        $values = ['settings' => ['enabled' => true], 'options' => ['theme' => 'dark', 'sizes' => [1, 2]]];
        $rows = fn (string $table): array => DB::table($table)->orderBy('id')->get(['settings', 'options'])->map(fn (object $row) => [json_decode((string) $row->settings, true), json_decode((string) $row->options, true)])->all();
        $holder = $this->sitedJsonHolder();

        try {
            DB::table('settings_natives')->whereIn('id', [1, 2])->update($values);
            Compatibility::prepare($holder::class);
            DB::statement('update settings_holders set job_id = gen_random_uuid()');
            DB::statement('insert into configured_jobs (id, name, settings) select job_id, name, settings from settings_holders');
            DB::statement('insert into configured_sites (configured_job_id, options) select configured_jobs.id, settings_holders.options from settings_holders join configured_jobs on configured_jobs.id = settings_holders.job_id where settings_holders.options is not null');
            Compatibility::install(
                model: $holder::class,
                statementTimeout: CarbonInterval::seconds(30),
            );

            $updated = $holder->newQuery()->whereKey([1, 2])->update($values);
            $served = $rows('settings_holders');
            $native = $rows('settings_natives');
            $sites = DB::table('configured_sites')->count();
        } finally {
            DB::statement('drop view if exists settings_holders');
            DB::statement('drop table if exists settings_holders, settings_holders_retained, settings_holders_backfill, settings_natives, configured_sites, configured_jobs cascade');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type = 'settings_holders'; IF NOT EXISTS (SELECT FROM compatibility_backfills) THEN DROP TABLE compatibility_backfills; END IF; END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }

        $this->assertSame(2, $updated);
        $this->assertSame(array_fill(0, 2, [['enabled' => true], ['sizes' => [1, 2], 'theme' => 'dark']]), $native);
        $this->assertSame($native, $served);
        $this->assertSame(2, $sites);
    }

    #[Test]
    public function it_writes_a_whole_json_value_into_a_legacy_only_column_as_a_native_update_does(): void
    {
        DB::statement('create table settings_holders (id serial primary key, name text not null, preferences jsonb, notes json, created_at timestamptz, updated_at timestamptz)');
        DB::statement('create table configured_jobs (id uuid primary key default gen_random_uuid(), name text not null)');
        DB::table('settings_holders')->insert(['name' => 'Oak', 'preferences' => '{"theme": "light"}']);
        DB::statement('create table settings_natives as select * from settings_holders');
        $values = ['preferences' => ['theme' => 'dark'], 'notes' => ['seen', 'pinned']];
        $rows = fn (string $table): array => DB::table($table)->orderBy('id')->get(['id', 'preferences', 'notes'])->map(fn (object $row) => [$row->id, json_decode((string) $row->preferences, true), json_decode((string) $row->notes, true)])->all();
        $holder = $this->jsonHolder();

        try {
            DB::table('settings_natives')->whereIn('id', [1, 10])->update($values);
            Compatibility::prepare($holder::class);
            DB::statement('update settings_holders set job_id = gen_random_uuid()');
            DB::statement('insert into configured_jobs (id, name) select job_id, name from settings_holders');
            Compatibility::install(
                model: $holder::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            DB::table('configured_jobs')->insert(['name' => 'Ash']);

            $updated = $holder->newQuery()->whereKey([1, 10])->update($values);
            $served = $rows('settings_holders');
            $native = $rows('settings_natives');
        } finally {
            DB::statement('drop view if exists settings_holders');
            DB::statement('drop table if exists settings_holders, settings_holders_retained, settings_holders_backfill, settings_natives, configured_jobs cascade');
            DB::unprepared("DO \$\$ BEGIN IF to_regclass('compatibility_backfills') IS NOT NULL THEN DELETE FROM compatibility_backfills WHERE legacy_type = 'settings_holders'; IF NOT EXISTS (SELECT FROM compatibility_backfills) THEN DROP TABLE compatibility_backfills; END IF; END IF; END \$\$");
            LegacyBuilder::forgetViews();
        }

        $this->assertSame(1, $updated);
        $this->assertSame([[1, ['theme' => 'dark'], ['seen', 'pinned']]], $native);
        $this->assertSame($native, $served);
    }

    #[Test]
    public function it_refuses_a_json_path_write_into_an_attribute_whose_conversions_read_the_whole_value(): void
    {
        $before = DB::table('jobs')->orderBy('id')->get()->all();

        $refusal = $this->refusal(fn () => Listing::query()->whereKey(1)->update(['price->amount' => 5]));

        $this->assertSame(Listing::class.' cannot run a JSON path write of $price through its compatibility map. Why: Its conversions read the whole stored value, so a path inside the legacy value has no one stored path. Instead: Write each record through Listing, or write the successor models directly.', $refusal->getMessage());
        $this->assertEquals($before, DB::table('jobs')->orderBy('id')->get()->all());
    }

    #[Test]
    public function it_stores_the_backing_value_of_an_enum_written_to_a_legacy_only_column(): void
    {
        $updated = [
            Listing::query()->whereKey(1)->update(['mls_number' => ListingStatus::Sold]),
            Listing::query()->whereKey(3)->toBase()->update(['mls_number' => ListingStatus::Active]),
            Listing::query()->findOrFail(1)->increment('views', 1, ['mls_number' => ListingStatus::Draft]),
        ];

        $this->assertSame([1, 1, 1], $updated);
        $this->assertSame([['Draft', 6], ['Active', 5]], Listing::query()->whereKey([1, 3])->orderBy('id')->get(['mls_number', 'views'])->map(fn (Listing $listing) => [$listing->mls_number, $listing->getAttribute('views')])->all());
    }

    #[Test]
    public function it_refuses_an_increment_through_a_conversion_that_is_not_linear(): void
    {
        $mapped = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
                        name: 'status',
                        definition: fn (Attribute $attribute) => $attribute->from('lifecycle_status')->mapping(['drafting' => 'Draft', 'live' => 'Active', 'closed' => 'Sold']),
                    ));
            }
        };

        $refused = $this->refusal(fn () => $mapped->newQuery()->increment('status'));

        $this->assertSame($mapped::class.' cannot run an increment of $status through its compatibility map. Why: Its conversions are not linear, so an amount added to it has no one stored amount. Instead: Write each record through '.class_basename($mapped::class).', or write the successor models directly.', $refused->getMessage());
        $this->assertSame(['live', 'drafting', 'closed'], DB::table('jobs')->join(
            table: 'listings_retained',
            first: 'listings_retained.job_id',
            operator: '=',
            second: 'jobs.id',
        )->orderBy('listings_retained.id')->pluck('jobs.lifecycle_status')->all());
    }

    #[Test]
    public function it_refuses_a_raw_expression_for_a_converted_attribute_and_a_write_of_a_read_only_attribute(): void
    {
        $raw = $this->refusal(fn () => Listing::query()->update(['price' => DB::raw('price + 1')]));
        $readOnly = $this->refusal(fn () => Listing::query()->update(['company_id' => ListingTables::COMPANY]));

        $this->assertSame(Listing::class.'::$price cannot take a raw expression. Why: Its conversions read stored values, and SQL written for the legacy value does not pass through them. Instead: Write the computed value, or write the stored column through the successor model.', $raw->getMessage());
        $this->assertSame(Listing::class.' records cannot change $company_id. Why: A job never changes company. Instead: Leave company_id unchanged in writes through Listing.', $readOnly->getMessage());
        $this->assertSame([125050, 9999], DB::table('jobs')->join(
            table: 'listings_retained',
            first: 'listings_retained.job_id',
            operator: '=',
            second: 'jobs.id',
        )->whereNotNull('jobs.price_cents')->orderBy('listings_retained.id')->pluck('jobs.price_cents')->all());
    }

    #[DataProvider('successorKeyWrites')]
    #[Test]
    public function it_refuses_a_write_of_the_successor_key_as_a_write_of_an_attribute_the_legacy_model_cannot_change(Closure $write, string $refused, string $because): void
    {
        $before = DB::table('listings_retained')->orderBy('id')->pluck('job_id')->all();

        $refusal = $this->refusal(fn () => $write(DB::table('jobs')->where(
            column: 'name',
            operator: '=',
            value: 'Elm Cottage',
        )->value('id')));

        $this->assertSame("{$refused} cannot change \$job_id. Why: {$because} Instead: Leave job_id unchanged in writes through Listing.", $refusal->getMessage());
        $this->assertSame($before, DB::table('listings_retained')->orderBy('id')->pluck('job_id')->all());
    }

    /**
     * @return array<string, array{Closure(mixed): mixed, string, string}>
     */
    public static function successorKeyWrites(): array
    {
        return [
            'a record write' => [function (mixed $job): void {
                $listing = Listing::query()->findOrFail(1);
                $listing->job_id = $job;
                $listing->save();
            }, Listing::class.' record 1', 'The successor key binds the record to its Job, and a write that rebinds it would serve another root under the same legacy identity.'],
            'a mass write' => [fn (mixed $job) => Listing::query()->whereKey(1)->update(['job_id' => $job]), Listing::class.' records', 'The successor key binds each record to its Job, and a write that changes it would serve another root under the same legacy identity.'],
            'an increment' => [fn () => Listing::query()->whereKey(1)->increment('job_id'), Listing::class.' records', 'The successor key binds each record to its Job, and a write that changes it would serve another root under the same legacy identity.'],
        ];
    }

    #[Test]
    public function it_refuses_a_mass_write_of_an_attribute_that_runs_transitions(): void
    {
        $refused = $this->refusal(fn () => Listing::query()->where('status', ListingStatus::Active)->update(['title' => 'Oak Manor', 'status' => ListingStatus::Sold]));

        $this->assertSame(Listing::class.'::$status cannot take a mass write. Why: It is declared transitions(), so each record\'s change runs its successor\'s state-machine trigger, and a set-based statement cannot run a trigger per row. Instead: Write the records one at a time through Listing.', $refused->getMessage());
        $this->assertSame([['Oak House', 'live'], ['Pine Flat', 'drafting'], ['Elm Cottage', 'closed']], DB::table('jobs')->join(
            table: 'listings_retained',
            first: 'listings_retained.job_id',
            operator: '=',
            second: 'jobs.id',
        )->orderBy('listings_retained.id')->get(['jobs.name', 'jobs.lifecycle_status'])->map(fn (object $job) => array_values((array) $job))->all());
    }

    #[Test]
    public function it_refuses_a_raw_expression_for_a_successor_attribute_and_reads_a_legacy_only_one_against_the_row_before_the_write(): void
    {
        $refused = $this->refusal(fn () => Listing::query()->whereKey(3)->update(['title' => DB::raw("'Listing ' || id")]));
        $updated = Listing::query()->whereKey(1)->update(['title' => 'Oak Manor', 'mls_number' => DB::raw("title || ' ' || id")]);

        $this->assertSame(Listing::class.'::$title cannot take a raw expression. Why: It is stored in jobs, and SQL written for the legacy table would read the columns of jobs instead. Instead: Write the computed value, or write the stored column through the successor model.', $refused->getMessage());
        $this->assertSame('Elm Cottage', Job::query()->whereKey(ListingTables::jobOf(3))->value('name'));
        $this->assertSame([1, 'Oak Manor', 'Oak House 1'], [$updated, Listing::query()->findOrFail(1)->title, Listing::query()->findOrFail(1)->mls_number]);
    }

    #[Test]
    public function it_skips_a_record_a_concurrent_write_moved_out_of_the_filter_while_the_write_waited_for_its_lock(): void
    {
        $locked = $this->lockConcurrently("update jobs set name = 'Changed' where id = (select job_id from listings_retained where id = 1)");

        $updated = Listing::query()->where('title', 'Oak House')->update(['title' => 'Mass']);

        $this->assertSame(["locked\n", ''], [$locked, $this->concurrentFailure()]);
        $this->assertSame([0, 'Changed'], [$updated, Listing::query()->findOrFail(1)->title]);
    }

    #[Test]
    public function it_skips_deleting_a_record_a_concurrent_write_moved_out_of_the_filter_while_the_delete_waited_for_its_lock(): void
    {
        $locked = $this->lockConcurrently("update jobs set name = 'Changed' where id = (select job_id from listings_retained where id = 1)");

        $deleted = Listing::query()->where('title', 'Oak House')->forceDelete();

        $this->assertSame(["locked\n", ''], [$locked, $this->concurrentFailure()]);
        $this->assertSame([0, 'Changed'], [$deleted, Listing::query()->findOrFail(1)->title]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function concurrentMovesOutOfAnotherTable(): iterable
    {
        yield 'a related row' => ["update places set city = 'Portland' where street = '1 Oak St'", 'city', 'Seattle'];
        yield 'a retained row' => ["update listings_retained set mls_number = 'MLS-1B' where mls_number = 'MLS-1'", 'mls_number', 'MLS-1'];
    }

    #[DataProvider('concurrentMovesOutOfAnotherTable')]
    #[Test]
    public function it_skips_a_record_a_concurrent_write_moved_out_of_a_filter_on_another_table_while_the_write_waited(string $concurrent, string $column, string $value): void
    {
        $locked = $this->lockConcurrently($concurrent);

        $updated = Listing::query()->where($column, $value)->update(['title' => 'Mass', 'street' => '9 Oak St']);

        $this->assertSame(["locked\n", ''], [$locked, $this->concurrentFailure()]);
        $this->assertSame([0, 'Oak House', '1 Oak St'], [$updated, Listing::query()->findOrFail(1)->title, Listing::query()->findOrFail(1)->street]);
    }

    #[Test]
    public function it_writes_only_the_records_a_limited_query_chooses(): void
    {
        $updated = Listing::query()->orderBy('price')->limit(1)->update(['title' => 'Cheapest', 'mls_number' => 'MLS-C']); // @phpstan-ignore staticMethod.dynamicCall

        $this->assertSame(1, $updated);
        $this->assertSame([['Oak House', 'MLS-1'], ['Cheapest', 'MLS-C']], Listing::query()->orderBy('id')->get(['title', 'mls_number'])->map(fn (Listing $listing) => [$listing->title, $listing->mls_number])->all());
    }

    #[Test]
    public function it_refuses_to_create_a_missing_dependent_whose_key_has_no_database_default(): void
    {
        DB::statement('alter table places alter column id drop default');

        $refused = $this->refusal(fn () => Listing::withTrashed()->whereKey([1, 2])->update(['title' => 'Renamed', 'street' => '2 Pine St']));
        Listing::query()->whereKey(1)->update(['street' => '2 Oak St']);

        $this->assertSame(Listing::class.' cannot write $street in one statement to records that have no places row. Why: A set-based write creates the missing places rows in SQL, and places.id has no database default to key them. Instead: Write the records one at a time through Listing, or give places.id a database default.', $refused->getMessage());
        $this->assertSame(['Oak House', 'Pine Flat'], Job::withTrashed()->whereIn(
            column: 'id',
            values: DB::table('listings_retained')->whereIn(
                column: 'id',
                values: [1, 2],
            )->select('job_id'),
        )->orderBy('name')->pluck('name')->all());
        $this->assertSame(['2 Oak St', '3 Elm St'], DB::table('places')->orderBy('street')->pluck('street')->all());
    }

    #[Test]
    public function it_binds_no_matched_key_into_a_statement(): void
    {
        DB::table('jobs')->insert(array_fill(0, 100, ['name' => 'Bulk', 'company_id' => ListingTables::COMPANY, 'price_cents' => 100]));
        self::serveNativeJobs();
        $keys = DB::table('jobs')->where('name', 'Bulk')->pluck('id')->all();

        [$updated] = $this->statements(fn () => Listing::query()->where('title', 'Bulk')->update(['price' => '2.00', 'street' => '5 Elm St', 'latitude' => 2.5, 'mls_number' => 'MLS-B']));
        $bound = (string) json_encode(array_column(DB::getQueryLog(), 'bindings'));

        $this->assertSame(100, $updated);
        $this->assertSame([], array_values(array_filter($keys, fn (mixed $key) => str_contains($bound, (string) $key))));
    }

    #[Test]
    public function it_deletes_the_primary_rows_hard_in_the_same_number_of_statements_for_one_matching_row_and_for_a_hundred(): void
    {
        $oak = Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail();
        DB::table('jobs')->insert(array_fill(0, 100, ['name' => 'Bulk', 'company_id' => ListingTables::COMPANY, 'price_cents' => 100]));
        self::serveNativeJobs();
        Listing::query()->where('price', '>', 1000)->get();

        $one = $this->statements(fn () => Listing::query()->where('price', '>', 1000)->forceDelete());
        $hundred = $this->statements(fn () => Listing::query()->where('title', 'Bulk')->forceDelete());

        $this->assertSame([1, 100], [$one[0], $hundred[0]]);
        $this->assertSame($one[1], $hundred[1]);
        $this->assertSame([0, 0, 0], [Job::withTrashed()->whereKey($oak->id)->count(), DB::table('places')->where('job_id', $oak->id)->count(), DB::table('listings_retained')->where('job_id', $oak->id)->count()]);
        $this->assertSame(2, Job::withTrashed()->count());
    }

    private function createCompanies(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        Schema::create('company_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->foreignUuid('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->string('tagline')->nullable();
        });
    }

    /**
     * @return array{mixed, int}
     */
    private function statements(Closure $write): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $write();
        } finally {
            DB::disableQueryLog();
        }

        return [$result, count(array_filter(DB::getQueryLog(), fn (array $query) => ! str_contains((string) $query['query'], 'pg_class')))];
    }

    private function lockConcurrently(string $sql): string
    {
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $concurrent = '$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).');'
            .' $pdo->beginTransaction(); $pdo->exec('.var_export($sql, true).'); echo "locked\n"; fflush(STDOUT); sleep(2); $pdo->commit();';
        $process = proc_open([PHP_BINARY, '-r', $concurrent], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            $this->fail('The concurrent writer did not start.');
        }

        $this->concurrent = [$process, $pipes];

        return (string) fgets($pipes[1]);
    }

    private function concurrentFailure(): string
    {
        if ($this->concurrent === null) {
            $this->fail('No concurrent writer is running.');
        }

        [$process, $pipes] = $this->concurrent;
        $failure = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $this->concurrent = null;

        return $failure;
    }

    private function refusal(Closure $write): CompatibilityWriteRefused
    {
        try {
            $write();
        } catch (CompatibilityWriteRefused $refused) {
            return $refused;
        }

        $this->fail('The write was not refused.');
    }

    private function jsonHolder(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'settings_holders';

            protected $guarded = [];

            public static function compatibility(Compatibility $holder): Compatibility
            {
                return $holder
                    ->retainedIn('settings_holders_retained')
                    ->servedFrom(root: ConfiguredJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'name',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        ));
            }
        };
    }

    private function sitedJsonHolder(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'settings_holders';

            protected $guarded = [];

            public static function compatibility(Compatibility $holder): Compatibility
            {
                return $holder
                    ->retainedIn('settings_holders_retained')
                    ->servedFrom(root: ConfiguredJob::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'name',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'settings',
                            definition: fn (Attribute $attribute) => $attribute->from('settings'),
                        )
                        ->related(
                            relation: fn (ConfiguredJob $job) => $job->site(),
                            definition: fn (Successor $site) => $site
                                ->attribute('options', fn (Attribute $attribute) => $attribute->from('options')),
                        ));
            }
        };
    }

    private function sited(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $timestamps = false;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->rootPlace(),
                            definition: fn (Successor $place) => $place
                                ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                                ->related(fn (Place $place) => $place->hasOne(JobSite::class, 'job_id', 'job_id'), fn (Successor $site) => $site
                                    ->attribute('latitude', fn (Attribute $attribute) => $attribute->from('latitude'))),
                        ));
            }
        };
    }

    private static function serveNativeJobs(): void
    {
        DB::statement('insert into listings_retained (job_id) select jobs.id from jobs where not exists (select from listings_retained where listings_retained.job_id = jobs.id)');
    }
}
