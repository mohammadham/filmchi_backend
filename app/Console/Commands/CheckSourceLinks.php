<?php

namespace App\Console\Commands;

use App\ContentSource;
use App\EpisodeSource;
use App\Jobs\ProbeSourceLinkJob;
use Illuminate\Console\Command;

/**
 * Schedules a health probe for every external source link.
 *
 * Probes are dispatched onto the queue so a slow dead host cannot stall
 * the pass; each job records its own result.
 *
 *   php artisan sources:check
 */
class CheckSourceLinks extends Command
{
    /** @var string */
    protected $signature = 'sources:check {--stale-hours=24 : Re-probe only links not checked within this window}';

    /** @var string */
    protected $description = 'Probe external source links and mark dead ones';

    public function handle()
    {
        $staleHours = max(1, (int) $this->option('stale-hours'));
        $limit = 50;
        $dispatched = 0;

        foreach (['content' => ContentSource::class, 'episode' => EpisodeSource::class] as $type => $model) {
            $query = $model::query()
                ->whereIn('type', [2, 3, 4, 5, 6, 8])
                ->where(function ($q) {
                    $q->whereNull('link_status')->orWhereIn('link_status', ['alive', 'dead']);
                })
                ->where(function ($q) use ($staleHours) {
                    $q->whereNull('link_checked_at')
                        ->orWhere('link_checked_at', '<', now()->subHours($staleHours));
                });

            foreach ($query->limit($limit)->get() as $source) {
                if (empty($source->source) || !str_starts_with((string) $source->source, 'http')) {
                    continue;
                }

                ProbeSourceLinkJob::dispatch($type, $source->id);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} link probes.");

        return 0;
    }
}
