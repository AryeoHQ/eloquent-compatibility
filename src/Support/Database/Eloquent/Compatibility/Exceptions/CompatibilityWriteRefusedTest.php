<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Definition\Guard;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Listings\JobSite;
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
            CompatibilityWriteRefused::unstorable(Listing::class, 'status', "mapping() maps no stored value to 'Pending'."),
            CompatibilityWriteRefused::byGuard(Listing::class, new Guard('sold price frozen', fn () => true, 'A sold listing keeps the price it sold for.', 'Change the price before the sale.'), 7),
            CompatibilityWriteRefused::bySuccessorListener(Listing::class, new Place),
            CompatibilityWriteRefused::noParentRow(Listing::class, ['street', 'city'], 'places', 'job_sites'),
            CompatibilityWriteRefused::noRelatedRow(Listing::class, ['street'], 'places', 'jobs'),
            CompatibilityWriteRefused::rawExpression(Listing::class, 'title', 'It is stored in jobs, and SQL written for the legacy table would read the columns of jobs instead.'),
            CompatibilityWriteRefused::roundTrip(Listing::class, 'title', 7, 'Oak Manor', 'OAK MANOR'),
            CompatibilityWriteRefused::successorsMoved(Listing::class, JobSite::class, ['a1'], []),
            CompatibilityWriteRefused::membershipMoved(
                root: File::class,
                raised: [],
                joined: [Photo::class, LegacyFile::class],
            ),
        ];

        $this->assertSame([
            'Tests\Fixtures\Support\Listings\Listing record 7 cannot change $company_id. Why: A job never changes company. Instead: Leave company_id unchanged in writes through Listing.',
            'Tests\Fixtures\Support\Listings\Listing::$status cannot take a write. Why: mapping() maps no stored value to \'Pending\'. Instead: Write a value its conversions can store, or write the stored column through the successor model.',
            'Tests\Fixtures\Support\Listings\Listing record 7 cannot take this write, because guard \'sold price frozen\' refuses it. Why: A sold listing keeps the price it sold for. Instead: Change the price before the sale.',
            'Tests\Fixtures\Support\Listings\Listing cannot take this write, because a listener of Place refused its save. Why: The write stores its values through Place, and that model\'s own listeners refused them. Instead: Make the write satisfy the rules of Place.',
            'Tests\Fixtures\Support\Listings\Listing cannot write $street, $city. Why: They live on a places row, and the record has no job_sites row to hold one. Instead: Write a value that creates the job_sites row in the same write, or create that row through its own model first.',
            'Tests\Fixtures\Support\Listings\Listing cannot write $street. Why: They live on a places row the record does not have, and jobs reaches that row through a belongs-to relation, which creates no row. Instead: Create the places row through its own model and associate it with the jobs row first.',
            'Tests\Fixtures\Support\Listings\Listing::$title cannot take a raw expression. Why: It is stored in jobs, and SQL written for the legacy table would read the columns of jobs instead. Instead: Write the computed value, or write the stored column through the successor model.',
            'Tests\Fixtures\Support\Listings\Listing record 7 cannot take this write of $title. Why: The write stores \'Oak Manor\', and the view now serves title as \'OAK MANOR\', so the record would not read back what was written. Instead: Make the operation of title store a value its read serves back.',
            'Tests\Fixtures\Support\Listings\Listing cannot take this write now, because its JobSite rows moved after the write scope ran. Why: The write scope received 1 key, and the package then locked 0 keys. It locked [], which the scope never received, and no longer found [a1], so it would write a row the scope never ordered. Instead: Retry the write in a new transaction; the outermost transaction of the application is the retry point.',
            'Tests\Fixtures\Support\Files\File cannot take this create, because its legacy model changed between its creating event and its insert. Why: The package raised the legacy saving and creating events to no legacy model, and the inserted File row joins Photo and LegacyFile, so a column that when() reads changed after the creating event. Instead: Set every column that when() reads before the insert, in the create\'s attributes or in a listener of File, rather than in a database default or trigger.',
        ], array_map(fn (CompatibilityWriteRefused $refusal) => $refusal->getMessage(), $refusals));
        $this->assertContainsOnlyInstancesOf(CompatibilityException::class, $refusals);
    }

    #[Test]
    public function it_names_only_the_keys_that_moved_up_to_ten_and_counts_both_lists(): void
    {
        $scoped = array_map(
            callback: fn (int $key) => 'k'.$key,
            array: range(1, 203),
        );
        $locked = array_values(array_diff($scoped, ['k7']));
        $locked = [...$locked, ...array_map(
            callback: fn (int $key) => 'n'.$key,
            array: range(1, 12),
        )];

        $because = CompatibilityWriteRefused::successorsMoved(
            model: Listing::class,
            successor: JobSite::class,
            scoped: $scoped,
            locked: $locked,
        )->because;

        $this->assertSame('The write scope received 203 keys, and the package then locked 214 keys. It locked [n1, n2, n3, n4, n5, n6, n7, n8, n9, n10, and 2 more], which the scope never received, and no longer found [k7], so it would write a row the scope never ordered.', $because);
    }

    #[Test]
    public function it_names_the_record_and_the_successor_a_listener_refused(): void
    {
        $place = (new Place)->forceFill(['id' => 'a1b2c3d4-0000-4000-8000-000000000001']);

        $this->assertSame(
            'Tests\Fixtures\Support\Listings\Listing record 3 cannot take this write, because a listener of Place a1b2c3d4-0000-4000-8000-000000000001 refused its save',
            CompatibilityWriteRefused::bySuccessorListener(
                model: Listing::class,
                successor: $place,
                key: 3,
            )->refused,
        );
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
