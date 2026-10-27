<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\TestCase;

#[CoversClass(PendingMirroredWrite::class)]
final class PendingMirroredWriteTest extends TestCase
{
    #[Test]
    public function it_starts_pending_with_no_statement_sent_no_transaction_and_no_snapshot_of_the_write_it_was_given(): void
    {
        $widget = new Widget;

        $pendingWrite = new PendingMirroredWrite($widget, Operation::Update);

        $this->assertSame(
            ['pending' => true, 'statementSent' => false, 'snapshotsBefore' => [], 'transactionLevel' => 0, 'savepoint' => null, 'incrementColumns' => null, 'source' => $widget, 'operation' => Operation::Update],
            get_object_vars($pendingWrite),
        );
    }

    /**
     * @param  list<mixed>  $bindings
     * @param  array<string, string>  $dirty
     */
    #[Test]
    #[TestWith([Operation::Insert, 'insert into "widgets" ("id", "title") values (?, ?) returning "id"', [7, 'Blue gadget'], [], true])]
    #[TestWith([Operation::Insert, 'update "widgets" set "title" = ? where "id" = ?', ['Renamed', 7], ['title' => 'Renamed'], false])]
    #[TestWith([Operation::Insert, 'delete from "widgets" where "id" = ?', [7], [], false])]
    #[TestWith([Operation::Update, 'insert into "widgets" ("title") values (?) returning "id"', ['Blue gadget'], [], false])]
    #[TestWith([Operation::Update, 'update "widgets" set "title" = ? where "id" = ?', ['Renamed', 7], ['title' => 'Renamed'], true])]
    #[TestWith([Operation::Update, 'delete from "widgets" where "id" = ?', [7], [], false])]
    #[TestWith([Operation::Delete, 'insert into "widgets" ("title") values (?) returning "id"', ['Blue gadget'], [], false])]
    #[TestWith([Operation::Delete, 'update "widgets" set "deleted_at" = ?, "updated_at" = ? where "id" = ?', ['2026-10-26 12:00:00', '2026-10-26 12:00:00', 7], ['deleted_at' => '2026-10-26 12:00:00', 'updated_at' => '2026-10-26 12:00:00'], true])]
    #[TestWith([Operation::Delete, 'delete from "widgets" where "id" = ?', [7], [], true])]
    public function it_expects_the_operation_of_its_model_event_and_a_soft_delete_for_a_delete(Operation $operation, string $sql, array $bindings, array $dirty, bool $expected): void
    {
        $this->assertSame($expected, $this->pendingWrite($this->widget()->forceFill($dirty), $operation)->expects($this->parsed($sql), $bindings));
    }

    /**
     * @param  array<string, string>  $dirty
     */
    #[Test]
    #[TestWith([Operation::Update, ['title' => 'Renamed', 'updated_at' => '2026-10-26 12:00:00'], 'update "widgets" set "title" = ?, "updated_at" = ? where "id" = ?', true])]
    #[TestWith([Operation::Update, ['title' => 'Renamed'], 'update "widgets" set "title" = ?, "updated_at" = ? where "id" = ?', true])]
    #[TestWith([Operation::Update, ['title' => 'Renamed'], 'update "widgets" set "sku" = ? where "id" = ?', false])]
    #[TestWith([Operation::Update, ['title' => 'Renamed'], 'update "widgets" set "sku" = ?, "title" = ? where "id" = ?', false])]
    #[TestWith([Operation::Update, ['title' => 'Renamed', 'sku' => 'Touched'], 'update "widgets" set "title" = ? where "id" = ?', false])]
    #[TestWith([Operation::Update, ['title' => 'Renamed'], 'update "widgets" set ("title") = row(?) where "id" = ?', false])]
    #[TestWith([Operation::Delete, ['deleted_at' => '2026-10-26 12:00:00', 'updated_at' => '2026-10-26 12:00:00'], 'update "widgets" set "deleted_at" = ?, "updated_at" = ? where "id" = ?', true])]
    #[TestWith([Operation::Delete, ['title' => 'Renamed', 'deleted_at' => '2026-10-26 12:00:00'], 'update "widgets" set "deleted_at" = ?, "updated_at" = ? where "id" = ?', true])]
    #[TestWith([Operation::Delete, [], 'update "widgets" set "deleted_by" = ? where "id" = ?', false])]
    #[TestWith([Operation::Delete, [], 'update "widgets" set "deleted_at" = ? where "id" = ?', false])]
    #[TestWith([Operation::Delete, ['deleted_at' => '2026-10-26 12:00:00'], 'update "widgets" set "updated_at" = ? where "id" = ?', false])]
    public function it_expects_an_update_that_sets_exactly_its_models_changes_and_a_soft_delete_that_sets_only_changed_columns_and_the_deleted_at_column(Operation $operation, array $dirty, string $sql, bool $expected): void
    {
        $bindings = [...array_fill(0, substr_count($sql, '?') - 1, 'Bound'), 7];

        $this->assertSame($expected, $this->pendingWrite($this->widget()->forceFill($dirty), $operation)->expects($this->parsed($sql), $bindings));
    }

    /**
     * @param  list<string>  $incrementColumns
     */
    #[Test]
    #[TestWith([['latitude'], 'update "widgets" set "latitude" = "latitude" + ?, "updated_at" = ? where "id" = ?', true])]
    #[TestWith([['latitude', 'sku'], 'update "widgets" set "latitude" = "latitude" + ?, "sku" = ?, "updated_at" = ? where "id" = ?', true])]
    #[TestWith([['latitude', 'updated_at'], 'update "widgets" set "latitude" = "latitude" + ?, "updated_at" = ? where "id" = ?', true])]
    #[TestWith([['latitude', 'price'], 'update "widgets" set "latitude" = "latitude" + ?, "price" = "price" + ?, "updated_at" = ? where "id" = ?', true])]
    #[TestWith([['latitude'], 'update "widgets" set "latitude" = ? where "id" = ?', false])]
    #[TestWith([['latitude'], 'update "widgets" set "sku" = ?, "updated_at" = ? where "id" = ?', false])]
    #[TestWith([['latitude'], 'update "widgets" set "latitude" = "latitude" + ?, "title" = ?, "updated_at" = ? where "id" = ?', false])]
    #[TestWith([['latitude', 'sku'], 'update "widgets" set "latitude" = "latitude" + ?, "updated_at" = ? where "id" = ?', false])]
    public function it_expects_an_increment_that_sets_exactly_its_columns_and_the_updated_at_column_whatever_else_its_model_changed(array $incrementColumns, string $sql, bool $expected): void
    {
        $pendingWrite = $this->pendingWrite($this->widget()->forceFill(['title' => 'Never saved', 'latitude' => 49.0]), Operation::Update);
        $pendingWrite->incrementColumns = $incrementColumns;
        $bindings = [...array_fill(0, substr_count($sql, '?') - 1, 'Bound'), 7];

        $this->assertSame($expected, $pendingWrite->expects($this->parsed($sql), $bindings));
    }

    #[Test]
    #[TestWith(['update "widgets" set "latitude" = "latitude" + ? where "id" = ?', true])]
    #[TestWith(['update "widgets" set "latitude" = "latitude" + ?, "updated_at" = ? where "id" = ?', false])]
    public function it_expects_an_increment_of_a_model_without_timestamps_to_set_only_its_columns(string $sql, bool $expected): void
    {
        $widget = $this->widget()->forceFill(['title' => 'Never saved', 'latitude' => 49.0]);
        $widget->timestamps = false;
        $pendingWrite = $this->pendingWrite($widget, Operation::Update);
        $pendingWrite->incrementColumns = ['latitude'];
        $bindings = [...array_fill(0, substr_count($sql, '?') - 1, 'Bound'), 7];

        $this->assertSame($expected, $pendingWrite->expects($this->parsed($sql), $bindings));
    }

    /**
     * @param  list<mixed>  $bindings
     */
    #[Test]
    #[TestWith([Operation::Update, 'update "widgets" set "sku" = ?, "updated_at" = ? where "widgets"."id" = ? and "widgets"."deleted_at" is null', ['Touched', '2026-10-26 12:00:00', 8], false])]
    #[TestWith([Operation::Update, 'update "widgets" set "sku" = ?, "updated_at" = ? where "widgets"."id" = ? and "widgets"."deleted_at" is null', ['Touched', '2026-10-26 12:00:00', 7], true])]
    #[TestWith([Operation::Update, 'update "widgets" set "sku" = ? where "id" = ?', ['Touched', '7'], false])]
    #[TestWith([Operation::Update, 'update "widgets" set "sku" = ? where "tenant_id" = ? and "id" = ?', ['Touched', 3, 7], true])]
    #[TestWith([Operation::Delete, 'delete from "widgets" where "id" = ?', [8], false])]
    #[TestWith([Operation::Delete, 'delete from "widgets"', [], false])]
    public function it_expects_an_update_or_delete_only_when_the_statement_binds_its_models_key_for_saving(Operation $operation, string $sql, array $bindings, bool $expected): void
    {
        $this->assertSame($expected, $this->pendingWrite($this->widget()->forceFill(['sku' => 'Touched']), $operation)->expects($this->parsed($sql), $bindings));
    }

    #[Test]
    public function it_expects_an_update_that_binds_the_key_the_model_was_loaded_with_after_the_key_changes(): void
    {
        $widget = $this->widget();
        $widget->setAttribute('id', 9);

        $this->assertSame(
            [true, false],
            [
                $this->pendingWrite($widget, Operation::Update)->expects($this->parsed('update "widgets" set "id" = ? where "id" = ?'), [9, 7]),
                $this->pendingWrite($widget, Operation::Update)->expects($this->parsed('update "widgets" set "id" = ? where "id" = ?'), [7, 9]),
            ],
        );
    }

    #[Test]
    #[TestWith(['insert into "widgets" ("title", "price") values (?, ?) returning "id"', true])]
    #[TestWith(['insert into "widgets" ("title") values (?) returning "id"', false])]
    #[TestWith(['insert into "widgets" ("price", "title") values (?, ?) returning "id"', false])]
    #[TestWith(['insert into "widgets" ("title", "price", "sku") values (?, ?, ?) returning "id"', false])]
    #[TestWith(['insert into "widgets" default values returning "id"', false])]
    public function it_expects_an_insert_only_when_it_names_the_columns_of_its_models_attributes(string $sql, bool $expected): void
    {
        $widget = new Widget(['title' => 'Blue gadget', 'price' => '12.50']);

        $this->assertSame($expected, $this->pendingWrite($widget, Operation::Insert)->expects($this->parsed($sql), ['Blue gadget', '12.50']));
    }

    #[Test]
    public function it_expects_an_insert_of_default_values_for_a_model_without_attributes(): void
    {
        $this->assertTrue($this->pendingWrite(new Widget, Operation::Insert)->expects($this->parsed('insert into "widgets" default values returning "id"'), []));
    }

    #[Test]
    #[TestWith(['update "gadgets" set "name" = ? where "id" = ?'])]
    #[TestWith(['update "retained_widgets" set "sku" = ? where "id" = ?'])]
    #[TestWith(['update "widgets_archive" set "title" = ? where "id" = ?'])]
    public function it_expects_no_write_to_another_relation(string $sql): void
    {
        $this->assertFalse($this->pendingWrite($this->widget(), Operation::Update)->expects($this->parsed($sql), ['Renamed', 7]));
    }

    #[Test]
    #[TestWith(['public.widgets', 'update "widgets" set "title" = ? where "id" = ?'])]
    #[TestWith(['widgets', 'update "public"."widgets" set "title" = ? where "id" = ?'])]
    #[TestWith(['Legacy.Widgets', 'update "legacy"."Widgets" set "title" = ? where "id" = ?'])]
    public function it_expects_a_write_to_its_relation_whatever_the_schema_of_either_name(string $table, string $sql): void
    {
        $this->assertTrue($this->pendingWrite($this->widget()->setTable($table)->forceFill(['title' => 'Renamed']), Operation::Update)->expects($this->parsed($sql), ['Renamed', 7]));
    }

    #[Test]
    #[TestWith(['Widgets', 'update "widgets" set "title" = ? where "id" = ?'])]
    #[TestWith(['widgets', 'update "Widgets" set "title" = ? where "id" = ?'])]
    public function it_expects_no_write_to_a_relation_whose_quoted_name_differs_in_case(string $table, string $sql): void
    {
        $this->assertFalse($this->pendingWrite($this->widget()->setTable($table), Operation::Update)->expects($this->parsed($sql), ['Renamed', 7]));
    }

    private function widget(): Widget
    {
        $widget = (new Widget)->forceFill(['id' => 7, 'title' => 'Blue gadget']);
        $widget->syncOriginal();
        $widget->exists = true;

        return $widget;
    }

    private function pendingWrite(Widget $widget, Operation $operation): PendingMirroredWrite
    {
        return new PendingMirroredWrite($widget, $operation);
    }

    private function parsed(string $sql): ParsedWrite
    {
        $parsedWrite = ParsedWrite::of($sql);
        $this->assertNotNull($parsedWrite);

        return $parsedWrite;
    }
}
