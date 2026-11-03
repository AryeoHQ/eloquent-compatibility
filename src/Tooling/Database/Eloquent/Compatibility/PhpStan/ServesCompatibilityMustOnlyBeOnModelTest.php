<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Tooling\Concerns\GetsFixtures;

/** @extends RuleTestCase<ServesCompatibilityMustOnlyBeOnModel> */
#[CoversClass(ServesCompatibilityMustOnlyBeOnModel::class)]
class ServesCompatibilityMustOnlyBeOnModelTest extends RuleTestCase
{
    use GetsFixtures;

    protected function getRule(): Rule
    {
        return new ServesCompatibilityMustOnlyBeOnModel;
    }

    #[Test]
    public function it_passes_when_serves_compatibility_is_on_model(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ValidModel.php')], []);
    }

    #[Test]
    public function it_passes_when_class_neither_uses_serves_compatibility_nor_implements_compatible(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/PlainModel.php')], []);
    }

    #[Test]
    public function it_passes_when_class_is_anonymous(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/AnonymousClasses.php')], []);
    }

    #[Test]
    public function it_fails_when_compatible_is_not_on_model(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/CompatibleOnNonModel.php')], [
            [
                'Compatible must only be on Model.',
                10,
            ],
        ]);
    }

    #[Test]
    public function it_fails_when_serves_compatibility_and_compatible_are_not_on_model(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ServesCompatibilityOnNonModel.php')], [
            [
                'ServesCompatibility must only be on Model.',
                11,
            ],
            [
                'Compatible must only be on Model.',
                11,
            ],
        ]);
    }
}
