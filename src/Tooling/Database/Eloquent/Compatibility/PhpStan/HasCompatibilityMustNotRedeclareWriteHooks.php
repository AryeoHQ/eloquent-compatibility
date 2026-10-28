<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;
use Tooling\PhpStan\Rules\Rule;
use Tooling\Rules\Attributes\NodeType;

/**
 * @extends Rule<Class_>
 */
#[NodeType(Class_::class)]
final class HasCompatibilityMustNotRedeclareWriteHooks extends Rule
{
    private const array METHODS = [
        'setKeysForSaveQuery',
        'getAttributesForInsert',
    ];

    /**
     * @param  Class_  $node
     */
    public function shouldHandle(Node $node, Scope $scope): bool
    {
        return ! $node->isAnonymous()
            && $this->inherits($node, HasCompatibility::class)
            && $this->redeclaredWriteHooks($node) !== [];
    }

    /**
     * @param  Class_  $node
     */
    public function handle(Node $node, Scope $scope): void
    {
        foreach ($this->redeclaredWriteHooks($node) as $method) {
            $this->error(
                message: "HasCompatibility must not redeclare {$method}().",
                line: $node->name?->getStartLine() ?? $node->getStartLine(),
                identifier: 'compatibility.hasCompatibility.mustNotRedeclare.writeHooks'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function redeclaredWriteHooks(Class_ $node): array
    {
        $found = [];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && in_array($stmt->name->toString(), self::METHODS, true)) {
                $found[] = $stmt->name->toString();
            }
        }

        return $found;
    }
}
