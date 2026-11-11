<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Carbon\CarbonInterval;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use LogicException;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Support\Database\Eloquent\Compatibility\Write\Ledger;
use Support\Database\Eloquent\Compatibility\Write\Outcome;
use Support\Database\Eloquent\Compatibility\Write\Pass;

/**
 * @internal
 */
final class Backfill extends Command
{
    protected $signature = 'compatibility:backfill
        {model : The legacy model that declares the map, by its class or by the class name of one served model}
        {--chunk=1000 : How many legacy rows each transaction writes, one savepoint per row}
        {--margin=300 : How many seconds before the last pass started this pass looks back, to catch writes that committed late}';

    protected $description = 'Run one backfill pass: write each legacy row through its compatibility map, record its outcome, and exit non-zero when a row is refused or failed.';

    public function handle(ServedModels $served): int
    {
        $named = (string) $this->argument('model');
        $chunk = (string) $this->option('chunk');
        $margin = (string) $this->option('margin');
        $models = class_exists($named) ? [$named] : array_values(array_filter($served->all(), fn (string $model) => class_basename($model) === $named));

        if (count($models) !== 1) {
            return $this->refuse($models === []
                ? "{$named} names no served model. Name the legacy model by its class, or by the class name of one served model."
                : "{$named} names more than one served model: ".implode(', ', $models).'. Name the legacy model by its class.');
        }

        if (preg_match('/^[1-9][0-9]*$/', $chunk) !== 1) {
            return $this->refuse("--chunk takes a whole number of rows above 0, not {$chunk}.");
        }

        if (preg_match(
            pattern: '/^[0-9]+$/',
            subject: $margin,
        ) !== 1) {
            return $this->refuse("--margin takes a whole number of seconds, not {$margin}.");
        }

        try {
            $map = Compatibility::of($models[0])->resolve();
            $pass = (new Backfiller(
                map: $map,
                connection: Compatibility::connection($map),
            ))->run(
                chunk: (int) $chunk,
                margin: CarbonInterval::seconds((int) $margin),
                progress: fn (Pass $written, int|string $first, int|string $last) => $this->raw("Wrote {$map->table} {$first} to {$last}: ".self::counts($written).'.'),
            );
        } catch (InvalidCompatibilityMap|LogicException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $this->line("Walked {$pass->walked} {$map->table} rows and {$pass->gone} whose legacy row is gone: ".self::counts($pass).'.');
        $key = (new $map->model)->getKeyName();
        $printed = 0;
        $groups = 0;

        foreach ($pass->reasons as $status => $reasons) {
            foreach ($reasons as $reason => $count) {
                $groups++;

                if ($printed < Ledger::FIRST_REASONS) {
                    $printed++;
                    $this->raw("{$count} {$status}: {$reason} ({$key} ".implode(
                        separator: ', ',
                        array: $pass->reasonKeys[$status][$reason] ?? [],
                    ).')');
                }
            }
        }

        if ($groups > $printed) {
            $this->line('And '.($groups - $printed).' more '.($groups - $printed === 1 ? 'reason' : 'reasons').' in '.Ledger::table($map->table).'.');
        }

        $this->line('Watermark: '.($pass->watermark ?? 'none'));

        if ($pass->blocked()) {
            $this->output->getErrorStyle()->writeln(
                messages: "{$map->table} has refused or failed rows, which ".Ledger::table($map->table).' records with their reasons, and install() refuses until a pass writes them. Fix each row, then run '.self::command($models[0]).' again.',
                type: OutputStyle::OUTPUT_RAW,
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    public static function command(string $model): string
    {
        return "php artisan compatibility:backfill '".str_replace(
            search: "'",
            replace: "'\\''",
            subject: $model,
        )."'";
    }

    private static function counts(Pass $pass): string
    {
        return implode(
            separator: ', ',
            array: array_map(
                callback: fn (Outcome $outcome) => $pass->count($outcome).' '.$outcome->value,
                array: Outcome::cases(),
            ),
        );
    }

    private function raw(string $line): void
    {
        $this->output->writeln(
            messages: (string) preg_replace_callback(
                pattern: '/[\x00-\x1F\x7F]/',
                callback: fn (array $control) => sprintf('\\x%02X', ord($control[0])),
                subject: $line,
            ),
            type: OutputStyle::OUTPUT_RAW,
        );
    }

    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln($message, OutputStyle::OUTPUT_RAW);

        return self::FAILURE;
    }
}
