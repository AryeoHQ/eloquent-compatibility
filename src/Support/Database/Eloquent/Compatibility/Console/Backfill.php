<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use LogicException;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;

final class Backfill extends Command
{
    protected $signature = 'compatibility:backfill
        {model : The legacy model that declares the map, by its class or by the class name of one served model}
        {--chunk=1000 : How many legacy rows each transaction writes}
        {--since= : Write only the legacy rows whose updated_at passed this watermark, which an earlier run printed}';

    protected $description = 'Write the successor rows of each legacy row from its compatibility map, chunk by chunk, while the legacy table is still a table.';

    public function handle(ServedModels $served): int
    {
        $named = (string) $this->argument('model');
        $chunk = (string) $this->option('chunk');
        $since = $this->option('since');
        $models = class_exists($named) ? [$named] : array_values(array_filter($served->all(), fn (string $model) => class_basename($model) === $named));

        if (count($models) !== 1) {
            return $this->refuse($models === []
                ? "{$named} names no served model. Name the legacy model by its class, or by the class name of one served model."
                : "{$named} names more than one served model: ".implode(', ', $models).'. Name the legacy model by its class.');
        }

        if (preg_match('/^[1-9][0-9]*$/', $chunk) !== 1) {
            return $this->refuse("--chunk takes a whole number of rows above 0, not {$chunk}.");
        }

        try {
            $map = Compatibility::of($models[0])->resolve();
            $backfilled = (new Backfiller($map, Compatibility::connection($map)))->run(
                (int) $chunk,
                is_string($since) && $since !== '' ? Backfiller::watermark($since) : null,
                fn (array $keys, int $changed) => $this->line("Wrote {$changed} successor rows for {$map->table} ".reset($keys).' to '.end($keys).'.'),
            );
        } catch (InvalidCompatibilityMap|LogicException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $watermark = $backfilled['watermark']['updatedAt'];
        $this->line("Backfilled {$backfilled['read']} {$map->table} rows, which changed {$backfilled['changed']} successor rows.");
        $this->line("Watermark: {$watermark}");
        $this->line("Catch up with: php artisan compatibility:backfill {$models[0]} --since={$watermark}");

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln($message, OutputStyle::OUTPUT_RAW);

        return self::FAILURE;
    }
}
