<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Tooling\Concerns\GetsFixtures;

/** @extends RuleTestCase<HasCompatibilityMustNotRedeclareWriteHooks> */
#[CoversClass(HasCompatibilityMustNotRedeclareWriteHooks::class)]
class HasCompatibilityMustNotRedeclareWriteHooksTest extends RuleTestCase
{
    use GetsFixtures;

    protected function getRule(): Rule
    {
        return new HasCompatibilityMustNotRedeclareWriteHooks;
    }

    #[Test]
    public function it_passes_when_has_compatibility_keeps_its_write_hooks(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/ValidModel.php')], []);
    }

    #[Test]
    public function it_passes_when_a_subclass_inherits_has_compatibility_without_redeclaring_a_write_hook(): void
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
    public function it_fails_when_has_compatibility_redeclares_write_hooks(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/RedeclaresWriteHooks.php')], [
            [
                'HasCompatibility must not redeclare setKeysForSaveQuery().',
                12,
            ],
            [
                'HasCompatibility must not redeclare getAttributesForInsert().',
                12,
            ],
        ]);
    }

    #[Test]
    public function it_fails_when_a_subclass_of_has_compatibility_redeclares_a_write_hook(): void
    {
        $this->analyse([$this->getFixturePath('Compatibility/SubclassRedeclaresWriteHook.php')], [
            [
                'HasCompatibility must not redeclare getAttributesForInsert().',
                7,
            ],
        ]);
    }
}
