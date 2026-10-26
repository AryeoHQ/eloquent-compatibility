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
    public function it_starts_pending_with_no_statement_sent_and_no_snapshot_of_the_write_it_was_given(): void
    {
        $widget = new Widget;

        $pendingWrite = new PendingMirroredWrite($widget, Operation::Update, 1, 'compatibility_1');

        $this->assertSame(
            ['pending' => true, 'statementSent' => false, 'snapshotsBefore' => [], 'source' => $widget, 'operation' => Operation::Update, 'transactionLevel' => 1, 'savepoint' => 'compatibility_1'],
            get_object_vars($pendingWrite),
        );
    }

    #[Test]
    #[TestWith([Operation::Insert, 'insert into "widgets" ("title") values (?) returning "id"', true])]
    #[TestWith([Operation::Insert, 'update "widgets" set "title" = ? where "id" = ?', false])]
    #[TestWith([Operation::Insert, 'delete from "widgets" where "id" = ?', false])]
    #[TestWith([Operation::Update, 'insert into "widgets" ("title") values (?) returning "id"', false])]
    #[TestWith([Operation::Update, 'update "widgets" set "title" = ? where "id" = ?', true])]
    #[TestWith([Operation::Update, 'delete from "widgets" where "id" = ?', false])]
    #[TestWith([Operation::Delete, 'insert into "widgets" ("title") values (?) returning "id"', false])]
    #[TestWith([Operation::Delete, 'update "widgets" set "deleted_at" = ?, "updated_at" = ? where "id" = ?', true])]
    #[TestWith([Operation::Delete, 'delete from "widgets" where "id" = ?', true])]
    public function it_expects_the_operation_of_its_model_event_and_a_soft_delete_for_a_delete(Operation $operation, string $sql, bool $expected): void
    {
        $this->assertSame($expected, $this->pendingWrite(new Widget, $operation)->expects($this->parsed($sql)));
    }

    #[Test]
    #[TestWith(['update "gadgets" set "name" = ? where "id" = ?'])]
    #[TestWith(['update "retained_widgets" set "sku" = ? where "id" = ?'])]
    #[TestWith(['update "widgets_archive" set "title" = ? where "id" = ?'])]
    public function it_expects_no_write_to_another_relation(string $sql): void
    {
        $this->assertFalse($this->pendingWrite(new Widget, Operation::Update)->expects($this->parsed($sql)));
    }

    #[Test]
    #[TestWith(['public.widgets', 'update "widgets" set "title" = ?'])]
    #[TestWith(['widgets', 'update "public"."widgets" set "title" = ?'])]
    #[TestWith(['Legacy.Widgets', 'update "legacy"."Widgets" set "title" = ?'])]
    public function it_expects_a_write_to_its_relation_whatever_the_schema_of_either_name(string $table, string $sql): void
    {
        $this->assertTrue($this->pendingWrite((new Widget)->setTable($table), Operation::Update)->expects($this->parsed($sql)));
    }

    #[Test]
    #[TestWith(['Widgets', 'update "widgets" set "title" = ?'])]
    #[TestWith(['widgets', 'update "Widgets" set "title" = ?'])]
    public function it_expects_no_write_to_a_relation_whose_quoted_name_differs_in_case(string $table, string $sql): void
    {
        $this->assertFalse($this->pendingWrite((new Widget)->setTable($table), Operation::Update)->expects($this->parsed($sql)));
    }

    private function pendingWrite(Widget $widget, Operation $operation): PendingMirroredWrite
    {
        return new PendingMirroredWrite($widget, $operation, 0, null);
    }

    private function parsed(string $sql): ParsedWrite
    {
        $parsedWrite = ParsedWrite::of($sql);
        $this->assertNotNull($parsedWrite);

        return $parsedWrite;
    }
}
