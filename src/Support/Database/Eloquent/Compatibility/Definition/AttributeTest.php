<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\DividedBy;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\Fixed;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\ReadAs;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\TestCase;

#[CoversClass(Attribute::class)]
final class AttributeTest extends TestCase
{
    #[Test]
    public function it_reads_a_plain_column_of_its_successor_and_filters_and_orders_it_unchanged(): void
    {
        $attribute = (new Attribute('listings', 'title'))->from('name');

        $this->assertSame(['name', '"jobs"."name"', 'Oak Lane', ['like', 'Oak%'], 'desc'], [
            $attribute->column(),
            $attribute->read('jobs'),
            $attribute->write('Oak Lane'),
            $attribute->filter('like', 'Oak%'),
            $attribute->order('DESC'),
        ]);
        $this->assertTrue($attribute->isWritable());
    }

    #[Test]
    public function it_applies_its_conversions_in_order_when_it_reads_and_in_reverse_when_it_writes(): void
    {
        $attribute = (new Attribute('listings', 'price'))->from('price_cents')->dividedBy(100)->asType('numeric(10,2)');

        $this->assertSame('CAST((CAST("jobs"."price_cents" AS numeric) / 100) AS numeric(10,2))', $attribute->read('jobs'));
        $this->assertSame(450055, $attribute->write('4500.55'));
        $this->assertSame('4500.55', DB::scalar('select '.str_replace('"jobs"."price_cents"', 'CAST(? AS integer)', $attribute->read('jobs')).'::text', [$attribute->write('4500.55')]));
    }

    #[Test]
    public function it_translates_a_filter_and_an_order_through_every_conversion_from_the_last_to_the_first(): void
    {
        $attribute = (new Attribute('job_sites', 'area'))->from('area_m2')->multipliedBy(-2)->dividedBy(100);

        $this->assertSame(['<', -150], $attribute->filter('>', 3));
        $this->assertSame('desc', $attribute->order('asc'));
    }

    #[Test]
    public function it_cannot_filter_or_order_when_one_conversion_cannot(): void
    {
        $attribute = (new Attribute('listings', 'status'))->from('lifecycle_status')->mapping(['live' => ListingStatus::Active])->asType('text');

        $this->assertSame([null, null, null], [$attribute->filter('=', 'Active'), $attribute->filter('>', 'Active'), $attribute->order('asc')]);
    }

    #[Test]
    public function it_reads_a_fixed_value_from_no_column_and_cannot_be_written(): void
    {
        $attribute = (new Attribute('videos', 'mime_type'))->fixed(null)->resolve();

        $this->assertEquals([new Fixed(null)], $attribute->conversions());
        $this->assertSame([null, 'NULL', false], [$attribute->column(), $attribute->read('links'), $attribute->isWritable()]);
    }

    #[Test]
    public function it_reads_raw_sql_and_writes_through_its_inverse_to_its_column(): void
    {
        $attribute = (new Attribute('listings', 'title'))
            ->from('name')
            ->readAs('upper("jobs"."name")')
            ->writeTo(fn (mixed $value) => strtolower((string) $value))
            ->resolve();

        $this->assertInstanceOf(ReadAs::class, $attribute->conversions()[0]);
        $this->assertSame(['(upper("jobs"."name"))', 'oak lane', true], [$attribute->read('jobs'), $attribute->write('OAK LANE'), $attribute->isWritable()]);
    }

    #[Test]
    public function it_carries_a_read_only_reason_and_is_then_not_writable(): void
    {
        $attribute = (new Attribute('listings', 'company_id'))->from('company_id')->readOnly(because: 'A job never changes company.');

        $this->assertSame(['A job never changes company.', false], [$attribute->readOnlyBecause(), $attribute->isWritable()]);
    }

    #[Test]
    public function it_records_that_the_state_slice_runs_its_transitions(): void
    {
        $this->assertSame([false, true], [
            (new Attribute('listings', 'status'))->from('lifecycle_status')->runsTransitions(),
            (new Attribute('listings', 'status'))->from('lifecycle_status')->transitions()->runsTransitions(),
        ]);
    }

    #[Test]
    public function it_refuses_transitions_when_the_state_machine_package_is_absent(): void
    {
        $resolve = <<<'PHP'
            $loader = require $argv[1];
            $loader->addClassMap([Support\Database\Eloquent\StateMachines\Contracts\StateMachineable::class => '/dev/null']);

            try {
                (new Support\Database\Eloquent\Compatibility\Definition\Attribute('listings', 'status'))->from('lifecycle_status')->transitions()->resolve();
                echo 'resolved';
            } catch (Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap $invalid) {
                echo $invalid->getMessage();
            }
            PHP;

        $result = Process::run([PHP_BINARY, '-r', $resolve, dirname(__DIR__, 6).'/vendor/autoload.php']);

        $this->assertSame('', $result->errorOutput());
        $this->assertStringEndsWith("attribute 'status' is declared transitions(), which runs a state-machine trigger, and aryeo/eloquent-state-machines is not installed.", $result->output());
        $this->assertTrue((new Attribute('listings', 'status'))->from('lifecycle_status')->transitions()->resolve()->runsTransitions());
    }

    #[Test]
    public function it_serves_an_external_fact_from_no_sql(): void
    {
        $attribute = (new Attribute('videos', 'mime_type'))->external(read: fn (mixed $files) => $files, write: fn (mixed $file, mixed $value) => null)->resolve();

        $this->assertSame([true, false, null, null], [$attribute->isExternal(), $attribute->isWritable(), $attribute->filter('=', 'video/mp4'), $attribute->order('asc')]);

        $this->expectExceptionObject(new LogicException("Attribute 'mime_type' is external, so no SQL reads it."));

        $attribute->read('files');
    }

    #[Test]
    public function it_keeps_the_conversions_it_was_given(): void
    {
        $this->assertEquals([new DividedBy(100)], (new Attribute('listings', 'price'))->from('price_cents')->dividedBy(100)->conversions());
    }

    /**
     * @return iterable<string, array{Closure(Attribute): mixed, string}>
     */
    public static function invalidDeclarations(): iterable
    {
        yield 'two columns' => [fn (Attribute $attribute) => $attribute->from('name')->from('title'), "reads from both 'name' and 'title'"];
        yield 'another table' => [fn (Attribute $attribute) => $attribute->from('places.street'), "reads from 'places.street', which names no column of its successor's own table; reach another table through related()"];
        yield 'no column name' => [fn (Attribute $attribute) => $attribute->from(''), "reads from '', which names no column of its successor's own table; reach another table through related()"];
        yield 'no read-only reason' => [fn (Attribute $attribute) => $attribute->from('name')->readOnly(' '), 'is read-only without a reason; readOnly() takes the reason a write is refused'];
        yield 'divided by zero' => [fn (Attribute $attribute) => $attribute->from('price_cents')->dividedBy(0), 'is divided by 0'];
        yield 'multiplied by zero' => [fn (Attribute $attribute) => $attribute->from('price_cents')->multipliedBy(0.0), 'is multiplied by 0, so no write can be inverted'];
        yield 'empty mapping' => [fn (Attribute $attribute) => $attribute->from('lifecycle_status')->mapping([]), 'maps no values'];
        yield 'mapping without an inverse' => [fn (Attribute $attribute) => $attribute->from('lifecycle_status')->mapping(['live' => 'Active', 'paused' => ListingStatus::Active]), "maps more than one stored value to 'Active', so a write of it has no one stored value"];
        yield 'not a type' => [fn (Attribute $attribute) => $attribute->from('price')->asType('numeric; drop table jobs'), "reads as type 'numeric; drop table jobs', which is not a type name"];
        yield 'unknown time zone' => [fn (Attribute $attribute) => $attribute->from('starts_at')->inTimeZone('Mars/Olympus_Mons'), "reads in the time zone 'Mars/Olympus_Mons', which PHP does not know"];
        yield 'fixed twice' => [fn (Attribute $attribute) => $attribute->fixed(null)->fixed('x'), 'is fixed twice'];
        yield 'no sql' => [fn (Attribute $attribute) => $attribute->readAs(''), 'reads as no SQL'];
        yield 'sql twice' => [fn (Attribute $attribute) => $attribute->readAs('1')->readAs('2'), 'reads as raw SQL twice'];
        yield 'fixed with a column' => [fn (Attribute $attribute) => $attribute->from('url')->fixed(null)->resolve(), 'is fixed(), so it reads no column and takes no other conversion'];
        yield 'fixed with a conversion' => [fn (Attribute $attribute) => $attribute->fixed(1)->dividedBy(2)->resolve(), 'is fixed(), so it reads no column and takes no other conversion'];
        yield 'raw sql with a conversion' => [fn (Attribute $attribute) => $attribute->from('name')->readAs('upper(name)')->asType('text')->readOnly('Derived.')->resolve(), 'reads as raw SQL with readAs(), so it takes no other conversion'];
        yield 'raw sql that cannot be written' => [fn (Attribute $attribute) => $attribute->from('name')->readAs('upper(name)')->resolve(), 'reads as raw SQL with readAs(), and says neither writeTo() nor readOnly()'];
        yield 'an inverse of nothing' => [fn (Attribute $attribute) => $attribute->from('name')->writeTo(fn (mixed $value) => $value)->resolve(), 'says writeTo(), which inverts readAs(), without readAs()'];
        yield 'an inverse without a column' => [fn (Attribute $attribute) => $attribute->readAs("'x'")->writeTo(fn (mixed $value) => $value)->resolve(), 'says writeTo() without from(), so its inverse has no column to store'];
        yield 'no column' => [fn (Attribute $attribute) => $attribute->dividedBy(100)->resolve(), 'names no column; give it from(), fixed(), readAs() or external()'];
    }

    /**
     * @param  Closure(Attribute): mixed  $declare
     */
    #[DataProvider('invalidDeclarations')]
    #[Test]
    public function it_refuses_an_invalid_declaration_and_names_the_attribute(Closure $declare, string $problem): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', "attribute 'title' {$problem}"));

        $declare(new Attribute('listings', 'title'));
    }

    #[Test]
    public function it_refuses_a_closure_that_captures_a_variable(): void
    {
        $now = '2026-11-01 12:00:00';

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', "the isNotNull() closure of attribute 'is_published' captures \$now, and a map reads nothing but its own arguments"));

        (new Attribute('listings', 'is_published'))->from('published_at')->isNotNull(fn () => $now);
    }

    #[Test]
    public function it_refuses_an_inverse_or_an_external_closure_that_captures_a_variable(): void
    {
        $suffix = '!';
        $refusals = [];

        foreach ([
            fn (Attribute $attribute) => $attribute->writeTo(fn (mixed $value) => $value.$suffix),
            fn (Attribute $attribute) => $attribute->external(fn (mixed $files) => $files.$suffix, fn (mixed $file, mixed $value) => null),
            fn (Attribute $attribute) => $attribute->external(fn (mixed $files) => $files, fn (mixed $file, mixed $value) => $value.$suffix),
        ] as $declare) {
            try {
                $declare(new Attribute('listings', 'title'));
            } catch (InvalidCompatibilityMap $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $this->assertSame([
            "Compatibility map 'listings' is declared wrongly: the writeTo() closure of attribute 'title' captures \$suffix, and a map reads nothing but its own arguments.",
            "Compatibility map 'listings' is declared wrongly: the external() read closure of attribute 'title' captures \$suffix, and a map reads nothing but its own arguments.",
            "Compatibility map 'listings' is declared wrongly: the external() write closure of attribute 'title' captures \$suffix, and a map reads nothing but its own arguments.",
        ], $refusals);
    }
}
