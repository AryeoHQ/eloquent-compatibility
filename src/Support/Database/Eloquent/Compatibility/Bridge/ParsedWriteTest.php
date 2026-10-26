<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(ParsedWrite::class)]
final class ParsedWriteTest extends TestCase
{
    #[Test]
    #[TestWith(['update "gadget-items" set "name" = ? where "id" = ?', Operation::Update, 'gadget-items'])]
    #[TestWith(['insert into "gadget places" ("street") values (?)', Operation::Insert, 'gadget places'])]
    #[TestWith(['delete from "gadget""items" where "id" = ?', Operation::Delete, 'gadget"items'])]
    #[TestWith(['update "public"."gadget-items" set "name" = ?', Operation::Update, 'gadget-items'])]
    #[TestWith(['update "my ""schema"""."Gadget Items" set "name" = ?', Operation::Update, 'Gadget Items'])]
    #[TestWith(['update "Gadgets" set "name" = ?', Operation::Update, 'Gadgets'])]
    public function it_parses_a_quoted_relation_as_one_whole_case_sensitive_identifier(string $sql, Operation $operation, string $relation): void
    {
        $parsedWrite = ParsedWrite::of($sql);

        $this->assertSame([$operation, $relation], [$parsedWrite?->operation, $parsedWrite?->relation]);
    }

    #[Test]
    #[TestWith(['UPDATE Widgets SET sku = ?', Operation::Update, 'widgets'])]
    #[TestWith(['delete from Public.Widgets where id = ?', Operation::Delete, 'widgets'])]
    public function it_folds_an_unquoted_relation_to_lower_case(string $sql, Operation $operation, string $relation): void
    {
        $parsedWrite = ParsedWrite::of($sql);

        $this->assertSame([$operation, $relation], [$parsedWrite?->operation, $parsedWrite?->relation]);
    }

    #[Test]
    public function it_parses_no_write_from_a_read(): void
    {
        $this->assertNull(ParsedWrite::of('select * from "gadget-items"'));
    }
}
