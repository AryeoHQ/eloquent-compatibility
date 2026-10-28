<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(SqlState::class)]
final class SqlStateTest extends TestCase
{
    #[Test]
    public function it_names_the_refusal_code_and_the_serialization_failure_code(): void
    {
        $this->assertSame(['Refused' => 'CM001', 'SerializationFailure' => '40001'], array_column(SqlState::cases(), 'value', 'name'));
    }
}
