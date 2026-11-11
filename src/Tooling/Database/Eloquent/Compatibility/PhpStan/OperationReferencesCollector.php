<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use Support\Database\Eloquent\Compatibility\Contracts\InitializesLegacy;
use Support\Database\Eloquent\Compatibility\Contracts\InitializesSuccessors;
use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @internal
 *
 * @phpstan-type Reference array{operation: string, classes: list<string>, line: int}
 *
 * @implements Collector<MethodCall, Reference>
 */
final class OperationReferencesCollector implements Collector
{
    use ReadsCallArguments;

    private const array OPERATIONS = [
        WritesAttribute::class,
        InitializesSuccessors::class,
        InitializesLegacy::class,
    ];

    private const array METHODS = [
        'related',
        'relatedornew',
        'deleterelated',
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param  MethodCall  $node
     * @return null|Reference
     */
    public function processNode(Node $node, Scope $scope): null|array
    {
        $operation = $scope->getClassReflection();

        if ($operation === null
            || $node->isFirstClassCallable()
            || ! $node->name instanceof Identifier
            || ! in_array(
                needle: $node->name->toLowerString(),
                haystack: self::METHODS,
                strict: true,
            )
            || ! $this->is(
                class: Successors::class,
                type: $scope->getType($node->var),
            )
            || array_filter(
                array: self::OPERATIONS,
                callback: $operation->implementsInterface(...),
            ) === []) {
            return null;
        }

        $classes = $this->strings(
            argument: $this->argument(
                call: $node,
                name: 'class',
            ),
            scope: $scope,
        );

        if ($classes === []) {
            return null;
        }

        return [
            'operation' => $operation->getName(),
            'classes' => $classes,
            'line' => $node->name->getStartLine(),
        ];
    }
}
