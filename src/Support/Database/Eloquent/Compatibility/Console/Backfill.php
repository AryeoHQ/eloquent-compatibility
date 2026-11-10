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
use Support\Database\Eloquent\Compatibility\Write\Outcome;

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
            );
        } catch (InvalidCompatibilityMap|LogicException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $this->line("Walked {$pass->walked} {$map->table} rows: ".implode(
            separator: ', ',
            array: array_map(
                callback: fn (Outcome $outcome) => $pass->count($outcome).' '.$outcome->value,
                array: Outcome::cases(),
            ),
        ).'.');

        foreach ($pass->reasons as $status => $reasons) {
            foreach ($reasons as $reason => $count) {
                $this->line("{$count} {$status}: {$reason}");
            }
        }

        $this->line('Watermark: '.($pass->watermark ?? 'none'));

        if ($pass->blocked()) {
            $this->output->getErrorStyle()->writeln(
                messages: "{$map->table} has refused or failed rows, and install() refuses until a pass writes them. Fix each row, then run php artisan compatibility:backfill {$models[0]} again.",
                type: OutputStyle::OUTPUT_RAW,
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln($message, OutputStyle::OUTPUT_RAW);

        return self::FAILURE;
    }
}
