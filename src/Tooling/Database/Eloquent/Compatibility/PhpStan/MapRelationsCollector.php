<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Targets;

/**
 * @phpstan-type Declaration array{function: string, root: bool, kind: 'operations'|'relates'|'delegates', names: list<string>}
 *
 * @implements Collector<CallLike, Declaration>
 */
final class MapRelationsCollector implements Collector
{
    use ReadsCallArguments;

    private const array DEFINITIONS = [
        Compatibility::class,
        Successor::class,
        Attribute::class,
        Targets::class,
        Closure::class,
    ];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return null|Declaration
     */
    public function processNode(Node $node, Scope $scope): null|array
    {
        $function = $scope->getFunction();

        if (! $function instanceof MethodReflection || $node->isFirstClassCallable()) {
            return null;
        }

        [$kind, $names] = match (true) {
            $node instanceof MethodCall => $this->declared(
                call: $node,
                scope: $scope,
            ),
            $node instanceof StaticCall => ['delegates', $this->delegated(
                call: $node,
                scope: $scope,
            )],
            default => ['delegates', []],
        };

        if ($names === []) {
            return null;
        }

        return [
            'function' => $function->getDeclaringClass()->getName().'::'.$function->getName(),
            'root' => $function->isStatic()
                && strtolower($function->getName()) === 'compatibility'
                && $function->getDeclaringClass()->implementsInterface(Compatible::class),
            'kind' => $kind,
            'names' => $names,
        ];
    }

    /**
     * @return array{'operations'|'relates', list<string>}
     */
    private function declared(MethodCall $call, Scope $scope): array
    {
        $method = $call->name instanceof Identifier ? $call->name->toLowerString() : null;
        $declaring = $scope->getType($call->var);

        return match (true) {
            $method === 'writtenby' && $this->is(
                class: Attribute::class,
                type: $declaring,
            ) => ['operations', $this->strings(
                argument: $this->argument(
                    call: $call,
                    name: 'operation',
                ),
                scope: $scope,
            )],
            in_array(
                needle: $method,
                haystack: ['successorsinitializedby', 'legacyinitializedby'],
                strict: true,
            ) && $this->is(
                class: Compatibility::class,
                type: $declaring,
            ) => ['operations', $this->strings(
                argument: $this->argument(
                    call: $call,
                    name: 'initializer',
                ),
                scope: $scope,
            )],
            $method === 'related' && $this->is(
                class: Successor::class,
                type: $declaring,
            ) => ['relates', $this->relatedModels(
                relation: $this->argument(
                    call: $call,
                    name: 'relation',
                ),
                scope: $scope,
            )],
            $method === 'as' && $this->is(
                class: Targets::class,
                type: $declaring,
            ) => ['relates', $this->strings(
                argument: $this->argument(
                    call: $call,
                    name: 'target',
                ),
                scope: $scope,
            )],
            default => ['relates', []],
        };
    }

    /**
     * @return list<string>
     */
    private function delegated(StaticCall $call, Scope $scope): array
    {
        if (! $call->class instanceof Name || ! $call->name instanceof Identifier) {
            return [];
        }

        $method = $scope->getMethodReflection(
            typeWithMethod: $scope->resolveTypeByName($call->class),
            methodName: $call->name->toString(),
        );

        if ($method === null || ! $this->takesDefinitions($method)) {
            return [];
        }

        return [$method->getDeclaringClass()->getName().'::'.$method->getName()];
    }

    private function takesDefinitions(ExtendedMethodReflection $method): bool
    {
        foreach ($method->getVariants() as $variant) {
            $types = [$variant->getReturnType(), ...array_map(
                callback: fn (ParameterReflection $parameter) => $parameter->getType(),
                array: $variant->getParameters(),
            )];

            foreach ($types as $type) {
                foreach ($type->getReferencedClasses() as $class) {
                    if (in_array(
                        needle: $class,
                        haystack: self::DEFINITIONS,
                        strict: true,
                    )) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function relatedModels(null|Expr $relation, Scope $scope): array
    {
        $type = $relation === null ? null : $scope->getType($relation);

        if ($type === null || ! $type->isCallable()->yes()) {
            return [];
        }

        $models = [];

        foreach ($type->getCallableParametersAcceptors($scope) as $acceptor) {
            $models = [...$models, ...$acceptor->getReturnType()->getTemplateType(
                ancestorClassName: Relation::class,
                templateTypeName: 'TRelatedModel',
            )->getObjectClassNames()];
        }

        return array_values(array_unique($models));
    }
}
