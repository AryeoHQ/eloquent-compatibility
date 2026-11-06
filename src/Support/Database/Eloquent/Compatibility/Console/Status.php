<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\Statements;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
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
            table: Backfiller::ledger($map->table),
        ) !== null;
        $pass = Statements::kind(
            connection: $connection,
            table: Backfiller::BACKFILLS,
        ) === null ? null : $connection->scalar(
            query: 'select pass from '.SqlFragments::identifier(Backfiller::BACKFILLS).' where legacy_type = ?',
            bindings: [$map->table],
            useReadPdo: false,
        );

        return match (true) {
            $legacy === 'v' && $retained === 'r' => ['installed', ''],
            $legacy === null && $retained === null => ['retired', ''],
            $legacy !== 'r' || $retained !== null => ['unknown', "{$map->table} and ".ViewCompiler::retainedTable($map).' match no stage'],
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

    private static function progress(ResolvedMap $map, ConnectionInterface $connection, int $pass, bool $ledger): string
    {
        $counts = $ledger ? (array) $connection->selectOne(
            query: "select count(*) filter (where status = 'refused') as refused, count(*) filter (where status = 'failed') as failed from ".SqlFragments::identifier(Backfiller::ledger($map->table))." where status in ('refused', 'failed')",
            useReadPdo: false,
        ) : [];

        return "pass {$pass}, watermark ".((new Backfiller(
            map: $map,
            connection: $connection,
        ))->remembered() ?? 'none').', '.(int) ($counts['refused'] ?? 0).' refused, '.(int) ($counts['failed'] ?? 0).' failed';
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
