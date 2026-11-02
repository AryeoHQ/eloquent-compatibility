<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Definition\Guard;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(CompatibilityWriteRefused::class)]
final class CompatibilityWriteRefusedTest extends TestCase
{
    #[Test]
    public function it_names_what_it_refused_why_and_what_to_do_instead(): void
    {
        $refusals = [
            CompatibilityWriteRefused::readOnly(Listing::class, 'company_id', 7, 'A job never changes company.'),
            CompatibilityWriteRefused::transitions(Listing::class, 'status'),
            CompatibilityWriteRefused::external(Listing::class, 'mime_type'),
            CompatibilityWriteRefused::unstorable(Listing::class, 'status', "mapping() maps no stored value to 'Pending'."),
            CompatibilityWriteRefused::byGuard(Listing::class, new Guard('sold price frozen', fn () => true, 'A sold listing keeps the price it sold for.', 'Change the price before the sale.'), 7),
            CompatibilityWriteRefused::bySuccessorListener(Listing::class, new Place),
            CompatibilityWriteRefused::noParentRow(Listing::class, ['street', 'city'], 'places', 'job_sites'),
            CompatibilityWriteRefused::noRelatedRow(Listing::class, ['street'], 'places', 'jobs'),
        ];

        $this->assertSame([
            'Tests\Fixtures\Support\Listings\Listing record 7 cannot change $company_id. Why: A job never changes company. Instead: Leave company_id unchanged in writes through Listing.',
            'Tests\Fixtures\Support\Listings\Listing::$status cannot take a write. Why: It is declared transitions(), so a write must run the successor\'s state transition, and this release runs no transition yet. Instead: Change the state through the successor model\'s own transition.',
            'Tests\Fixtures\Support\Listings\Listing::$mime_type cannot take a write. Why: It is declared external(), so its value lives outside the database, and this release writes no external value yet. Instead: Write the value through the successor model.',
            'Tests\Fixtures\Support\Listings\Listing::$status cannot take a write. Why: mapping() maps no stored value to \'Pending\'. Instead: Write a value its conversions can store, or write the stored column through the successor model.',
            'Tests\Fixtures\Support\Listings\Listing record 7 cannot take this write, because guard \'sold price frozen\' refuses it. Why: A sold listing keeps the price it sold for. Instead: Change the price before the sale.',
            'Tests\Fixtures\Support\Listings\Listing cannot take this write, because a listener of Place refused its save. Why: The write stores its values through Place, and that model\'s own listeners refused them. Instead: Make the write satisfy the rules of Place.',
            'Tests\Fixtures\Support\Listings\Listing cannot write $street, $city. Why: They live on a places row, and the record has no job_sites row to hold one. Instead: Write a value that creates the job_sites row in the same write, or create that row through its own model first.',
            'Tests\Fixtures\Support\Listings\Listing cannot write $street. Why: They live on a places row the record does not have, and jobs reaches that row through a belongs-to relation, which creates no row. Instead: Create the places row through its own model and associate it with the jobs row first.',
        ], array_map(fn (CompatibilityWriteRefused $refusal) => $refusal->getMessage(), $refusals));
        $this->assertContainsOnlyInstancesOf(CompatibilityException::class, $refusals);
    }

    #[Test]
    public function it_keeps_each_part_of_the_refusal_apart(): void
    {
        $refusal = CompatibilityWriteRefused::readOnly(Listing::class, 'company_id', 7, 'A job never changes company.');

        $this->assertSame(
            ['Tests\Fixtures\Support\Listings\Listing record 7 cannot change $company_id', 'A job never changes company.', 'Leave company_id unchanged in writes through Listing.'],
            [$refusal->refused, $refusal->because, $refusal->instead],
        );
    }
}
