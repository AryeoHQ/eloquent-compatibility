<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(TriggerFunction::class)]
final class TriggerFunctionTest extends TestCase
{
    #[Test]
    public function it_names_the_suffix_of_each_trigger_function_every_map_compiles(): void
    {
        $this->assertSame([
            'Insert' => 'insert',
            'Update' => 'update',
            'Delete' => 'delete',
            'PrimaryInsert' => 'primary_insert',
            'PrimaryDelete' => 'primary_delete',
        ], array_column(TriggerFunction::cases(), 'value', 'name'));
    }
}
