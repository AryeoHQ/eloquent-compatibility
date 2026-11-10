<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PHPStan\Rules\FunctionCallParametersCheck;
use PHPStan\Rules\Methods\CallMethodsRule;
use PHPStan\Rules\Methods\MethodCallCheck;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Tooling\Concerns\GetsFixtures;

/** @extends RuleTestCase<CallMethodsRule> */
#[CoversNothing]
class WrittenByMustNameAnOperationTest extends RuleTestCase
{
    use GetsFixtures;

    protected function getRule(): Rule
    {
        return new CallMethodsRule( // @phpstan-ignore phpstanApi.constructor
            methodCallCheck: self::getContainer()->getByType(MethodCallCheck::class), // @phpstan-ignore phpstanApi.classConstant
            parametersCheck: self::getContainer()->getByType(FunctionCallParametersCheck::class), // @phpstan-ignore phpstanApi.classConstant
        );
    }

    #[Test]
    public function it_fails_when_written_by_takes_a_closure(): void
    {
        $this->analyse(files: [$this->getFixturePath('Definition/WrittenByClosure.php')], expectedErrors: [
            [
                'Parameter #1 $operation of method Support\Database\Eloquent\Compatibility\Definition\Attribute::writtenBy() expects class-string<Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute<covariant Illuminate\Database\Eloquent\Model>>, Closure(): null given.',
                13,
            ],
        ]);
    }

    #[Test]
    public function it_passes_when_written_by_takes_an_operation(): void
    {
        $this->analyse(files: [$this->getFixturePath('Definition/WrittenByOperation.php')], expectedErrors: []);
    }
}
