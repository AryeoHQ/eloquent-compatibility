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
        {model : The class of the legacy model that declares the map}
        {--for=install : Print the statements of prepare, install, recompile or uninstall}
        {--output= : Write the statements to this file instead of stdout}';

    protected $description = 'Print the SQL statements a compatibility operation runs, running only catalog reads.';

    public function handle(Filesystem $files): int
    {
        $model = (string) $this->argument('model');
        $for = (string) $this->option('for');
        $file = $this->option('output');

        $statementsFor = match ($for) {
            'prepare' => Statements::prepare(...),
            'install' => Statements::install(...),
            'recompile' => Statements::recompile(...),
            'uninstall' => Statements::uninstall(...),
            default => null,
        };

        if ($statementsFor === null) {
            return $this->refuse("--for must be prepare, install, recompile or uninstall, not {$for}.");
        }

        if (! class_exists($model)) {
            return $this->refuse("{$model} is not a class. Name the legacy model by its full class name.");
        }

        try {
            $map = Compatibility::of($model)->resolve();
            $connection = Compatibility::connection($map);
            [$statements, $server] = $connection->transaction(fn () => [
                $statementsFor($map, $connection),
                (array) $connection->selectOne("select current_database() as database, current_schema() as schema, current_setting('search_path') as search_path, current_setting('server_version') as version"),
            ]);
        } catch (InvalidCompatibilityMap $exception) {
            return $this->refuse($exception->getMessage());
        }

        $header = array_map(fn (string $line) => str_replace(["\r", "\n"], ['\\r', '\\n'], $line), [
            "-- compatibility:sql {$model} --for={$for}: the statements Compatibility::{$for}() runs for {$map->table}",
            "-- Read from database {$server['database']}, schema {$server['schema']} (search path {$server['search_path']}), on PostgreSQL {$server['version']}",
            '-- Compiled at '.Date::now()->toIso8601String(),
        ]);
        $terminate = fn (string $statement) => $statement."\n;";
        $sql = implode("\n", $header)."\n\nBEGIN;\n\n".implode("\n\n", array_map($terminate, $statements->transaction))."\n\nCOMMIT;\n";

        if ($statements->afterwards !== []) {
            $sql .= "\n-- After the transaction commits, outside any transaction\n\n".implode("\n\n", array_map($terminate, $statements->afterwards))."\n";
        }

        if (! is_string($file) || $file === '') {
            $this->output->write($sql, false, OutputStyle::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if ($files->put($file, $sql) !== strlen($sql)) {
            return $this->refuse("Could not write the statements to {$file}.");
        }

        $count = count($statements->all());
        $this->components->info("Wrote {$count} ".($count === 1 ? 'statement' : 'statements')." to {$file}.");

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln($message, OutputStyle::OUTPUT_RAW);

        return self::FAILURE;
    }
}
