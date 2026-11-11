<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Support\Database\Eloquent\Compatibility\Builder\LegacyBuilder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\Preparer;
use Support\Database\Eloquent\Compatibility\Schema\Statements;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

/**
 * @internal
 */
final class CompileSql extends Command
{
    protected $signature = 'compatibility:sql
        {operation : The operation whose statements to print: prepare, install, update, rollback or retire}
        {model : The class of the legacy model that declares the map}
        {--filters : Also print, for each attribute, where a filter on it runs once the view serves the model}
        {--output= : Write the statements to this file instead of stdout}';

    protected $description = 'Print the SQL statements a compatibility operation runs, running only catalog reads and temporary objects it rolls back.';

    public function handle(Filesystem $files): int
    {
        $model = (string) $this->argument('model');
        $for = (string) $this->argument('operation');
        $file = $this->option('output');

        $statementsFor = match ($for) {
            'prepare' => Statements::prepare(...),
            'install' => Statements::install(...),
            'update' => Statements::update(...),
            'rollback' => Statements::rollback(...),
            'retire' => Statements::retire(...),
            default => null,
        };

        if ($statementsFor === null) {
            return $this->refuse("The operation must be prepare, install, update, rollback or retire, not {$for}.");
        }

        if (! class_exists($model)) {
            return $this->refuse("{$model} is not a class. Name the legacy model by its full class name.");
        }

        try {
            $map = Compatibility::of($model)->resolve();
            $connection = Compatibility::connection($map);
            [$guard, $statements, $server, $filters] = $connection->transaction(fn () => [
                Statements::guard($map, $connection, $for),
                $statementsFor($map, $connection),
                (array) $connection->selectOne("select current_database() as database, current_schema() as schema, current_setting('search_path') as search_path, current_setting('server_version') as version"),
                $this->option('filters') === true ? self::filters(
                    map: $map,
                    connection: $connection,
                ) : [],
            ]);
        } catch (InvalidCompatibilityMap $exception) {
            return $this->refuse($exception->getMessage());
        }

        if ($statements->steps !== []) {
            $externals = $map->externals();

            return $this->refuse("Compatibility::{$for}() reads the computedAs() ".(count($externals) === 1 ? 'attribute ' : 'attributes ').implode(', ', $externals)." of {$map->table} through PHP between its statements, and no printed SQL runs that step. Run Compatibility::{$for}() instead.");
        }

        $comment = fn (string $line) => '-- '.str_replace(
            search: ["\r", "\n"],
            replace: ['\\r', '\\n'],
            subject: $line,
        );
        $header = array_map(
            callback: $comment,
            array: [
                "compatibility:sql {$for} {$model}: the statements Compatibility::{$for}() runs for {$map->table}",
                "Read from database {$server['database']}, schema {$server['schema']} (search path {$server['search_path']}), on PostgreSQL {$server['version']}",
                'Compiled at '.Date::now()->toIso8601String(),
                ...($for === 'install' ? [
                    'Compatibility::install() runs backfill passes in PHP before these statements, and this SQL omits them.',
                    "Running this SQL by hand skips those passes, so it drops every legacy edit that no pass has written to the successors of {$map->table}. Run Compatibility::install() instead.",
                    "Under its lock, Compatibility::install() also inserts in PHP the {$map->table} row of each natively created root, and this SQL omits those inserts.",
                ] : []),
                ...$statements->notes,
                ...array_map(
                    callback: fn (array $lock) => "Locks {$lock[0]} in {$lock[1]}",
                    array: $statements->locks,
                ),
                ...($filters === [] ? [] : ["Once the view serves {$map->table}, a filter on each attribute runs here:", ...$filters]),
            ],
        );
        $terminate = fn (string $statement) => $statement."\n;";
        $body = array_map(
            callback: $terminate,
            array: $guard,
        );

        foreach ($statements->transaction as $index => $statement) {
            $body[] = $terminate($statement);

            foreach ($statements->hooks[$index] ?? [] as [$hook]) {
                $body[] = $comment("{$hook} runs here, inside the transaction");
            }
        }

        $sql = implode(
            separator: "\n",
            array: $header,
        )."\n\nBEGIN;\n\n".implode(
            separator: "\n\n",
            array: $body,
        )."\n\nCOMMIT;\n";

        if ($statements->afterwards !== []) {
            $sql .= "\n-- After the transaction commits, outside any transaction\n\n".implode(
                separator: "\n\n",
                array: array_map(
                    callback: $terminate,
                    array: $statements->afterwards,
                ),
            )."\n";
        }

        if (! is_string($file) || $file === '') {
            $this->output->write($sql, false, OutputStyle::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if ($files->put($file, $sql) !== strlen($sql)) {
            return $this->refuse("Could not write the statements to {$file}.");
        }

        $count = count($guard) + count($statements->all());
        $this->components->info("Wrote {$count} ".($count === 1 ? 'statement' : 'statements')." to {$file}.");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private static function filters(ResolvedMap $map, ConnectionInterface $connection): array
    {
        $viewProven = [
            ...ViewCompiler::proven(
                map: $map,
                connection: $connection,
                writing: true,
            ),
            ...array_map(
                callback: fn (Related $related) => $related->table,
                array: Preparer::atMostOneRelations($map),
            ),
        ];
        $translatedProven = ViewCompiler::proven(
            map: $map,
            connection: $connection,
            writing: false,
        );
        $catalog = new ColumnCatalog(
            connection: $connection,
            throughSearchPath: true,
        );
        $collations = [];
        $collationsOf = function (string $table) use (&$collations, $connection): array {
            return $collations[$table] ??= ViewCompiler::collations(
                connection: $connection,
                table: $table,
                throughSearchPath: true,
            );
        };
        $list = fn (array $items) => count($items) < 2 ? implode(separator: '', array: $items) : implode(
            separator: ', ',
            array: array_slice(
                array: $items,
                offset: 0,
                length: -1,
            ),
        ).' and '.$items[count($items) - 1];
        $lines = [];

        foreach (LegacyBuilder::placements(
            map: $map,
            connection: $connection,
        ) as $name => $placement) {
            $attribute = $map->attributes[$name];
            $owner = $map->owners[$name]->table;
            $column = $attribute->column();
            $runs = match ($placement) {
                'computed' => "{$name}: no SQL filter, because computedAs() computes it in PHP",
                'stored' => "{$name}: the stored column {$owner}.{$column} when the operator can be inverted, otherwise the view",
                'expression' => "{$name}: a rebuilt expression over {$owner}",
                'view' => "{$name}: the view",
            };

            if ($placement === 'computed' || $map->isMorphed($name) || $owner === $map->primary->table) {
                $lines[] = $runs;

                continue;
            }

            $proven = $placement === 'view' ? $viewProven : $translatedProven;
            $path = ViewCompiler::path(
                map: $map,
                owner: $owner,
            );
            $lateral = null;

            foreach ($path as $table) {
                if ($map->relatedAt($table)->morphType === null && ! in_array(
                    needle: $table,
                    haystack: $proven,
                    strict: true,
                )) {
                    $lateral = $table;
                }
            }

            if ($lateral !== null) {
                $lines[] = $runs.", reached through a lateral join on {$lateral}, so no index on {$owner} serves it";

                continue;
            }

            $joins = array_map(
                callback: fn (string $table) => $map->relatedAt($table)->parentTable.'.'.$map->relatedAt($table)->parentKey,
                array: $path,
            );
            $stored = $column === null ? null : $catalog->of($owner)[$column] ?? null;
            $served = $catalog->of($map->table)[$name] ?? null;
            $lines[] = $runs.', reached through plain joins on '.$list($joins).match (true) {
                $placement === 'expression' => '',
                $column === null => ", but the view computes it, so no index on {$owner} serves it",
                $attribute->orFromSuccessor() !== null => ", but the view reads it through orFrom(), so no index on {$owner} serves it",
                $placement === 'view' && ($stored?->type !== $served?->type || ($collationsOf($owner)[$column]['name'] ?? null) !== ($collationsOf($map->table)[$name]['name'] ?? null)) => ", but the view casts {$owner}.{$column} to the legacy type or collation, so no index on {$owner} serves it",
                default => ', so indexes on '.$list([$owner.'.'.$column, ...$joins]).' can serve it',
            };
        }

        return $lines;
    }

    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln($message, OutputStyle::OUTPUT_RAW);

        return self::FAILURE;
    }
}
