<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\Job;
use Tests\TestCase;

#[CoversClass(Identifier::class)]
final class IdentifierTest extends TestCase
{
    #[Test]
    public function it_names_an_integer_identifier_and_its_successor_column(): void
    {
        $identifier = (new Identifier('listings', 'id'))->integer()->from('legacy_id')->resolve(new Successor('listings', Job::class));

        $this->assertSame(['id', 'legacy_id', true, false], [$identifier->name, $identifier->column(), $identifier->isInteger(), $identifier->isUuid()]);
    }

    #[Test]
    public function it_names_a_uuid_identifier_and_its_successor_column(): void
    {
        $identifier = (new Identifier('listings', 'uuid'))->uuid()->from('legacy_uuid')->resolve(new Successor('listings', Job::class));

        $this->assertSame(['uuid', 'legacy_uuid', false, true], [$identifier->name, $identifier->column(), $identifier->isInteger(), $identifier->isUuid()]);
    }

    /**
     * @return iterable<string, array{Closure(Identifier): mixed, string}>
     */
    public static function invalidDeclarations(): iterable
    {
        yield 'typed twice' => [fn (Identifier $identifier) => $identifier->integer()->uuid(), 'is typed twice, as integer() and as uuid()'];
        yield 'two columns' => [fn (Identifier $identifier) => $identifier->from('legacy_id')->from('old_id'), "reads from both 'legacy_id' and 'old_id'"];
        yield 'another table' => [fn (Identifier $identifier) => $identifier->from('listings.id'), "reads from 'listings.id', which names no column of the primary successor's own table"];
        yield 'no type' => [fn (Identifier $identifier) => $identifier->from('legacy_id')->resolve(new Successor('listings', Job::class)), 'says neither integer() nor uuid()'];
        yield 'no column' => [fn (Identifier $identifier) => $identifier->integer()->resolve(new Successor('listings', Job::class)), 'says no from(), so it names no successor column'];
        yield 'the successor key' => [fn (Identifier $identifier) => $identifier->uuid()->from('id')->resolve(new Successor('listings', Job::class)), "reads jobs.id, the successor's own key; a legacy identifier lives in a column of its own"];
    }

    /**
     * @param  Closure(Identifier): mixed  $declare
     */
    #[DataProvider('invalidDeclarations')]
    #[Test]
    public function it_refuses_an_invalid_identifier_and_names_it(Closure $declare, string $problem): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', "identifier 'id' {$problem}"));

        $declare(new Identifier('listings', 'id'));
    }
}
