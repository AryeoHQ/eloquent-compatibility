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
