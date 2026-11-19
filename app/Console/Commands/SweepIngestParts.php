<?php

namespace App\Console\Commands;

use App\Services\Relay\IngestPartSweeper;
use Illuminate\Console\Command;

/**
 * Runs the stale .part sweeper on demand.
 *
 * The queue supervisor already runs it on its own schedule; this is the
 * operator entry point for a host that filled up before the sweeper existed.
 *
 *   php artisan relay:ingest-sweep --dry-run
 */
class SweepIngestParts extends Command
{
    /** @var string */
    protected $signature = 'relay:ingest-sweep
        {--stale-hours= : Age after which an abandoned part is removed}
        {--dry-run : Report what would be removed without deleting it}';

    /** @var string */
    protected $description = 'Remove abandoned ingest .part files of sources that will not resume them';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $staleHours = $this->option('stale-hours') !== null ? (int) $this->option('stale-hours') : null;

        $result = (new IngestPartSweeper())->sweep($staleHours, $dryRun);

        $this->info(sprintf(
            '%s %d part file(s), %d MB, plus %d orphan chunk manifest(s); %d kept',
            $dryRun ? 'would delete' : 'deleted',
            $result['deleted'],
            (int) round($result['bytes'] / 1048576),
            $result['sidecars'],
            $result['kept']
        ));

        return 0;
    }
}
