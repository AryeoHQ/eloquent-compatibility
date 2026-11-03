<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tooling\PhpStan\Rules\Rule;
use Tooling\Rules\Attributes\NodeType;

/**
 * @extends Rule<Class_>
 */
#[NodeType(Class_::class)]
final class ServesCompatibilityMustOnlyBeOnModel extends Rule
{
    /**
     * @param  Class_  $node
     */
    public function shouldHandle(Node $node, Scope $scope): bool
    {
        return ! $node->isAnonymous()
            && $this->inherits($node, [ServesCompatibility::class, Compatible::class])
            && $this->doesNotInherit($node, Model::class);
    }

    /**
     * @param  Class_  $node
     */
    public function handle(Node $node, Scope $scope): void
    {
        if ($this->inherits($node, ServesCompatibility::class)) {
            $this->error(
                message: 'ServesCompatibility must only be on Model.',
                line: $node->name?->getStartLine() ?? $node->getStartLine(),
                identifier: 'compatibility.servesCompatibility.mustOnlyBeOn.model'
            );
        }

        if ($this->inherits($node, Compatible::class)) {
            $this->error(
                message: 'Compatible must only be on Model.',
                line: $node->name?->getStartLine() ?? $node->getStartLine(),
                identifier: 'compatibility.compatible.mustOnlyBeOn.model'
            );
        }
    }
}
