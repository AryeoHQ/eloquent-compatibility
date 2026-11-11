<?php

declare(strict_types=1);

namespace Tooling\Database\Eloquent\Compatibility\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\RuleErrorBuilder;
use Tooling\PhpStan\Rules\Rule;
use Tooling\Rules\Attributes\NodeType;

/**
 * @internal
 *
 * @phpstan-type Declared array{root: bool, operations: array<string, true>, relates: array<string, true>, delegates: array<string, true>}
 *
 * @extends Rule<CollectedDataNode>
 */
#[NodeType(CollectedDataNode::class)]
final class OperationMustReferenceRelatedSuccessor extends Rule
{
    /**
     * @param  CollectedDataNode  $node
     */
    public function handle(Node $node, Scope $scope): void
    {
        $relates = $this->relates($node);

        foreach ($node->get(OperationReferencesCollector::class) as $file => $references) {
            foreach ($references as $reference) {
                if (! array_key_exists(
                    key: $reference['operation'],
                    array: $relates,
                )) {
                    continue;
                }

                foreach ($reference['classes'] as $class) {
                    if (array_key_exists(
                        key: $class,
                        array: $relates[$reference['operation']],
                    )) {
                        continue;
                    }

                    $this->errors->push(
                        RuleErrorBuilder::message("Operation {$reference['operation']} must reference a related successor, and no map that names it relates {$class}.")
                            ->file($file)
                            ->line($reference['line'])
                            ->identifier('compatibility.operation.mustReference.relatedSuccessor')
                            ->build()
                    );
                }
            }
        }
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function relates(CollectedDataNode $node): array
    {
        $functions = $this->functions($node);
        $relates = [];

        foreach ($functions as $function => $declared) {
            if (! $declared['root']) {
                continue;
            }

            $map = $this->map(
                functions: $functions,
                root: $function,
            );

            foreach (array_keys($map['operations']) as $operation) {
                $relates[$operation] = [...$relates[$operation] ?? [], ...$map['relates']];
            }
        }

        return $relates;
    }

    /**
     * @return array<string, Declared>
     */
    private function functions(CollectedDataNode $node): array
    {
        $functions = [];

        foreach ($node->get(MapRelationsCollector::class) as $declarations) {
            foreach ($declarations as $declaration) {
                $declared = $functions[$declaration['function']] ?? ['root' => false, 'operations' => [], 'relates' => [], 'delegates' => []];
                $declared['root'] = $declared['root'] || $declaration['root'];
                $declared[$declaration['kind']] = [...$declared[$declaration['kind']], ...array_fill_keys(
                    keys: $declaration['names'],
                    value: true,
                )];
                $functions[$declaration['function']] = $declared;
            }
        }

        return $functions;
    }

    /**
     * @param  array<string, Declared>  $functions
     * @return array{operations: array<string, true>, relates: array<string, true>}
     */
    private function map(array $functions, string $root): array
    {
        $map = ['operations' => [], 'relates' => []];
        $visited = [];

        for ($pending = [$root]; $pending !== [];) {
            $function = array_shift($pending);

            if (array_key_exists(
                key: $function,
                array: $visited,
            ) || ! array_key_exists(
                key: $function,
                array: $functions,
            )) {
                continue;
            }

            $visited[$function] = true;
            $map['operations'] = [...$map['operations'], ...$functions[$function]['operations']];
            $map['relates'] = [...$map['relates'], ...$functions[$function]['relates']];
            $pending = [...$pending, ...array_keys($functions[$function]['delegates'])];
        }

        return $map;
    }
}
