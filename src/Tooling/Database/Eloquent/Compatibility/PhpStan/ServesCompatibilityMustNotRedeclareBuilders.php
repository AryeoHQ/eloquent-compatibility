<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tooling\PhpStan\Rules\Rule;
use Tooling\Rules\Attributes\NodeType;

/**
 * @internal
 *
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

    private readonly ReflectionProvider $reflectionProvider;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reflectionProvider = $reflectionProvider;
    }

    /**
     * @param  Class_  $node
     */
    public function shouldHandle(Node $node, Scope $scope): bool
    {
        return $this->inherits($this->subject($node, $scope), ServesCompatibility::class)
            && $this->redeclared($node, $scope) !== [];
    }

    /**
     * @param  Class_  $node
     */
    public function handle(Node $node, Scope $scope): void
    {
        foreach ($this->redeclared($node, $scope) as $method) {
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
    private function redeclared(Class_ $node, Scope $scope): array
    {
        $subject = $this->subject($node, $scope);
        $className = $subject instanceof ClassReflection ? null : $subject->namespacedName?->toString();
        $class = match (true) {
            $subject instanceof ClassReflection => $subject,
            $className !== null && $this->reflectionProvider->hasClass($className) => $this->reflectionProvider->getClass($className),
            default => null,
        };

        if ($class === null || ! $this->reflectionProvider->hasClass(ServesCompatibility::class)) {
            return [];
        }

        $trait = $this->reflectionProvider->getClass(ServesCompatibility::class);
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

    private function subject(Class_ $node, Scope $scope): Class_|ClassReflection
    {
        return $node->isAnonymous() ? $this->reflectionProvider->getAnonymousClassReflection($node, $scope) : $node;
    }
}
