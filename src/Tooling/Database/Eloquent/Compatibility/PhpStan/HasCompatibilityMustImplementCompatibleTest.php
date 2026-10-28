<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Tooling\Concerns\GetsFixtures;

/** @extends RuleTestCase<HasCompatibilityMustImplementCompatible> */
#[CoversClass(HasCompatibilityMustImplementCompatible::class)]
class HasCompatibilityMustImplementCompatibleTest extends RuleTestCase
{
    use GetsFixtures;

    protected function getRule(): Rule
    {
        return new HasCompatibilityMustImplementCompatible;
    }

    #[Test]
    public function it_passes_when_has_compatibility_implements_compatible(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ValidModel.php')], []);
    }

    #[Test]
    public function it_passes_when_has_compatibility_and_compatible_are_inherited(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/InheritedModel.php')], []);
    }

    #[Test]
    public function it_passes_when_class_does_not_use_has_compatibility(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/PlainModel.php')], []);
    }

    #[Test]
    public function it_passes_when_class_is_anonymous(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/AnonymousClasses.php')], []);
    }

    #[Test]
    public function it_fails_when_has_compatibility_does_not_implement_compatible(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/HasCompatibilityWithoutCompatible.php')], [
            [
                'HasCompatibility must implement Compatible.',
                10,
            ],
        ]);
    }
}
