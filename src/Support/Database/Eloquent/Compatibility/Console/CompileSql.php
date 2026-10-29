<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Schema\InstallerAction;

final class CompileSql extends Command
{
    protected $signature = 'compatibility:sql
        {map : The name of a registered compatibility map}
        {--for=install : Print the statements of install, recompile, uninstall or prepare}
        {--retained-table-is-currently= : Compile before the rename: read the retained table\'s columns and grants from its current name}
        {--output= : Write the statements to this file instead of stdout}';

    protected $description = 'Print the SQL statements the Installer runs for a compatibility map, running only catalog reads.';

    public function handle(CompatibilityMaps $compatibilityMaps, Installer $installer, Connection $connection, Filesystem $files): int
    {
        $connection->useWriteConnectionWhenReading();

        $name = (string) $this->argument('map');
        $option = (string) $this->option('for');
        $for = InstallerAction::tryFrom($option);
        $current = $this->option('retained-table-is-currently');
        $current = is_string($current) && $current !== '' ? $current : null;
        $file = $this->option('output');

        if ($for === null) {
            return $this->refuse('--for must be '.implode(', ', array_column(InstallerAction::cases(), 'value')).", not {$option}.");
        }

        try {
            $resolvedMap = $compatibilityMaps->get($name);
            $statements = $installer->statements($resolvedMap, $for, $current);
        } catch (InvalidCompatibilityMap $e) {
            return $this->refuse($e->getMessage());
        }

        $server = $connection->selectOne("select current_database() as database, current_schema() as schema, current_setting('search_path') as search_path, current_setting('server_version') as version, current_user as role");
        $table = (string) $resolvedMap->previousModel->table;
        $header = [
            "-- compatibility:sql {$name} --for={$for->value}: the statements Installer::{$for->value}() runs for the compatibility map {$name}",
            "-- Read from database {$server->database}, schema {$server->schema} (search path {$server->search_path}), on PostgreSQL {$server->version}",
            '-- Compiled at '.now()->toIso8601String(),
        ];

        if ($current !== null && $for !== InstallerAction::Prepare) {
            $header[] = "-- The retained table {$table} was read from {$current}, its name before the rename";
        }

        if ($for === InstallerAction::Install) {
            $header[] = "-- The grants are derived from the grants on {$table} and the map's attribute columns, because the view {$resolvedMap->previousModel->view} does not exist yet, and they omit the grants to {$server->role}, the role this command ran as, as install() omits the role it runs as";
        }

        $header = array_map(fn (string $line) => str_replace(["\r", "\n"], ['\\r', '\\n'], $line), $header);
        $sql = implode("\n", $header)."\n\n".implode("\n\n", array_map(fn (string $statement) => $statement."\n;", $statements))."\n";

        if (! is_string($file) || $file === '') {
            $this->output->write($sql, false, OutputStyle::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if ($files->put($file, $sql) !== strlen($sql)) {
            return $this->refuse("Could not write the statements to {$file}.");
        }

        $this->components->info('Wrote '.count($statements).' '.str('statement')->plural(count($statements))." to {$file}.");

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln($message, OutputStyle::OUTPUT_RAW);

        return self::FAILURE;
    }
}
