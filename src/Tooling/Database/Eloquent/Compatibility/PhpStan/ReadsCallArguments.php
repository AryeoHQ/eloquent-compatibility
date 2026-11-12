<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * @internal
 */
trait ReadsCallArguments
{
    /**
     * @return list<string>
     */
    private function strings(null|Expr $argument, Scope $scope): array
    {
        if ($argument === null) {
            return [];
        }

        return array_map(
            callback: fn (ConstantStringType $string) => $string->getValue(),
            array: $scope->getType($argument)->getConstantStrings(),
        );
    }

    private function argument(MethodCall $call, string $name): null|Expr
    {
        foreach ($call->getArgs() as $index => $argument) {
            if ($argument->name?->toString() === $name || ($argument->name === null && ! $argument->unpack && $index === 0)) {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * @param  class-string  $class
     */
    private function is(string $class, Type $type): bool
    {
        return new ObjectType($class)->isSuperTypeOf($type)->yes();
    }
}
