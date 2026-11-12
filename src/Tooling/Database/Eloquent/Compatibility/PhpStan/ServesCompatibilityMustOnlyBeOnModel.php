<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tooling\PhpStan\Rules\Rule;
use Tooling\Rules\Attributes\NodeType;

/**
 * @internal
 *
 * @extends Rule<Class_>
 */
#[NodeType(Class_::class)]
final class ServesCompatibilityMustOnlyBeOnModel extends Rule
{
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
        $class = $this->subject($node, $scope);

        return $this->inherits($class, [ServesCompatibility::class, Compatible::class])
            && $this->doesNotInherit($class, Model::class);
    }

    /**
     * @param  Class_  $node
     */
    public function handle(Node $node, Scope $scope): void
    {
        $class = $this->subject($node, $scope);

        if ($this->inherits($class, ServesCompatibility::class)) {
            $this->error(
                message: 'ServesCompatibility must only be on Model.',
                line: $node->name?->getStartLine() ?? $node->getStartLine(),
                identifier: 'compatibility.servesCompatibility.mustOnlyBeOn.model'
            );
        }

        if ($this->inherits($class, Compatible::class)) {
            $this->error(
                message: 'Compatible must only be on Model.',
                line: $node->name?->getStartLine() ?? $node->getStartLine(),
                identifier: 'compatibility.compatible.mustOnlyBeOn.model'
            );
        }
    }

    private function subject(Class_ $node, Scope $scope): Class_|ClassReflection
    {
        return $node->isAnonymous() ? $this->reflectionProvider->getAnonymousClassReflection($node, $scope) : $node;
    }
}
