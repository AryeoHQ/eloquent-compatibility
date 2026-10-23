<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Mapping\Declaration;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableStateKind;
use Support\Database\Eloquent\Compatibility\Schema\Sql;

final readonly class Divergence
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @return list<Finding>
     */
    public function find(Declaration $declaration, int $limit): array
    {
        return [
            ...$this->previousWithoutCurrent($declaration, $limit),
            ...$this->orphanCurrents($declaration, $limit),
            ...$this->sharedPrimaries($declaration, $limit),
            ...$this->refusedStates($declaration, $limit),
            ...$this->brokenDesignations($declaration, $limit),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function previousWithoutCurrent(Declaration $declaration, int $limit): array
    {
        $previous = $declaration->previousModel;
        $primary = (string) $declaration->primaryCurrentModel->alias;
        $primaryForeignKey = $previous->alias.'.'.Sql::id((string) $previous->primaryForeignKey);
        $hidden = array_map(
            fn (UnrepresentableState $state) => 'COALESCE(('.$state->condition.'), false)',
            $declaration->unrepresentableStates(UnrepresentableStateKind::Hide),
        );

        $rows = $this->connection->select(
            'select '.$previous->alias.'.'.Sql::id((string) $previous->key).' as key, '.$primaryForeignKey.' is null as unlinked'
            .' from '.Sql::id((string) $previous->table).' '.$previous->alias
            .' left join '.Sql::id($declaration->table($primary)).' '.$primary
            .' on '.$primary.'.'.Sql::id($declaration->key($primary)).' = '.$primaryForeignKey
            .' where '.$primary.'.'.Sql::id($declaration->key($primary)).' is null'
            .($hidden === [] ? '' : ' and not ('.implode(' or ', $hidden).')')
            .' order by 1 limit ?',
            [$limit],
        );

        return array_map(fn (object $row) => new Finding(
            $declaration->name,
            'previous without current',
            $previous->table.'#'.$row->key,
            $previous->table.'.'.$previous->primaryForeignKey,
            $row->unlinked
                ? 'no '.$declaration->table($primary).' row is linked, and no hidden unrepresentable state covers the record'
                : 'it links a '.$declaration->table($primary).' row that does not exist',
        ), $rows);
    }

    /**
     * @return list<Finding>
     */
    private function orphanCurrents(Declaration $declaration, int $limit): array
    {
        $previous = $declaration->previousModel;
        $primary = (string) $declaration->primaryCurrentModel->alias;
        $primaryKey = $primary.'.'.Sql::id($declaration->key($primary));

        $rows = $this->connection->select(
            'select '.$primaryKey.' as key from '.Sql::id($declaration->table($primary)).' '.$primary
            .' where not exists (select 1 from '.Sql::id((string) $previous->table).' '.$previous->alias
            .' where '.$previous->alias.'.'.Sql::id((string) $previous->primaryForeignKey).' = '.$primaryKey.')'
            .' order by 1 limit ?',
            [$limit],
        );

        return array_map(fn (object $row) => new Finding(
            $declaration->name,
            'orphan current',
            $declaration->table($primary).'#'.$row->key,
            $previous->table.'.'.$previous->primaryForeignKey,
            'no retained row links it, so the previous model cannot see the record',
        ), $rows);
    }

    /**
     * @return list<Finding>
     */
    private function sharedPrimaries(Declaration $declaration, int $limit): array
    {
        $previous = $declaration->previousModel;
        $primaryForeignKey = Sql::id((string) $previous->primaryForeignKey);

        $rows = $this->connection->select(
            'select '.$primaryForeignKey.' as key, count(*) as records from '.Sql::id((string) $previous->table)
            .' where '.$primaryForeignKey.' is not null group by 1 having count(*) > 1 order by 1 limit ?',
            [$limit],
        );

        return array_map(fn (object $row) => new Finding(
            $declaration->name,
            'more than one primary',
            $declaration->table((string) $declaration->primaryCurrentModel->alias).'#'.$row->key,
            $previous->table.'.'.$previous->primaryForeignKey,
            "{$row->records} retained rows link the same record",
        ), $rows);
    }

    /**
     * @return list<Finding>
     */
    private function brokenDesignations(Declaration $declaration, int $limit): array
    {
        $previous = $declaration->previousModel;
        $primary = (string) $declaration->primaryCurrentModel->alias;
        $primaryForeignKey = $previous->alias.'.'.Sql::id((string) $previous->primaryForeignKey);
        $hidden = array_map(
            fn (UnrepresentableState $state) => 'COALESCE(('.$state->condition.'), false)',
            $declaration->unrepresentableStates(UnrepresentableStateKind::Hide),
        );
        $findings = [];

        foreach ($declaration->designated() as $dependent) {
            $alias = (string) $dependent->alias;
            $table = $declaration->table($alias);
            $key = $alias.'.'.Sql::id($declaration->key($alias));

            $rows = $this->connection->select(
                'select '.$previous->alias.'.'.Sql::id((string) $previous->key).' as key, '.$key.' is null as missing'
                .' from '.Sql::id((string) $previous->table).' '.$previous->alias
                .' join '.Sql::id($declaration->table($primary)).' '.$primary
                .' on '.$primary.'.'.Sql::id($declaration->key($primary)).' = '.$primaryForeignKey
                .' left join '.Sql::id($table).' '.$alias.' on '.$key.' = '.$previous->alias.'.'.Sql::id((string) $dependent->designation)
                .' where '.$previous->alias.'.'.Sql::id((string) $dependent->designation).' is not null'
                .' and ('.$key.' is null or '.$alias.'.'.Sql::id($declaration->primaryForeignKey($dependent)).' is distinct from '.$primaryForeignKey.')'
                .($hidden === [] ? '' : ' and not ('.implode(' or ', $hidden).')')
                .' order by 1 limit ?',
                [$limit],
            );

            foreach ($rows as $row) {
                $findings[] = new Finding(
                    $declaration->name,
                    'designation',
                    $previous->table.'#'.$row->key,
                    $previous->table.'.'.$dependent->designation,
                    $row->missing
                        ? "it names a {$table} row that does not exist"
                        : "it names a {$table} row of another ".$declaration->table($primary).' row',
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function refusedStates(Declaration $declaration, int $limit): array
    {
        $findings = [];

        foreach ($declaration->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->alias === null) {
                continue;
            }

            $alias = $state->alias;
            $table = $declaration->table($alias);

            $rows = $this->connection->select(
                'select '.$alias.'.'.Sql::id($declaration->key($alias)).' as key from '.Sql::id($table).' '.$alias
                .' where COALESCE(('.$state->condition.'), false) order by 1 limit ?',
                [$limit],
            );

            foreach ($rows as $row) {
                $findings[] = new Finding(
                    $declaration->name,
                    'refused state',
                    $table.'#'.$row->key,
                    $state->name,
                    "the record is in a refused state: {$state->state}",
                );
            }
        }

        return $findings;
    }
}
