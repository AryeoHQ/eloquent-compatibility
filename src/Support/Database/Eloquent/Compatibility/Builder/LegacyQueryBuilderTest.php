<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\TestCase;

#[CoversClass(LegacyQueryBuilder::class)]
final class LegacyQueryBuilderTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
    }

    protected function tearDown(): void
    {
        ListingTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_translates_an_update_through_to_base(): void
    {
        $this->install();
        $at = Date::parse('2026-06-07 08:09:10');

        $updated = Listing::query()->whereKey(1)->toBase()->update(['updated_at' => $at]);

        $this->assertSame(1, $updated);
        $this->assertSame('2026-06-07 08:09:10', Job::query()->whereKey(ListingTables::jobOf(1))->firstOrFail()->updated_at?->toDateTimeString());
        $this->assertSame('2026-01-05 08:00:00', Job::query()->whereKey(ListingTables::jobOf(3))->firstOrFail()->updated_at?->toDateTimeString());
    }

    #[Test]
    public function it_translates_a_write_through_the_query_without_scopes(): void
    {
        $this->install();

        $updated = Listing::query()->getQuery()->where('id', 2)->update(['title' => 'Pine Lodge']);
        $deleted = Listing::query()->toBase()->delete(3);

        $this->assertSame([1, 1], [$updated, $deleted]);
        $this->assertSame('Pine Lodge', Job::withTrashed()->whereKey(ListingTables::jobOf(2))->value('name'));
        $this->assertSame([1, 2], DB::table('listings_retained')->whereIn(column: 'job_id', values: Job::withTrashed()->select('id'))->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function it_translates_a_write_that_reads_the_legacy_table_by_an_alias(): void
    {
        $this->install();

        $updated = Listing::query()->withoutGlobalScopes()->toBase()->from('listings as legacy')->where('legacy.id', 1)->update(['title' => 'Renamed']);
        $aliased = Listing::query()->withoutGlobalScopes();
        $aliased->getQuery()->from('listings AS legacy');
        $eloquent = $aliased->where('legacy.id', 3)->update(['title' => 'Elm Lodge']);
        $incremented = Listing::query()->getQuery()->from('listings as legacy')->where('legacy.id', 3)->incrementEach(['price' => 1]);
        $deleted = Listing::query()->getQuery()->from('listings as legacy')->delete(2);

        $this->assertSame([1, 1, 1, 1], [$updated, $eloquent, $incremented, $deleted]);
        $this->assertSame(['Renamed', 'Elm Lodge', 10099], [Job::query()->whereKey(ListingTables::jobOf(1))->value('name'), Job::query()->whereKey(ListingTables::jobOf(3))->value('name'), Job::query()->whereKey(ListingTables::jobOf(3))->value('price_cents')]);
        $this->assertFalse(Job::withTrashed()->whereKey(ListingTables::jobOf(2))->exists());
    }

    #[Test]
    public function it_inserts_each_row_through_the_record_writer(): void
    {
        $this->install();
        Event::fake(['eloquent.created: '.Job::class]);

        Listing::query()->toBase()->insert([
            ['title' => 'Birch House', 'company_id' => ListingTables::COMPANY, 'price' => '10.00', 'street' => '9 Birch St', 'mls_number' => 'MLS-9'],
            ['title' => 'Cedar House', 'company_id' => ListingTables::COMPANY, 'price' => null, 'street' => null, 'mls_number' => null],
        ]);
        $id = Listing::query()->toBase()->insertGetId(['title' => 'Fir House', 'company_id' => ListingTables::COMPANY]);

        Event::assertDispatchedTimes('eloquent.created: '.Job::class, 3);
        $this->assertSame(6, $id);
        $this->assertSame([[4, 'Birch House', 1000], [5, 'Cedar House', null], [6, 'Fir House', null]], DB::table('jobs')->join(table: 'listings_retained', first: 'listings_retained.job_id', operator: '=', second: 'jobs.id')->where(column: 'listings_retained.id', operator: '>', value: 3)->orderBy('listings_retained.id')->get(['listings_retained.id', 'jobs.name', 'jobs.price_cents'])->map(fn (object $job) => array_values((array) $job))->all());
        $this->assertSame(['9 Birch St', null, null], Listing::query()->where('id', '>', 3)->orderBy('id')->pluck('street')->all());
        $this->assertSame(['MLS-9', null, null], Listing::query()->where('id', '>', 3)->orderBy('id')->pluck('mls_number')->all());
    }

    #[Test]
    public function it_ignores_a_row_whose_unique_identifier_is_taken(): void
    {
        $this->install();
        $uuid = (string) Listing::query()->whereKey(1)->value('uuid');

        $inserted = Listing::query()->toBase()->insertOrIgnore([
            ['uuid' => $uuid, 'title' => 'Taken', 'company_id' => ListingTables::COMPANY],
            ['title' => 'Fresh', 'company_id' => ListingTables::COMPANY],
        ]);

        $this->assertSame(1, $inserted);
        $this->assertSame(['Oak House', 'Fresh'], Listing::query()->where('title', '!=', 'Elm Cottage')->orderBy('id')->pluck('title')->all());
        $this->assertSame(0, DB::table('jobs')->where('name', 'like', 'Taken%')->count());
    }

    #[RequiresMethod(Model::class, 'saveOrIgnore')]
    #[Test]
    public function it_ignores_a_record_save_whose_unique_identifier_is_taken(): void
    {
        $this->install();
        $uuid = (string) Listing::query()->whereKey(1)->value('uuid');
        $taken = new Listing(['uuid' => $uuid, 'title' => 'Taken', 'company_id' => ListingTables::COMPANY]);
        $fresh = new Listing(['title' => 'Fresh', 'company_id' => ListingTables::COMPANY, 'street' => '4 Yew St']);

        $this->assertFalse($taken->saveOrIgnore());
        $this->assertTrue($fresh->saveOrIgnore());
        $this->assertSame(['Oak House', 'Fresh'], Listing::query()->where('title', '!=', 'Elm Cottage')->orderBy('id')->pluck('title')->all());
        $this->assertSame(['4 Yew St', 'Fresh'], [$fresh->street, Listing::query()->findOrFail($fresh->id)->title]);
        $this->assertSame(0, DB::table('jobs')->where('name', 'like', 'Taken%')->count());
    }

    #[Test]
    public function it_returns_the_native_empty_result_for_an_empty_insert_before_any_statement(): void
    {
        $this->install();
        $query = fn () => Listing::query()->toBase();
        $query();
        DB::enableQueryLog();

        $results = [$query()->insert([]), $query()->insertOrIgnore([])];

        $this->assertSame([true, 0], $results);
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame(3, DB::table('jobs')->count());
    }

    #[RequiresMethod(QueryBuilder::class, 'insertOrIgnoreReturning')]
    #[Test]
    public function it_returns_the_native_empty_result_for_an_empty_insert_or_ignore_returning_before_any_statement(): void
    {
        $this->install();
        $query = Listing::query()->toBase();
        DB::enableQueryLog();

        $results = $query->insertOrIgnoreReturning([])->all();

        $this->assertSame([], $results);
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame(3, DB::table('jobs')->count());
    }

    #[Test]
    public function it_returns_a_string_key_from_insert_get_id(): void
    {
        $this->install();
        $keyed = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public $incrementing = false;

            protected $table = 'listings';

            protected $primaryKey = 'uuid';

            protected $keyType = 'string';

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
                        ));
            }
        };

        $key = $keyed->newQuery()->toBase()->insertGetId(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY]);

        $this->assertSame(DB::table('listings_retained')->where(column: 'job_id', operator: '=', value: Job::query()->where(column: 'name', operator: '=', value: 'Birch House')->value('id'))->value('uuid'), $key);
    }

    #[Test]
    public function it_returns_the_column_an_insert_get_id_names_as_the_native_insert_does(): void
    {
        $native = [
            Listing::query()->toBase()->insertGetId(['title' => 'Ash House', 'company_id' => ListingTables::COMPANY], 'uuid'),
            Listing::query()->toBase()->insertGetId(['title' => 'Bay House', 'company_id' => ListingTables::COMPANY], 'id'),
            Listing::query()->toBase()->insertGetId(['title' => 'Cove House', 'company_id' => ListingTables::COMPANY], 'title'),
        ];
        $this->install();

        $served = [
            Listing::query()->toBase()->insertGetId(['title' => 'Dune House', 'company_id' => ListingTables::COMPANY], 'uuid'),
            Listing::query()->insertGetId(['title' => 'Elm House', 'company_id' => ListingTables::COMPANY], 'uuid'),
            Listing::query()->toBase()->insertGetId(['title' => 'Fen House', 'company_id' => ListingTables::COMPANY], 'id'),
            Listing::query()->toBase()->insertGetId(['title' => 'Glen House', 'company_id' => ListingTables::COMPANY], 'title'),
        ];

        $this->assertSame([Listing::query()->where('title', 'Ash House')->value('uuid'), 5, 'Cove House'], $native);
        $this->assertSame([Listing::query()->where('title', 'Dune House')->value('uuid'), Listing::query()->where('title', 'Elm House')->value('uuid'), 9, 'Glen House'], $served);
        $this->assertTrue(is_string($served[0]) && is_string($served[1]));
    }

    #[Test]
    public function it_inserts_or_updates_through_update_or_insert(): void
    {
        $this->install();

        Listing::query()->toBase()->updateOrInsert(['title' => 'Oak House'], ['mls_number' => 'MLS-1B']);
        Listing::query()->toBase()->updateOrInsert(['title' => 'Ash House'], ['company_id' => ListingTables::COMPANY, 'mls_number' => 'MLS-A']);

        $this->assertSame([['Oak House', 'MLS-1B'], ['Elm Cottage', 'MLS-3'], ['Ash House', 'MLS-A']], Listing::query()->orderBy('id')->get(['title', 'mls_number'])->map(fn (Listing $listing) => [$listing->title, $listing->mls_number])->all());
    }

    #[Test]
    public function it_refuses_the_writes_it_cannot_translate(): void
    {
        $this->install();
        $query = fn () => Listing::query()->toBase();

        $refusals = [
            $this->refusal(fn () => $query()->upsert([['id' => 1, 'title' => 'X']], ['id'], ['title'])),
            $this->refusal(fn () => $query()->insertUsing(['title'], DB::table('jobs')->select('name'))),
            $this->refusal(fn () => $query()->insertOrIgnoreUsing(['title'], DB::table('jobs')->select('name'))),
            $this->refusal(fn () => $query()->join(
                table: 'jobs',
                first: 'jobs.id',
                operator: '=',
                second: 'listings.job_id',
            )->updateFrom(['title' => 'X'])),
            $this->refusal(fn () => $query()->truncate()),
            $this->refusal(fn () => $query()->insertOrIgnoreReturning(['title' => 'X'], ['id'], ['uuid'])),
        ];

        $this->assertSame(
            ['upsert()', 'insertUsing()', 'insertOrIgnoreUsing()', 'updateFrom()', 'truncate()', 'insertOrIgnoreReturning() with unique columns'],
            array_map(fn (CompatibilityWriteRefused $refused) => substr($refused->refused, strlen(Listing::class.' cannot run '), -strlen(' through its compatibility map')), $refusals),
        );
        $this->assertSame(3, DB::table('jobs')->count());
        $this->assertSame(['Oak House', 'Pine Flat', 'Elm Cottage'], DB::table('jobs')->orderBy(ListingTables::legacyId())->pluck('name')->all());
    }

    #[Test]
    public function it_writes_the_legacy_table_natively_before_install(): void
    {
        Listing::query()->toBase()->insert(['title' => 'Birch House', 'company_id' => ListingTables::COMPANY]);
        Listing::query()->toBase()->where('title', 'Oak House')->update(['title' => 'Oak Manor']);
        Listing::query()->toBase()->upsert([['id' => 3, 'title' => 'Elm Lodge', 'company_id' => ListingTables::COMPANY]], ['id'], ['title']);
        Listing::withTrashed()->toBase()->where('id', 2)->delete();

        $this->assertSame(['Oak Manor', 'Elm Lodge', 'Birch House'], DB::table('listings')->orderBy('id')->pluck('title')->all());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function install(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
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
}
