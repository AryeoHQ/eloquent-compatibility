<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableStateKind;
use Support\Database\Eloquent\Compatibility\Schema\Compiler;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Throwable;

final readonly class DriftQueries
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @return list<DriftFinding>
     */
    public function find(ResolvedMap $resolvedMap, int $limit): array
    {
        return [
            ...$this->viewDefinition($resolvedMap),
            ...$this->previousWithoutCurrent($resolvedMap, $limit),
            ...$this->orphanCurrents($resolvedMap, $limit),
            ...$this->sharedPrimaries($resolvedMap, $limit),
            ...$this->refusedStates($resolvedMap, $limit),
            ...$this->brokenDesignations($resolvedMap, $limit),
        ];
    }

    /**
     * Compares the installed compatibility view with the view the map compiles to now. A change to a relation, a
     * scope or an attribute changes the compiled view, and only Installer::recompile() changes the installed one.
     * The compiled view is created as a temporary view and dropped again, so PostgreSQL normalizes both alike.
     *
     * @return list<DriftFinding>
     */
    private function viewDefinition(ResolvedMap $resolvedMap): array
    {
        $view = $resolvedMap->previousModel->view;
        $finding = fn (string $detail) => [new DriftFinding($resolvedMap->name, DriftCheck::ViewDefinition, $view, $view, $detail)];
        $installed = $this->connection->scalar("select to_regclass(quote_ident(current_schema()) || '.' || ?)::text", [SqlFragments::identifier($view)]);

        if (! is_string($installed)) {
            return $finding('the compatibility view is not installed; run Installer::install()');
        }

        $this->connection->beginTransaction();

        try {
            $this->connection->statement('create temporary view compatibility_expected_view as '.(new Compiler($this->connection))->viewQuery($resolvedMap));
            $definitions = $this->connection->selectOne(
                "select pg_get_viewdef(?::regclass, true) as installed, pg_get_viewdef('compatibility_expected_view'::regclass, true) as expected",
                [$installed],
            );
        } catch (Throwable $e) {
            return $finding('the map no longer compiles against the database: '.strtok($e->getMessage(), "\n"));
        } finally {
            $this->connection->rollBack();
        }

        return trim((string) $definitions?->installed) === trim((string) $definitions?->expected)
            ? []
            : $finding('the installed view differs from the view the map compiles to now; run Installer::recompile()');
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
            $designation = $previous->alias.'.'.SqlFragments::identifier((string) $dependent->designationColumn);
            $dependentPrimaryForeignKey = $alias.'.'.SqlFragments::identifier($resolvedMap->primaryForeignKey($dependent));

            $rows = $this->connection->select(
                'select '.$previous->alias.'.'.SqlFragments::identifier((string) $previous->keyColumn).' as key, '.$designation.' is null as empty, '.$key.' is null as missing'
                .' from '.SqlFragments::identifier((string) $previous->table).' '.$previous->alias
                .' join '.SqlFragments::identifier($resolvedMap->table($primary)).' '.$primary
                .' on '.$primary.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primary)).' = '.$primaryForeignKey
                .' left join '.SqlFragments::identifier($table).' '.$alias.' on '.$key.' = '.$designation
                .' where (('.$designation.' is not null and ('.$key.' is null or '.$dependentPrimaryForeignKey.' is distinct from '.$primaryForeignKey.'))'
                .' or ('.$designation.' is null and exists (select 1 from '.SqlFragments::identifier($table).' '.$alias
                .' where '.$dependentPrimaryForeignKey.' = '.$primaryForeignKey.' and ('.$dependent->readableWhen.'))))'
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
                    match (true) {
                        $row->empty => "it names no {$table} row, and the record has a readable one",
                        $row->missing => "it names a {$table} row that does not exist",
                        default => "it names a {$table} row of another ".$resolvedMap->table($primary).' row',
                    },
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
            $rows = $state->predicate === null ? null : $this->predicateRows($resolvedMap, $state, $alias, $limit);

            $rows ??= $this->connection->select(
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

    /**
     * Walks the rows the state's candidates select, by key, through their model, and keeps each one its predicate holds for.
     *
     * @return list<object{key: int|string}>
     */
    private function predicateRows(ResolvedMap $resolvedMap, UnrepresentableState $state, string $alias, int $limit): array
    {
        $class = (string) $resolvedMap->modelOf($alias);
        $model = new $class;

        if ($this->connection instanceof Connection) {
            $model->setConnection($this->connection->getName());
        }

        $query = $model->newQueryWithoutScopes();

        if ($state->candidates !== null) {
            ($state->candidates)($query);
        }

        $rows = [];

        foreach ($query->lazyById(500, $model->getKeyName()) as $row) {
            if (($state->predicate)($row) === true) {
                $rows[] = (object) ['key' => $row->getKey()];

                if (count($rows) >= $limit) {
                    break;
                }
            }
        }

        return $rows;
    }
}
