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

        $sweeper = new IngestPartSweeper();
        $result = $sweeper->sweep($staleHours, $dryRun);
        // Abandoned resumable uploads live in their own folder and have no
        // source row, so age is the only thing that can free them.
        $uploads = $sweeper->sweepTmpUploads($staleHours, $dryRun);

        $this->info(sprintf(
            '%s %d part file(s), %d MB, plus %d orphan chunk manifest(s) and %d abandoned upload part(s); %d ingest part(s) kept',
            $dryRun ? 'would delete' : 'deleted',
            $result['deleted'],
            (int) round($result['bytes'] / 1048576),
            $result['sidecars'],
            $uploads['deleted'],
            $result['kept']
        ));

        return 0;
    }
}
