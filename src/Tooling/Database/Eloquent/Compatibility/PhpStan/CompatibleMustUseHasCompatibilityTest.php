<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Tooling\Concerns\GetsFixtures;

/** @extends RuleTestCase<CompatibleMustUseHasCompatibility> */
#[CoversClass(CompatibleMustUseHasCompatibility::class)]
class CompatibleMustUseHasCompatibilityTest extends RuleTestCase
{
    use GetsFixtures;

    protected function getRule(): Rule
    {
        return new CompatibleMustUseHasCompatibility;
    }

    #[Test]
    public function it_passes_when_compatible_uses_has_compatibility(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ValidModel.php')], []);
    }

    #[Test]
    public function it_passes_when_compatible_and_has_compatibility_are_inherited(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/InheritedModel.php')], []);
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
    public function it_passes_when_class_is_anonymous(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/AnonymousClasses.php')], []);
    }

    #[Test]
    public function it_fails_when_compatible_does_not_use_has_compatibility(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/CompatibleWithoutHasCompatibility.php')], [
            [
                'Compatible must use HasCompatibility.',
                10,
            ],
        ]);
    }
}
