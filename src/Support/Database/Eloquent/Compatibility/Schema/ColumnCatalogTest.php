<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\TestCase;

#[CoversClass(ColumnCatalog::class)]
final class ColumnCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
    }

    #[Test]
    public function it_reads_each_column_with_its_type_default_and_nullability_in_table_order(): void
    {
        $this->assertEquals([
            'id' => new Column('uuid', 'gen_random_uuid()', true),
            'gadget_id' => new Column('uuid', null, true),
            'latitude' => new Column('numeric(24,12)', null, true),
            'longitude' => new Column('numeric(24,12)', "'0'::numeric", true),
            'created_at' => new Column('timestamp(0) with time zone', null, false),
            'updated_at' => new Column('timestamp(0) with time zone', null, false),
        ], (new ColumnCatalog(DB::connection()))->of('gadget_sites'));
    }

    #[Test]
    public function it_leaves_out_a_dropped_column(): void
    {
        DB::statement('alter table gadget_sites drop column longitude');

        $this->assertSame(['id', 'gadget_id', 'latitude', 'created_at', 'updated_at'], array_keys((new ColumnCatalog(DB::connection()))->of('gadget_sites')));
    }

    #[Test]
    public function it_reads_no_columns_of_a_table_that_does_not_exist(): void
    {
        $this->assertSame([], (new ColumnCatalog(DB::connection()))->of('no_such_table'));
    }

    #[Test]
    public function it_reads_a_table_whose_name_and_columns_need_quoting(): void
    {
        DB::statement('create table "Odd ""Table""" ("order" integer not null default 1, "Say ""hi""" text)');

        $this->assertEquals(
            ['order' => new Column('integer', '1', true), 'Say "hi"' => new Column('text', null, false)],
            (new ColumnCatalog(DB::connection()))->of('Odd "Table"'),
        );
    }

    #[Test]
    #[TestWith(['gadgets', 'price_cents', 'integer'])]
    #[TestWith(['gadgets', 'delivered_at', 'timestamp(0) with time zone'])]
    #[TestWith(['gadgets', 'nope', null])]
    #[TestWith(['no_such_table', 'name', null])]
    public function it_gives_the_type_of_a_column_or_none_for_a_column_the_table_does_not_have(string $table, string $column, null|string $type): void
    {
        $this->assertSame($type, (new ColumnCatalog(DB::connection()))->type($table, $column));
    }

    #[Test]
    #[TestWith(['gadgets', 'name', true])]
    #[TestWith(['gadgets', 'price_cents', false])]
    #[TestWith(['gadgets', 'nope', false])]
    #[TestWith(['no_such_table', 'name', false])]
    public function it_tells_whether_a_column_is_not_null_and_treats_a_missing_column_as_nullable(string $table, string $column, bool $notNull): void
    {
        $this->assertSame($notNull, (new ColumnCatalog(DB::connection()))->notNull($table, $column));
    }

    #[Test]
    public function it_reads_an_identity_or_generated_column_as_generated(): void
    {
        DB::statement('create table numbered (id integer generated always as identity, name text not null, shout text generated always as (upper(name)) stored)');

        $this->assertEquals(
            ['id' => new Column('integer', null, true, true), 'name' => new Column('text', null, true), 'shout' => new Column('text', 'upper(name)', false, true)],
            (new ColumnCatalog(DB::connection()))->of('numbered'),
        );
    }

    #[Test]
    #[TestWith(['json', false])]
    #[TestWith(['xml', false])]
    #[TestWith(['point', false])]
    #[TestWith(['json_settings', false])]
    #[TestWith(['jsonb', true])]
    #[TestWith(['text', true])]
    #[TestWith(['character varying(255)', true])]
    #[TestWith(['numeric(24,12)', true])]
    #[TestWith(['timestamp(0) without time zone', true])]
    #[TestWith(['uuid', true])]
    #[TestWith(['integer[]', true])]
    #[TestWith(['int4range', true])]
    #[TestWith(['widget_status', true])]
    #[TestWith(['widget_label', true])]
    public function it_tells_whether_a_type_has_a_default_btree_equality_operator(string $type, bool $equatable): void
    {
        DB::statement('create domain json_settings as json');
        DB::statement("create type widget_status as enum ('Draft', 'Live')");
        DB::statement('create domain widget_label as character varying(40)');

        $this->assertSame($equatable, (new ColumnCatalog(DB::connection()))->equatable($type));
    }

    #[Test]
    public function it_reads_the_catalog_once_for_each_table(): void
    {
        $columnCatalog = new ColumnCatalog(DB::connection());
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $columnCatalog->of('gadgets');
        $columnCatalog->type('gadgets', 'name');
        $columnCatalog->notNull('gadgets', 'name');
        $columnCatalog->of('no_such_table');
        $columnCatalog->type('no_such_table', 'name');

        $this->assertSame(2, $queries);
    }
}
