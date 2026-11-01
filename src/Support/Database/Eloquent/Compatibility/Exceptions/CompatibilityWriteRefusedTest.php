<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\TestCase;

#[CoversClass(CompatibilityWriteRefused::class)]
final class CompatibilityWriteRefusedTest extends TestCase
{
    #[Test]
    public function it_states_what_it_refused_why_and_what_to_do_instead(): void
    {
        $previous = new RuntimeException('The database said no.');

        $refusal = new CompatibilityWriteRefused('Refused a change to street', 'A widget has one street.', 'Change the place instead.', $previous);

        $this->assertSame('Refused a change to street. Why: A widget has one street. Instead: Change the place instead.', $refusal->getMessage());
        $this->assertSame(['Refused a change to street', 'A widget has one street.', 'Change the place instead.'], [$refusal->refused, $refusal->because, $refusal->instead]);
        $this->assertSame($previous, $refusal->getPrevious());
        $this->assertSame(0, $refusal->getCode());
        $this->assertInstanceOf(CompatibilityException::class, $refusal);
    }

    #[Test]
    public function it_names_the_read_only_attribute_and_the_record(): void
    {
        $refusal = CompatibilityWriteRefused::readOnly('widgets', 'widgets', 'uuid', 7, 'A widget keeps its public id.');

        $this->assertSame(["Compatibility map 'widgets' refused a change to widgets.uuid on record 7", 'A widget keeps its public id.', 'Leave uuid unchanged in writes through the previous model.'], [$refusal->refused, $refusal->because, $refusal->instead]);
    }

    #[Test]
    public function it_names_a_null_on_a_new_record_or_an_existing_one(): void
    {
        $this->assertSame("Compatibility map 'widgets' refused a null widgets.title on a new record", CompatibilityWriteRefused::nullValue('widgets', 'widgets', 'title', null, 'retained_widgets')->refused);
        $this->assertSame("Compatibility map 'widgets' refused a null widgets.title on record 7", CompatibilityWriteRefused::nullValue('widgets', 'widgets', 'title', 7, 'retained_widgets')->refused);
    }

    #[Test]
    public function it_names_each_write_the_router_cannot_split(): void
    {
        $this->assertSame("Compatibility map 'widgets' refused a write of colour, size through widgets", CompatibilityWriteRefused::unknownAttributes('widgets', 'widgets', ['colour', 'size'])->refused);
        $this->assertSame("Compatibility map 'widgets' refused a raw SQL value for widgets.title", CompatibilityWriteRefused::rawExpression('widgets', 'widgets', 'title')->refused);
        $this->assertSame("Compatibility map 'widgets' refused upsert() on widgets", CompatibilityWriteRefused::unsupported('widgets', 'widgets', 'upsert')->refused);
    }

    #[Test]
    public function it_names_the_missing_row_and_the_check_that_finds_it(): void
    {
        $refusal = CompatibilityWriteRefused::missingRow('widgets', 'widgets', 7, 'gadgets');

        $this->assertSame("Compatibility map 'widgets' refused a write to widgets record 7: its gadgets row is missing", $refusal->refused);
        $this->assertSame('Run php artisan compatibility:check --map=widgets and repair the record it names.', $refusal->instead);
    }

    #[Test]
    public function it_blames_the_current_model_listener_that_vetoed_its_save(): void
    {
        $gadget = new Gadget(['id' => '00000000-0000-4000-8000-000000000001']);

        $refusal = CompatibilityWriteRefused::byCurrentListener('widgets', $gadget);

        $this->assertSame("Compatibility map 'widgets' refused this write, because a listener of Gadget #00000000-0000-4000-8000-000000000001 vetoed its save", $refusal->refused);
        $this->assertSame('Make the write satisfy the rules of Gadget.', $refusal->instead);
    }

    #[Test]
    public function it_blames_the_mirrored_listener_that_returned_false(): void
    {
        $gadget = new Gadget(['id' => '00000000-0000-4000-8000-000000000001']);

        $refusal = CompatibilityWriteRefused::byMirroredListener('widgets', $gadget, ModelEvent::Updating);

        $this->assertSame("Compatibility map 'widgets' refused this write, because a 'updating' listener of Gadget #00000000-0000-4000-8000-000000000001 returned false", $refusal->refused);
        $this->assertSame("The write changed Gadget #00000000-0000-4000-8000-000000000001 through the other side, and every listener of that side runs for it, so that side's rules refused the change.", $refusal->because);
        $this->assertSame("Make the write satisfy the rules of Gadget, or exclude the listener in the map's event mirror if it must not run for writes through the other side.", $refusal->instead);
        $this->assertNull($refusal->getPrevious());
        $this->assertStringContainsString("a 'creating' listener of Gadget (new) returned false", CompatibilityWriteRefused::byMirroredListener('widgets', new Gadget, ModelEvent::Creating)->getMessage());
    }

    #[Test]
    public function it_names_the_columns_a_mirrored_listener_changed(): void
    {
        $gadget = new Gadget(['id' => '00000000-0000-4000-8000-000000000001']);

        $refusal = CompatibilityWriteRefused::byMirroredChange('widgets', $gadget, ModelEvent::Updated, ['name', 'price_cents']);

        $this->assertSame("Compatibility map 'widgets' refused this write, because a 'updated' listener of Gadget #00000000-0000-4000-8000-000000000001 changed name, price_cents", $refusal->refused);
        $this->assertSame('Mirrored events fire after the write through the other side has reached the database, so a change a listener makes to the mirrored record cannot be stored.', $refusal->because);
        $this->assertStringContainsString("a 'created' listener of Gadget (new) changed name.", CompatibilityWriteRefused::byMirroredChange('widgets', new Gadget, ModelEvent::Created, ['name'])->getMessage());
    }
}
