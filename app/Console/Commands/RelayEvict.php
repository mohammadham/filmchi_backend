<?php

namespace App\Console\Commands;

use App\Services\Relay\CachePromoter;
use Illuminate\Console\Command;

/**
 * Keeps the 9drive cache tier within its configured byte budget by
 * demoting the least recently used warm files back to the primary tier.
 *
 *   php artisan relay:evict            # evict until within budget
 *   php artisan relay:evict --limit=50 # raise the per-run cap
 */
class RelayEvict extends Command
{
    /** @var string */
    protected $signature = 'relay:evict {--limit=20 : Maximum evictions in one pass}';

    /** @var string */
    protected $description = 'Evict LRU warm files until the cache tier fits its byte budget';

    public function handle(CachePromoter $promoter)
    {
        $budget = (int) config('media_relay.cache.max_cache_bytes', 0);

        if ($budget <= 0) {
            $this->info('No cache budget configured (RELAY_MAX_CACHE_MB=0); nothing to do.');

            return 0;
        }

        $usage = $promoter->cacheUsage();
        $over = $promoter->overBudgetBytes();

        $this->table(['Metric', 'Value'], [
            ['Budget', number_format($budget / 1048576, 1) . ' MB'],
            ['Usage', number_format($usage / 1048576, 1) . ' MB'],
            ['Over budget', number_format($over / 1048576, 1) . ' MB'],
        ]);

        if ($over <= 0) {
            $this->info('Cache usage is within budget.');

            return 0;
        }

        $result = $promoter->evictToBudget((int) $this->option('limit'));

        $this->table(['Evicted', 'Bytes freed', 'Usage now', 'Still over'], [
            [
                $result['evicted'],
                number_format($result['bytes'] / 1048576, 1) . ' MB',
                number_format($result['usage'] / 1048576, 1) . ' MB',
                number_format($result['over'] / 1048576, 1) . ' MB',
            ],
        ]);

        if ($result['over'] > 0) {
            $this->warn('Still over budget: every remaining warm file is inside the idle protection window.');
            $this->warn('They will become eligible once RELAY_EVICT_MIN_IDLE seconds pass without access.');
        }

        return 0;
    }
}
