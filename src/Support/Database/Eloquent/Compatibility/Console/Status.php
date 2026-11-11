<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\Preparer;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\Statements;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use Support\Database\Eloquent\Compatibility\Write\Ledger;
use Support\Database\Eloquent\Compatibility\Write\Watermark;
use Throwable;

final class Status extends Command
{
    protected $signature = 'compatibility:status
        {--model=* : Print only these legacy models, each by its class or by the class name of one served model}';

    protected $description = 'Print the lifecycle stage of each served model, reading only the catalog and the backfill ledger.';

    public function handle(ServedModels $served): int
    {
        $models = [];

        foreach (array_filter(
            array: (array) $this->option('model'),
            callback: is_string(...),
        ) as $named) {
            $matches = class_exists($named) ? [$named] : array_values(array_filter(
                array: $served->all(),
                callback: fn (string $model) => class_basename($model) === $named,
            ));

            if (count($matches) !== 1) {
                return $this->refuse($matches === []
                    ? "{$named} names no served model. Name the legacy model by its class, or by the class name of one served model."
                    : "{$named} names more than one served model: ".implode(
                        separator: ', ',
                        array: $matches,
                    ).'. Name the legacy model by its class.');
            }

            $models[] = $matches[0];
        }

        $models = $models === [] ? $served->all() : array_values(array_unique($models));

        if ($models === []) {
            return $this->refuse('Discovery found no served model, so no stage was read.');
        }

        $rows = [];
        $refusals = [];

        foreach ($models as $model) {
            try {
                $map = Compatibility::of($model)->resolve();
                [$stage, $detail] = self::stage(
                    map: $map,
                    connection: Compatibility::connection($map),
                );
                $rows[] = [$model, $map->table, $stage, $detail];
            } catch (Throwable $exception) {
                $refusals[] = "{$model}: {$exception->getMessage()}";
            }
        }

        if ($rows !== []) {
            $this->table(
                headers: ['Model', 'Table', 'Stage', 'Detail'],
                rows: $rows,
            );
        }

        foreach ($refusals as $refusal) {
            $this->output->getErrorStyle()->writeln(
                messages: $refusal,
                type: OutputStyle::OUTPUT_RAW,
            );
        }

        return $refusals === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{string, string}
     */
    public static function stage(ResolvedMap $map, ConnectionInterface $connection): array
    {
        $legacy = Statements::kind(
            connection: $connection,
            table: $map->table,
        );
        $retained = Statements::kind(
            connection: $connection,
            table: ViewCompiler::retainedTable($map),
        );
        $ledger = Statements::kind(
            connection: $connection,
            table: Ledger::table($map->table),
        ) !== null;
        $pass = Statements::kind(
            connection: $connection,
            table: Watermark::TABLE,
        ) === null ? null : $connection->scalar(
            query: 'select pass from '.SqlFragments::identifier(Watermark::TABLE).' where legacy_type = ?',
            bindings: [$map->table],
            useReadPdo: false,
        );

        if ($legacy === 'v' && $retained === 'r') {
            return ['installed', ''];
        }

        if ($legacy === null && $retained === null) {
            return ['retired', ''];
        }

        if ($legacy !== 'r' || $retained !== null) {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'report its stage',
                because: "{$map->table} ".self::described($legacy).' and '.ViewCompiler::retainedTable($map).' '.self::described($retained).', which matches none of the six stages, so a schema change outside the package touched one of them. Restore the pair to one stage before you run another operation',
            );
        }

        if ($pass !== null && $connection->scalar(
            query: 'select '.Preparer::uniqueKey($map),
            useReadPdo: false,
        ) !== true) {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'report its stage',
                because: 'prepare() has not finished: '.Preparer::uniqueName($map)." is missing or not a valid unique index on {$map->table}.{$map->successorKey}. Run Compatibility::prepare() again until it completes",
            );
        }

        return match (true) {
            $pass === null && ! $ledger => ['discovered', ''],
            $pass === null => ['rolled back', ''],
            (int) $pass === 0 => ['prepared', ''],
            default => ['backfilling', self::progress(
                map: $map,
                connection: $connection,
                pass: (int) $pass,
                ledger: $ledger,
            )],
        };
    }

    private static function described(null|string $kind): string
    {
        return match ($kind) {
            null => 'does not exist',
            'r' => 'is a table',
            'v' => 'is a view',
            'm' => 'is a materialized view',
            default => "is a relation of kind {$kind}",
        };
    }

    private static function progress(ResolvedMap $map, ConnectionInterface $connection, int $pass, bool $ledger): string
    {
        $counts = $ledger ? (new Ledger(
            map: $map,
            connection: $connection,
        ))->counts() : ['refused' => 0, 'failed' => 0];

        $watermark = new Watermark(
            legacyType: $map->table,
            connection: $connection,
        );
        $progress = $watermark->progress();
        $finished = $progress['finished'] ?? null;

        return "pass {$pass} started ".($progress['started'] ?? 'never').', '.($finished === null ? 'not finished' : "finished {$finished} after ".($progress['rows'] ?? 0).' rows').', watermark '.($watermark->remembered() ?? 'none').", {$counts['refused']} refused, {$counts['failed']} failed";
    }

    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln(
            messages: $message,
            type: OutputStyle::OUTPUT_RAW,
        );

        return self::FAILURE;
    }
}
