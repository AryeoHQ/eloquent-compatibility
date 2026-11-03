<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tooling\PhpStan\Rules\Rule;
use Tooling\Rules\Attributes\NodeType;

/**
 * @extends Rule<Class_>
 */
#[NodeType(Class_::class)]
final class ServesCompatibilityMustNotRedeclareBuilders extends Rule
{
    private const array METHODS = [
        'newEloquentBuilder',
        'newBaseQueryBuilder',
        'setKeysForSaveQuery',
        'getAttributesForInsert',
    ];

    /**
     * @param  Class_  $node
     */
    public function shouldHandle(Node $node, Scope $scope): bool
    {
        return ! $node->isAnonymous()
            && $this->inherits($node, ServesCompatibility::class)
            && $this->redeclared($node) !== [];
    }

    /**
     * @param  Class_  $node
     */
    public function handle(Node $node, Scope $scope): void
    {
        foreach ($this->redeclared($node) as $method) {
            $this->error(
                message: "ServesCompatibility must not redeclare {$method}().",
                line: $node->name?->getStartLine() ?? $node->getStartLine(),
                identifier: 'compatibility.servesCompatibility.mustNotRedeclare.builders'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function redeclared(Class_ $node): array
    {
        $className = $node->namespacedName?->toString();
        $class = $className === null ? null : (new ObjectType($className))->getClassReflection();
        $trait = (new ObjectType(ServesCompatibility::class))->getClassReflection();

        if ($class === null || $trait === null) {
            return [];
        }

        $found = [];

        foreach (self::METHODS as $method) {
            $resolved = $class->getNativeReflection()->getMethod($method);
            $declared = $trait->getNativeReflection()->getMethod($method);

            if ($resolved->getFileName() !== $declared->getFileName() || $resolved->getStartLine() !== $declared->getStartLine()) {
                $found[] = $method;
            }
        }

        return $found;
    }
}
