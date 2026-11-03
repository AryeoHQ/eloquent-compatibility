<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Tooling\Concerns\GetsFixtures;

/** @extends RuleTestCase<CompatibleMustUseServesCompatibility> */
#[CoversClass(CompatibleMustUseServesCompatibility::class)]
class CompatibleMustUseServesCompatibilityTest extends RuleTestCase
{
    use GetsFixtures;

    protected function getRule(): Rule
    {
        return new CompatibleMustUseServesCompatibility(self::getContainer()->getByType(ReflectionProvider::class));
    }

    #[Test]
    public function it_passes_when_compatible_uses_serves_compatibility(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ValidModel.php')], []);
    }

    #[Test]
    public function it_passes_when_compatible_and_serves_compatibility_are_inherited(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/InheritedModel.php')], []);
    }

    #[Test]
    public function it_passes_when_a_class_takes_serves_compatibility_through_another_trait(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ComposedModel.php')], []);
    }

    #[Test]
    public function it_passes_when_class_does_not_implement_compatible(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/PlainModel.php')], []);
    }

    #[Test]
    public function it_passes_when_compatible_is_not_on_model(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/CompatibleOnNonModel.php')], []);
    }

    #[Test]
    public function it_fails_when_an_anonymous_model_implements_compatible_without_serves_compatibility(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/AnonymousClasses.php')], [
            [
                'Compatible must use ServesCompatibility.',
                20,
            ],
        ]);
    }

    #[Test]
    public function it_fails_when_compatible_does_not_use_serves_compatibility(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/CompatibleWithoutServesCompatibility.php')], [
            [
                'Compatible must use ServesCompatibility.',
                11,
            ],
        ]);
    }
}
