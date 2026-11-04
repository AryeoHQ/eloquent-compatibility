<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(Related::class)]
final class RelatedTest extends TestCase
{
    #[Test]
    public function it_compiles_a_has_one_with_its_conditions_order_soft_deletes_and_attributes(): void
    {
        $related = Related::compile('listings', Job::class, fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place
            ->attribute('street', fn (Attribute $attribute) => $attribute->from('street')));

        $this->assertSame(
            [Job::class, 'jobs', 'id', Place::class, 'places', 'job_id', false, '"places"."parent_id" is null', [], '"id" asc', 'deleted_at', ['parent_id' => null]],
            [$related->parent, $related->parentTable, $related->parentKey, $related->model, $related->table, $related->relatedKey, $related->belongsTo, $related->conditions, $related->bindings, $related->orders, $related->deletedAt, $related->attributes],
        );
        $this->assertSame([Place::class, ['street']], [$related->successor->model, array_keys($related->successor->attributes())]);
    }

    #[Test]
    public function it_compiles_a_plain_has_one_to_a_model_that_does_not_soft_delete(): void
    {
        $related = Related::compile('listings', Job::class, fn (Job $job) => $job->site(), fn (Successor $site) => $site);

        $this->assertSame(['job_sites', 'job_id', 'id', null, [], null, null, []], [$related->table, $related->relatedKey, $related->parentKey, $related->conditions, $related->bindings, $related->orders, $related->deletedAt, $related->attributes]);
    }

    #[Test]
    public function it_compiles_a_belongs_to_whose_parent_holds_the_key(): void
    {
        $related = Related::compile('places', Place::class, fn (Place $place) => $place->job(), fn (Successor $job) => $job);

        $this->assertSame(['places', 'job_id', Job::class, 'jobs', 'id', true, 'deleted_at'], [$related->parentTable, $related->parentKey, $related->model, $related->table, $related->relatedKey, $related->belongsTo, $related->deletedAt]);
    }

    #[Test]
    public function it_keeps_each_binding_of_a_condition_apart_from_its_sql(): void
    {
        $related = Related::compile('listings', Job::class, fn (Job $job) => $job->hasOne(Place::class)->where('city', 'Seattle')->whereIn('street', ['1 Main St', '2 Main St']), fn (Successor $place) => $place);

        $this->assertSame(['"city" = ? and "street" in (?, ?)', ['Seattle', '1 Main St', '2 Main St']], [$related->conditions, $related->bindings]);
    }

    #[Test]
    public function it_reads_trashed_rows_when_the_relation_says_with_trashed(): void
    {
        $this->assertNull(Related::compile('listings', Job::class, fn (Job $job) => $job->hasOne(Place::class)->withTrashed(), fn (Successor $place) => $place)->deletedAt);
    }

    #[Test]
    public function it_keeps_the_relation_closure_to_call_on_a_stored_model(): void
    {
        $related = Related::compile('listings', Job::class, fn (Job $job) => $job->site(), fn (Successor $site) => $site);
        $job = new Job(['id' => '00000000-0000-0000-0000-000000000001']);

        $this->assertSame('00000000-0000-0000-0000-000000000001', ($related->relation)($job)->getParentKey());
    }

    #[Test]
    public function it_registers_no_connection_and_leaves_the_connection_resolver_as_it_found_it(): void
    {
        $resolver = Model::getConnectionResolver();

        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }

        Related::compile('listings', Job::class, fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place);

        $this->assertSame([], DB::getConnections());
        $this->assertSame($resolver, Model::getConnectionResolver());
    }

    #[Test]
    public function it_restores_the_connection_resolver_after_a_refusal(): void
    {
        $resolver = Model::getConnectionResolver();

        try {
            Related::compile('listings', Job::class, fn (Job $job) => $job->places(), fn (Successor $place) => $place);
        } catch (InvalidCompatibilityMap) {
        }

        $this->assertSame($resolver, Model::getConnectionResolver());
    }

    /**
     * @return iterable<string, array{Closure, Closure(Successor): mixed, string}>
     */
    public static function invalidRelations(): iterable
    {
        $none = fn (Successor $successor) => $successor;

        yield 'has many' => [fn (Job $job) => $job->places(), $none, 'the related() relation from Job to Place is a HasMany, and one legacy record reads one row of each related table'];
        yield 'of many' => [fn (Job $job) => $job->hasOne(Place::class)->latestOfMany(), $none, 'the related() relation from Job to Place is an "of many" relation, which joins; order a plain HasOne instead'];
        yield 'morph one' => [fn (Job $job) => $job->morphOne(Place::class, 'placeable'), $none, 'the related() relation from Job to Place is a MorphOne, and related() reads a HasOne or a BelongsTo'];
        yield 'a join' => [fn (Job $job) => $job->hasOne(Place::class)->join('job_sites', 'job_sites.job_id', '=', 'places.job_id'), $none, 'the related() relation from Job to Place joins another table'];
        yield 'an offset' => [fn (Job $job) => $job->hasOne(Place::class)->oldest('id')->offset(1), $none, 'the related() relation from Job to Place applies an offset, a group or a union, and the view reads the first row of its conditions and order'];
        yield 'a group' => [fn (Job $job) => $job->hasOne(Place::class)->groupBy('places.id'), $none, 'the related() relation from Job to Place applies an offset, a group or a union, and the view reads the first row of its conditions and order'];
        yield 'an order with bindings' => [fn (Job $job) => $job->hasOne(Place::class)->orderByRaw('street = ? desc', ['1 Main St']), $none, 'the related() relation from Job to Place orders by an expression with bindings'];
        yield 'a global scope' => [fn (Job $job) => $job->hasOne(Place::class)->withGlobalScope('tenant', fn (Builder $places) => $places->where('city', 'Seattle')), $none, "the related() relation from Job to Place applies the global scope tenant, which would read the application's state once and keep it; only SoftDeletes may apply"];
        yield 'not a relation' => [fn (Job $job) => $job->newQuery(), $none, 'a related() relation closure on Job returns Illuminate\Database\Eloquent\Builder, which is not an Eloquent relation'];
        yield 'an unknown relation' => [fn (Job $job) => $job->__call('listing', []), $none, 'a related() relation closure on Job calls a method Job does not have: Call to undefined method Tests\Fixtures\Support\Listings\Job::listing()'];
        yield 'a database read' => [fn (Job $job) => $job->hasOne(Place::class)->whereIn('street', Place::query()->pluck('street')), $none, 'a related() relation reads the database while Laravel builds it, and resolution opens no connection'];
        yield 'a unique index over a raw condition' => [fn (Job $job) => $job->hasOne(Place::class)->whereRaw('length(street) > 3'), fn (Successor $place) => $place->atMostOne('One.'), 'atMostOne() on the related() relation from Job to Place compiles its conditions into a unique index, and a raw condition is not a plain column predicate'];
        yield 'a unique index over another table' => [fn (Job $job) => $job->hasOne(Place::class)->where('jobs.name', 'Oak'), fn (Successor $place) => $place->atMostOne('One.'), 'atMostOne() on the related() relation from Job to Place compiles its conditions into a unique index on places, and jobs.name is not a column of places'];
        yield 'a parent attribute' => [fn (Job $job) => $job->hasOne(Place::class)->where('city', $job->name), $none, "a related() relation closure on Job reads its attribute name, and the view compiles one relation for every Job, so a condition cannot follow each row's value"];
        yield 'a cast parent attribute' => [fn (Job $job) => $job->hasOne(Place::class)->where('street', $job->getAttribute('lifecycle_status')), $none, "a related() relation closure on Job reads its attribute lifecycle_status, and the view compiles one relation for every Job, so a condition cannot follow each row's value"];
        yield 'a raw original parent attribute' => [fn (Job $job) => $job->hasOne(Place::class)->where('city', $job->getRawOriginal('company_id')), $none, "a related() relation closure on Job reads its attribute company_id, and the view compiles one relation for every Job, so a condition cannot follow each row's value"];
        yield 'an original parent attribute' => [fn (Job $job) => $job->hasOne(Place::class)->where('city', $job->getOriginal('name')), $none, "a related() relation closure on Job reads its original attributes, and the view compiles one relation for every Job, so a condition cannot follow each row's value"];
        yield 'the parent key' => [fn (Job $job) => $job->hasOne(Place::class)->where('parent_id', $job->getKey()), $none, "a related() relation closure on Job reads its attribute id, and the view compiles one relation for every Job, so a condition cannot follow each row's value"];
        yield 'a default model' => [fn (Job $job) => $job->hasOne(Place::class)->withDefault(['street' => 'Unknown']), $none, 'the related() relation from Job to Place returns a default model when no row exists, and the view serves NULL for each attribute of a missing row'];
        yield 'a default model built by a closure' => [fn (Job $job) => $job->hasOne(Place::class)->withDefault(fn (Place $place) => $place->forceFill(['street' => 'Unknown'])), $none, 'the related() relation from Job to Place returns a default model when no row exists, and the view serves NULL for each attribute of a missing row'];
        yield 'a default belongs to' => [fn (Job $job) => $job->belongsTo(Place::class, 'merged_into_job_id')->withDefault(), $none, 'the related() relation from Job to Place returns a default model when no row exists, and the view serves NULL for each attribute of a missing row'];
        yield 'an ungrouped or' => [fn (Job $job) => $job->hasOne(Place::class)->where('city', 'Seattle')->orWhere('street', 'Main'), $none, 'the related() relation from Job to Place joins a condition with or outside a group, and Eloquent then reads rows of every Job; group the conditions in a nested where()'];
        yield 'an or as the first condition' => [fn (Job $job) => $job->hasOne(Place::class)->orWhereNull('parent_id'), $none, 'the related() relation from Job to Place joins a condition with or outside a group, and Eloquent then reads rows of every Job; group the conditions in a nested where()'];
        yield 'a unique index over a column comparison' => [fn (Job $job) => $job->hasOne(Place::class)->whereColumn('street', 'city'), fn (Successor $place) => $place->atMostOne('One.'), 'atMostOne() on the related() relation from Job to Place compiles its conditions into a unique index, and a Column condition is not a plain column predicate'];
    }

    /**
     * @param  Closure(Successor): mixed  $definition
     */
    #[DataProvider('invalidRelations')]
    #[Test]
    public function it_refuses_a_relation_the_view_cannot_read_as_one_row(Closure $relation, Closure $definition, string $problem): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', $problem));

        Related::compile('listings', Job::class, $relation, $definition);
    }

    #[Test]
    public function it_leaves_eloquent_missing_attribute_handling_as_it_found_it(): void
    {
        Model::preventAccessingMissingAttributes();
        Model::handleMissingAttributeViolationUsing(fn (Model $model, string $key) => "handled {$key}");

        try {
            Related::compile('listings', Job::class, fn (Job $job) => $job->hasOne(Place::class)->where('city', $job->name), fn (Successor $place) => $place);
            $this->fail('compile() did not refuse.');
        } catch (InvalidCompatibilityMap) {
            $job = new Job;
            $job->exists = true;

            $this->assertSame([true, 'handled name'], [Model::preventsAccessingMissingAttributes(), $job->name]);
        } finally {
            Model::preventAccessingMissingAttributes(false);
            Model::handleMissingAttributeViolationUsing(null);
        }
    }

    #[Test]
    public function it_compiles_an_or_that_a_nested_where_groups(): void
    {
        $related = Related::compile('listings', Job::class, fn (Job $job) => $job->hasOne(Place::class)->where(fn (Builder $places) => $places->where('city', 'Seattle')->orWhere('street', 'Main')), fn (Successor $place) => $place);

        $this->assertSame(['("city" = ? or "street" = ?)', ['Seattle', 'Main']], [$related->conditions, $related->bindings]);
    }

    #[Test]
    public function it_accepts_a_unique_index_over_plain_conditions_on_its_own_table(): void
    {
        $related = Related::compile('listings', Job::class, fn (Job $job) => $job->hasOne(Place::class)->whereNull('parent_id')->where('places.city', 'Seattle')->whereIn('street', ['1 Main St']), fn (Successor $place) => $place->atMostOne('One.'));

        $this->assertSame('One.', $related->successor->atMostOneBecause());
    }

    #[Test]
    public function it_refuses_a_closure_that_captures_a_variable(): void
    {
        $relation = 'rootPlace';

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'a related() relation closure on Job captures $relation, and a map reads nothing but its own arguments'));

        Related::compile('listings', Job::class, fn (Job $job) => $job->{$relation}(), fn (Successor $place) => $place);
    }
}
