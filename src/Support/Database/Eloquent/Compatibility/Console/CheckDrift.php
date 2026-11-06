<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Throwable;

final class CheckDrift extends Command
{
    protected $signature = 'compatibility:check
        {--model=* : Check only these legacy models, each by its class or by the class name of one served model}
        {--limit=100 : Report at most this many records per check and model}';

    protected $description = 'Name each record where a served model and its successors drifted apart, reading only, and exit non-zero on any finding.';

    public function handle(ServedModels $served): int
    {
        $limit = (string) $this->option('limit');

        if (preg_match('/^[1-9][0-9]*$/', $limit) !== 1) {
            return $this->refuse("--limit takes a whole number of records above 0, not {$limit}.");
        }

        $models = [];

        foreach (array_filter((array) $this->option('model'), is_string(...)) as $named) {
            $matches = class_exists($named) ? [$named] : array_values(array_filter($served->all(), fn (string $model) => class_basename($model) === $named));

            if (count($matches) !== 1) {
                return $this->refuse($matches === []
                    ? "{$named} names no served model. Name the legacy model by its class, or by the class name of one served model."
                    : "{$named} names more than one served model: ".implode(', ', $matches).'. Name the legacy model by its class.');
            }

            $models[] = $matches[0];
        }

        $models = $models === [] ? $served->all() : array_values(array_unique($models));

        if ($models === []) {
            return $this->refuse('Discovery found no served model, so nothing was checked.');
        }

        $findings = [];
        $refusals = [];

        $siblings = [];

        foreach ($served->all() as $sibling) {
            try {
                $siblings[] = Compatibility::of($sibling)->resolve();
            } catch (Throwable $exception) {
                if (! in_array(
                    needle: $sibling,
                    haystack: $models,
                    strict: true,
                )) {
                    $refusals[] = "{$sibling}: {$exception->getMessage()} The check of every map that shares its successors is incomplete.";
                }
            }
        }

        foreach ($models as $model) {
            try {
                $map = Compatibility::of($model)->resolve();
                $findings = [...$findings, ...(new DriftQueries(Compatibility::connection($map)))->find(
                    map: $map,
                    limit: (int) $limit,
                    siblings: $siblings,
                )];
            } catch (Throwable $exception) {
                $refusals[] = "{$model}: {$exception->getMessage()}";
            }
        }

        $checked = ' in '.count($models).' served '.str('model')->plural(count($models));

        if ($findings !== []) {
            $this->findings($findings);
            $this->components->error(count($findings).' drift '.str('finding')->plural(count($findings)).$checked.'.');
        }

        foreach ($refusals as $refusal) {
            $this->output->getErrorStyle()->writeln($refusal, OutputStyle::OUTPUT_RAW);
        }

        if ($findings !== [] || $refusals !== []) {
            return self::FAILURE;
        }

        $this->components->info('No drift'.$checked.'.');

        return self::SUCCESS;
    }

    /**
     * @param  list<DriftFinding>  $findings
     */
    private function findings(array $findings): void
    {
        $this->table(
            ['Model', 'Check', 'Record', 'Detail'],
            array_map(fn (DriftFinding $finding) => [$finding->model, $finding->check->value, $finding->record, $finding->detail], $findings),
        );
    }

    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln($message, OutputStyle::OUTPUT_RAW);

        return self::FAILURE;
    }
}
