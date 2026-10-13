<?php

namespace App\Console\Commands;

use App\RelayFile;
use App\Services\Relay\CachePromoter;
use App\Services\Relay\DemandTracker;
use Illuminate\Console\Command;

/**
 * Runs the tiering decision outside a web request.
 *
 * Anything whose demand crossed the threshold — or that was explicitly queued
 * from the panel — is streamed from Telegram Drive into the 9drive cache.
 *
 *   php artisan relay:promote --limit=5
 */
class PromoteRelayFiles extends Command
{
    /** @var string */
    protected $signature = 'relay:promote
        {--limit=5 : Maximum number of files to promote in this run}
        {--id= : Promote one specific relay file id}';

    /** @var string */
    protected $description = 'Promote in-demand relay files from Telegram Drive into the 9drive cache';

    public function handle(CachePromoter $promoter, DemandTracker $demand)
    {
        $limit = max(1, (int) $this->option('limit'));
        $id = $this->option('id');

        if ($id) {
            $file = RelayFile::find($id);

            if (!$file) {
                $this->error('Relay file ' . $id . ' not found.');

                return 1;
            }

            $result = $promoter->promote($file);
            $this->line($result['message']);

            return $result['ok'] ? 0 : 1;
        }

        $candidates = RelayFile::whereIn('cache_state', [RelayFile::QUEUED, RelayFile::COLD, RelayFile::FAILED])
            ->where('is_blocked', false)
            ->whereNotNull('tg_file_id')
            ->orderBy('last_access_at', 'desc')
            ->limit($limit * 4)
            ->get()
            ->filter(function (RelayFile $file) use ($demand) {
                return $file->cache_state === RelayFile::QUEUED || $demand->shouldPromote($file);
            })
            ->take($limit);

        if ($candidates->isEmpty()) {
            $this->info('Nothing to promote.');

            return 0;
        }

        foreach ($candidates as $file) {
            $this->line('Promoting #' . $file->id . ' — ' . $file->name);
            $result = $promoter->promote($file);
            $this->line('  ' . ($result['ok'] ? '<info>ok</info>' : '<error>failed</error>') . ' ' . $result['message']);
        }

        return 0;
    }
}
