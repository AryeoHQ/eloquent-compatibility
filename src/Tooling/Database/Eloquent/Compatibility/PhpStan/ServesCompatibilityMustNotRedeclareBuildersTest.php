<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Tooling\Concerns\GetsFixtures;

/** @extends RuleTestCase<ServesCompatibilityMustNotRedeclareBuilders> */
#[CoversClass(ServesCompatibilityMustNotRedeclareBuilders::class)]
class ServesCompatibilityMustNotRedeclareBuildersTest extends RuleTestCase
{
    use GetsFixtures;

    protected function getRule(): Rule
    {
        return new ServesCompatibilityMustNotRedeclareBuilders;
    }

    #[Test]
    public function it_passes_when_serves_compatibility_keeps_its_builders(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ValidModel.php')], []);
    }

    #[Test]
    public function it_passes_when_a_subclass_inherits_serves_compatibility_without_redeclaring_a_builder(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/InheritedModel.php')], []);
    }

    #[Test]
    public function it_passes_when_a_class_takes_serves_compatibility_through_another_trait(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ComposedModel.php')], []);
    }

    #[Test]
    public function it_passes_when_class_does_not_use_serves_compatibility(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/PlainModel.php')], []);
    }

    #[Test]
    public function it_passes_when_class_is_anonymous(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/AnonymousClasses.php')], []);
    }

    #[Test]
    public function it_fails_when_serves_compatibility_redeclares_its_builders(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/RedeclaresBuilders.php')], [
            [
                'ServesCompatibility must not redeclare newEloquentBuilder().',
                14,
            ],
            [
                'ServesCompatibility must not redeclare newBaseQueryBuilder().',
                14,
            ],
            [
                'ServesCompatibility must not redeclare setKeysForSaveQuery().',
                14,
            ],
            [
                'ServesCompatibility must not redeclare getAttributesForInsert().',
                14,
            ],
        ]);
    }

    #[Test]
    public function it_fails_when_a_subclass_of_serves_compatibility_redeclares_a_builder(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/SubclassRedeclaresBuilder.php')], [
            [
                'ServesCompatibility must not redeclare getAttributesForInsert().',
                7,
            ],
        ]);
    }

    #[Test]
    public function it_fails_when_a_subclass_of_serves_compatibility_imports_a_builder_through_another_trait(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/SubclassImportsBuilder.php')], [
            [
                'ServesCompatibility must not redeclare newEloquentBuilder().',
                7,
            ],
        ]);
    }
}
