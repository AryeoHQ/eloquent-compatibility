<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(AliasedTable::class)]
final class AliasedTableTest extends TestCase
{
    #[Test]
    public function it_carries_the_table_and_key_it_was_given(): void
    {
        $aliasedTable = new AliasedTable('gadget_places', 'gadget_id');

        $this->assertSame(['table' => 'gadget_places', 'key' => 'gadget_id'], get_object_vars($aliasedTable));
    }
}
