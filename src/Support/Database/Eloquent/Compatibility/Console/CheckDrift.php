<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Console\Command;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;

final class CheckDrift extends Command
{
    protected $signature = 'compatibility:check
        {--map=* : Check only the named compatibility maps}
        {--limit=100 : Report at most this many records per check}';

    protected $description = 'Name every record and attribute where the previous and current models of a compatibility map disagree.';

    public function handle(CompatibilityMaps $compatibilityMaps, DriftQueries $driftQueries): int
    {
        $names = array_values(array_filter((array) $this->option('map'), is_string(...)));
        $maps = $names === [] ? $compatibilityMaps->all() : array_map($compatibilityMaps->get(...), $names);
        $limit = max(1, (int) $this->option('limit'));
        $findings = array_merge(...array_map(fn (ResolvedMap $map) => $driftQueries->find($map, $limit), $maps));

        if ($findings === []) {
            $this->components->info('No divergence in '.count($maps).' compatibility '.str('map')->plural(count($maps)).'.');

            return self::SUCCESS;
        }

        $this->table(
            ['Map', 'Check', 'Record', 'Attribute', 'Detail'],
            array_map(fn (DriftFinding $finding) => [$finding->map, $finding->check->value, $finding->recordId, $finding->attribute, $finding->detail], $findings),
        );

        $this->components->error(count($findings).' divergent '.str('record')->plural(count($findings)).' found.');

        return self::FAILURE;
    }
}
