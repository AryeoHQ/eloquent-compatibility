<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\Statements;

final class CompileSql extends Command
{
    protected $signature = 'compatibility:sql
        {operation : The operation whose statements to print: prepare, install, update, rollback or retire}
        {model : The class of the legacy model that declares the map}
        {--output= : Write the statements to this file instead of stdout}';

    protected $description = 'Print the SQL statements a compatibility operation runs, running only catalog reads.';

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
            [$guard, $statements, $server] = $connection->transaction(fn () => [
                Statements::guard($map, $connection, $for),
                $statementsFor($map, $connection),
                (array) $connection->selectOne("select current_database() as database, current_schema() as schema, current_setting('search_path') as search_path, current_setting('server_version') as version"),
            ]);
        } catch (InvalidCompatibilityMap $exception) {
            return $this->refuse($exception->getMessage());
        }

        if ($statements->steps !== []) {
            $externals = $map->externals();

            return $this->refuse("Compatibility::{$for}() reads the external() ".(count($externals) === 1 ? 'attribute ' : 'attributes ').implode(', ', $externals)." of {$map->table} through PHP between its statements, and no printed SQL runs that step. Run Compatibility::{$for}() instead.");
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
                ...$statements->notes,
                ...array_map(
                    callback: fn (array $lock) => "Locks {$lock[0]} in {$lock[1]}",
                    array: $statements->locks,
                ),
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

    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln($message, OutputStyle::OUTPUT_RAW);

        return self::FAILURE;
    }
}
