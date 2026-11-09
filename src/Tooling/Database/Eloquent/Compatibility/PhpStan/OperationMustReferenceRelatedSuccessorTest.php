<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Tooling\Concerns\GetsFixtures;

/** @extends RuleTestCase<OperationMustReferenceRelatedSuccessor> */
#[CoversClass(OperationMustReferenceRelatedSuccessor::class)]
#[CoversClass(MapRelationsCollector::class)]
#[CoversClass(OperationReferencesCollector::class)]
class OperationMustReferenceRelatedSuccessorTest extends RuleTestCase
{
    use GetsFixtures;

    protected function getRule(): Rule
    {
        return new OperationMustReferenceRelatedSuccessor;
    }

    protected function getCollectors(): array
    {
        return [new MapRelationsCollector, new OperationReferencesCollector];
    }

    #[Test]
    public function it_fails_when_an_operation_references_a_class_its_map_does_not_relate(): void
    {
        $this->analyse(files: $this->withMaps('Operations/WriteOrderShipment.php'), expectedErrors: [
            [
                'Operation Tests\Fixtures\Tooling\Operations\WriteOrderShipment must reference a related successor, and no map that names it relates Tests\Fixtures\Tooling\Operations\Shipment.',
                15,
            ],
        ]);
    }

    #[Test]
    public function it_fails_when_an_initializer_references_a_class_its_map_does_not_relate(): void
    {
        $this->analyse(files: $this->withMaps('Operations/InitializeOrder.php', 'Operations/InitializeLegacyOrder.php'), expectedErrors: [
            [
                'Operation Tests\Fixtures\Tooling\Operations\InitializeOrder must reference a related successor, and no map that names it relates Tests\Fixtures\Tooling\Operations\Invoice.',
                15,
            ],
            [
                'Operation Tests\Fixtures\Tooling\Operations\InitializeLegacyOrder must reference a related successor, and no map that names it relates Tests\Fixtures\Tooling\Operations\Shipment.',
                15,
            ],
        ]);
    }

    #[Test]
    public function it_passes_when_an_operation_references_a_related_successor_or_a_morph_target(): void
    {
        $this->analyse(files: $this->withMaps('Operations/WriteOrderCustomer.php'), expectedErrors: []);
    }

    #[Test]
    public function it_fails_when_an_operation_references_the_root_through_related(): void
    {
        $this->analyse(files: $this->withMaps('Operations/WriteOrderTotal.php'), expectedErrors: [
            [
                'Operation Tests\Fixtures\Tooling\Operations\WriteOrderTotal must reference a related successor, and no map that names it relates Tests\Fixtures\Tooling\Operations\Order.',
                15,
            ],
        ]);
    }

    #[Test]
    public function it_fails_when_an_operation_of_a_delegated_map_references_a_class_the_map_does_not_relate(): void
    {
        $this->analyse(files: $this->withMaps('Operations/WriteInvoiceCustomer.php'), expectedErrors: [
            [
                'Operation Tests\Fixtures\Tooling\Operations\WriteInvoiceCustomer must reference a related successor, and no map that names it relates Tests\Fixtures\Tooling\Operations\Customer.',
                16,
            ],
        ]);
    }

    #[Test]
    public function it_passes_when_one_of_two_maps_that_name_an_operation_relates_the_class(): void
    {
        $this->analyse(files: $this->withMaps('Operations/WriteOrderNote.php'), expectedErrors: []);
    }

    #[Test]
    public function it_passes_when_no_map_names_the_operation(): void
    {
        $this->analyse(files: $this->withMaps('Operations/WriteUnnamedShipment.php'), expectedErrors: []);
    }

    /**
     * @return list<string>
     */
    private function withMaps(string ...$operations): array
    {
        return array_map(
            callback: $this->getFixturePath(...),
            array: [
                'Operations/LegacyOrder.php',
                'Operations/LegacyInvoice.php',
                'Operations/LegacyInvoiceMap.php',
                ...$operations,
            ],
        );
    }
}
