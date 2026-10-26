<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableStateKind;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class DriftQueries
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @return list<DriftFinding>
     */
    public function find(ResolvedMap $resolvedMap, int $limit): array
    {
        return [
            ...$this->previousWithoutCurrent($resolvedMap, $limit),
            ...$this->orphanCurrents($resolvedMap, $limit),
            ...$this->sharedPrimaries($resolvedMap, $limit),
            ...$this->refusedStates($resolvedMap, $limit),
            ...$this->brokenDesignations($resolvedMap, $limit),
        ];
    }

    /**
     * @return list<DriftFinding>
     */
    private function previousWithoutCurrent(ResolvedMap $resolvedMap, int $limit): array
    {
        $previous = $resolvedMap->previousModel;
        $primary = (string) $resolvedMap->primaryCurrentModel->alias;
        $primaryForeignKey = $previous->alias.'.'.SqlFragments::identifier((string) $previous->primaryForeignKey);
        $hidden = array_map(
            fn (UnrepresentableState $state) => 'COALESCE(('.$state->checkCondition.'), false)',
            $resolvedMap->unrepresentableStates(UnrepresentableStateKind::Hide),
        );

        $rows = $this->connection->select(
            'select '.$previous->alias.'.'.SqlFragments::identifier((string) $previous->keyColumn).' as key, '.$primaryForeignKey.' is null as unlinked'
            .' from '.SqlFragments::identifier((string) $previous->table).' '.$previous->alias
            .' left join '.SqlFragments::identifier($resolvedMap->table($primary)).' '.$primary
            .' on '.$primary.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primary)).' = '.$primaryForeignKey
            .' where '.$primary.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primary)).' is null'
            .($hidden === [] ? '' : ' and not ('.implode(' or ', $hidden).')')
            .' order by 1 limit ?',
            [$limit],
        );

        return array_map(fn (object $row) => new DriftFinding(
            $resolvedMap->name,
            DriftCheck::PreviousWithoutCurrent,
            $previous->table.'#'.$row->key,
            $previous->table.'.'.$previous->primaryForeignKey,
            $row->unlinked
                ? 'no '.$resolvedMap->table($primary).' row is linked, and no hidden unrepresentable state covers the record'
                : 'it links a '.$resolvedMap->table($primary).' row that does not exist',
        ), $rows);
    }

    /**
     * @return list<DriftFinding>
     */
    private function orphanCurrents(ResolvedMap $resolvedMap, int $limit): array
    {
        $previous = $resolvedMap->previousModel;
        $primary = (string) $resolvedMap->primaryCurrentModel->alias;
        $primaryKey = $primary.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primary));

        $rows = $this->connection->select(
            'select '.$primaryKey.' as key from '.SqlFragments::identifier($resolvedMap->table($primary)).' '.$primary
            .' where not exists (select 1 from '.SqlFragments::identifier((string) $previous->table).' '.$previous->alias
            .' where '.$previous->alias.'.'.SqlFragments::identifier((string) $previous->primaryForeignKey).' = '.$primaryKey.')'
            .' order by 1 limit ?',
            [$limit],
        );

        return array_map(fn (object $row) => new DriftFinding(
            $resolvedMap->name,
            DriftCheck::OrphanCurrent,
            $resolvedMap->table($primary).'#'.$row->key,
            $previous->table.'.'.$previous->primaryForeignKey,
            'no retained row links it, so the previous model cannot see the record',
        ), $rows);
    }

    /**
     * @return list<DriftFinding>
     */
    private function sharedPrimaries(ResolvedMap $resolvedMap, int $limit): array
    {
        $previous = $resolvedMap->previousModel;
        $primaryForeignKey = SqlFragments::identifier((string) $previous->primaryForeignKey);

        $rows = $this->connection->select(
            'select '.$primaryForeignKey.' as key, count(*) as records from '.SqlFragments::identifier((string) $previous->table)
            .' where '.$primaryForeignKey.' is not null group by 1 having count(*) > 1 order by 1 limit ?',
            [$limit],
        );

        return array_map(fn (object $row) => new DriftFinding(
            $resolvedMap->name,
            DriftCheck::MoreThanOnePrimary,
            $resolvedMap->table((string) $resolvedMap->primaryCurrentModel->alias).'#'.$row->key,
            $previous->table.'.'.$previous->primaryForeignKey,
            "{$row->records} retained rows link the same record",
        ), $rows);
    }

    /**
     * @return list<DriftFinding>
     */
    private function brokenDesignations(ResolvedMap $resolvedMap, int $limit): array
    {
        $previous = $resolvedMap->previousModel;
        $primary = (string) $resolvedMap->primaryCurrentModel->alias;
        $primaryForeignKey = $previous->alias.'.'.SqlFragments::identifier((string) $previous->primaryForeignKey);
        $hidden = array_map(
            fn (UnrepresentableState $state) => 'COALESCE(('.$state->checkCondition.'), false)',
            $resolvedMap->unrepresentableStates(UnrepresentableStateKind::Hide),
        );
        $findings = [];

        foreach ($resolvedMap->designated() as $dependent) {
            $alias = (string) $dependent->alias;
            $table = $resolvedMap->table($alias);
            $key = $alias.'.'.SqlFragments::identifier($resolvedMap->keyColumn($alias));

            $rows = $this->connection->select(
                'select '.$previous->alias.'.'.SqlFragments::identifier((string) $previous->keyColumn).' as key, '.$key.' is null as missing'
                .' from '.SqlFragments::identifier((string) $previous->table).' '.$previous->alias
                .' join '.SqlFragments::identifier($resolvedMap->table($primary)).' '.$primary
                .' on '.$primary.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primary)).' = '.$primaryForeignKey
                .' left join '.SqlFragments::identifier($table).' '.$alias.' on '.$key.' = '.$previous->alias.'.'.SqlFragments::identifier((string) $dependent->designationColumn)
                .' where '.$previous->alias.'.'.SqlFragments::identifier((string) $dependent->designationColumn).' is not null'
                .' and ('.$key.' is null or '.$alias.'.'.SqlFragments::identifier($resolvedMap->primaryForeignKey($dependent)).' is distinct from '.$primaryForeignKey.')'
                .($hidden === [] ? '' : ' and not ('.implode(' or ', $hidden).')')
                .' order by 1 limit ?',
                [$limit],
            );

            foreach ($rows as $row) {
                $findings[] = new DriftFinding(
                    $resolvedMap->name,
                    DriftCheck::Designation,
                    $previous->table.'#'.$row->key,
                    $previous->table.'.'.$dependent->designationColumn,
                    $row->missing
                        ? "it names a {$table} row that does not exist"
                        : "it names a {$table} row of another ".$resolvedMap->table($primary).' row',
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<DriftFinding>
     */
    private function refusedStates(ResolvedMap $resolvedMap, int $limit): array
    {
        $findings = [];

        foreach ($resolvedMap->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->checkedOn === null) {
                continue;
            }

            $alias = $state->checkedOn;
            $table = $resolvedMap->table($alias);

            $rows = $this->connection->select(
                'select '.$alias.'.'.SqlFragments::identifier($resolvedMap->keyColumn($alias)).' as key from '.SqlFragments::identifier($table).' '.$alias
                .' where COALESCE(('.$state->checkCondition.'), false) order by 1 limit ?',
                [$limit],
            );

            foreach ($rows as $row) {
                $findings[] = new DriftFinding(
                    $resolvedMap->name,
                    DriftCheck::RefusedState,
                    $table.'#'.$row->key,
                    $state->name,
                    "the record is in a refused state: {$state->description}",
                );
            }
        }

        return $findings;
    }
}
