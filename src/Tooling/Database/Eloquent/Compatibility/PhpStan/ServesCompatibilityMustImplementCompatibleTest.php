<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Tooling\Concerns\GetsFixtures;

/** @extends RuleTestCase<ServesCompatibilityMustImplementCompatible> */
#[CoversClass(ServesCompatibilityMustImplementCompatible::class)]
class ServesCompatibilityMustImplementCompatibleTest extends RuleTestCase
{
    use GetsFixtures;

    protected function getRule(): Rule
    {
        return new ServesCompatibilityMustImplementCompatible(self::getContainer()->getByType(ReflectionProvider::class));
    }

    #[Test]
    public function it_passes_when_serves_compatibility_implements_compatible(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ValidModel.php')], []);
    }

    #[Test]
    public function it_passes_when_serves_compatibility_and_compatible_are_inherited(): void
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
    public function it_fails_when_an_anonymous_model_uses_serves_compatibility_without_compatible(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/AnonymousClasses.php')], [
            [
                'ServesCompatibility must implement Compatible.',
                27,
            ],
        ]);
    }

    #[Test]
    public function it_fails_when_serves_compatibility_does_not_implement_compatible(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ServesCompatibilityWithoutCompatible.php')], [
            [
                'ServesCompatibility must implement Compatible.',
                10,
            ],
        ]);
    }
}
