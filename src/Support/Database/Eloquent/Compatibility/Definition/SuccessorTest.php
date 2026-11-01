<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(Successor::class)]
final class SuccessorTest extends TestCase
{
    #[Test]
    public function it_reads_the_table_and_key_of_its_model(): void
    {
        $successor = new Successor('listings', Job::class);

        $this->assertSame([Job::class, 'jobs', 'id'], [$successor->model, $successor->table, $successor->key]);
    }

    #[Test]
    public function it_declares_each_attribute_through_its_closure(): void
    {
        $successor = (new Successor('listings', Job::class))
            ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
            ->attribute('price', fn (Attribute $attribute) => $attribute->from('price_cents')->dividedBy(100));

        $this->assertSame(['title', 'price'], array_keys($successor->attributes()));
        $this->assertSame(['name', 'price_cents'], array_map(fn (Attribute $attribute) => $attribute->column(), array_values($successor->attributes())));
    }

    #[Test]
    public function it_nests_each_related_table_and_a_second_hop_inside_it(): void
    {
        $successor = (new Successor('listings', Job::class))
            ->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place
                ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                ->related(fn (Place $place) => $place->hasOne(JobSite::class, 'job_id', 'job_id'), fn (Successor $site) => $site
                    ->attribute('latitude', fn (Attribute $attribute) => $attribute->from('latitude'))));

        [$places] = $successor->relations();
        [$sites] = $places->successor->relations();

        $this->assertSame([Place::class, ['street'], JobSite::class, ['latitude']], [
            $places->model,
            array_keys($places->successor->attributes()),
            $sites->model,
            array_keys($sites->successor->attributes()),
        ]);
    }

    #[Test]
    public function it_carries_the_reason_it_refuses_a_second_related_row(): void
    {
        $this->assertSame([null, 'A listing has one address.'], [
            (new Successor('listings', Place::class))->atMostOneBecause(),
            (new Successor('listings', Place::class))->atMostOne(because: 'A listing has one address.')->atMostOneBecause(),
        ]);
    }

    #[Test]
    public function it_refuses_an_attribute_declared_twice(): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', "attribute 'title' is declared twice"));

        (new Successor('listings', Job::class))
            ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
            ->attribute('title', fn (Attribute $attribute) => $attribute->from('headline'));
    }

    #[Test]
    public function it_refuses_an_attribute_without_a_name(): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'an attribute of Job has no name'));

        (new Successor('listings', Job::class))->attribute('', fn (Attribute $attribute) => $attribute->from('name'));
    }

    #[Test]
    public function it_refuses_an_attribute_closure_that_captures_a_variable(): void
    {
        $column = 'name';

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', "the closure of attribute 'title' captures \$column, and a map reads nothing but its own arguments"));

        (new Successor('listings', Job::class))->attribute('title', fn (Attribute $attribute) => $attribute->from($column));
    }

    #[Test]
    public function it_refuses_at_most_one_without_a_reason(): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'atMostOne() on Place gives no reason; it takes the reason a second related row is refused'));

        (new Successor('listings', Place::class))->atMostOne('');
    }
}
