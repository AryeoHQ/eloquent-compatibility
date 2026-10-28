<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
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
        'fireModelEvent',
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
        $className = $node->namespacedName?->toString();
        $class = $className === null ? null : (new ObjectType($className))->getClassReflection();
        $trait = (new ObjectType(HasCompatibility::class))->getClassReflection();

        if ($class === null || $trait === null) {
            return [];
        }

        $found = [];

        foreach (self::METHODS as $method) {
            $resolved = $class->getNativeReflection()->getMethod($method);
            $marking = $trait->getNativeReflection()->getMethod($method);

            if ($resolved->getFileName() !== $marking->getFileName() || $resolved->getStartLine() !== $marking->getStartLine()) {
                $found[] = $method;
            }
        }

        return $found;
    }
}
