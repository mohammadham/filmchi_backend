<?php

namespace App\Console\Commands;

use App\RelayFile;
use App\Services\Relay\DemandTracker;
use App\Services\Relay\NineDriveClient;
use App\Services\Relay\TelegramDriveClient;
use Illuminate\Console\Command;

/**
 * Shows whether the two storage services are reachable and how the tiers
 * are currently populated.
 *
 *   php artisan relay:status
 */
class RelayStatus extends Command
{
    /** @var string */
    protected $signature = 'relay:status';

    /** @var string */
    protected $description = 'Show media relay health and tier statistics';

    public function handle(TelegramDriveClient $telegram, NineDriveClient $ninedrive, DemandTracker $demand)
    {
        $tg = $telegram->health();
        $nine = $ninedrive->health();

        $store = $demand->checkStore();

        $this->table(['Component', 'State', 'Detail'], [
            ['Telegram Drive (primary)', $tg['ok'] ? 'ok' : 'down', $tg['message']],
            ['9drive (cache)', $nine['ok'] ? 'ok' : 'down', $nine['message']],
            ['Demand store', $store['ok'] ? 'ok' : 'down', $store['message']],
            ['Promotion queue', 'ok', (string) (config('media_relay.queue.connection') ?: config('queue.default'))],
        ]);

        $this->table(['Tier', 'Files'], [
            ['warm (cache)', RelayFile::where('cache_state', RelayFile::WARM)->count()],
            ['cold', RelayFile::where('cache_state', RelayFile::COLD)->count()],
            ['queued', RelayFile::where('cache_state', RelayFile::QUEUED)->count()],
            ['failed', RelayFile::where('cache_state', RelayFile::FAILED)->count()],
        ]);

        $this->line('Promotion threshold: ' . $demand->threshold() . ' requests inside a '
            . $demand->window() . 's window.');

        if (!$store['ok']) {
            $this->newLine();
            $this->error('The demand store is unusable: ' . $store['message']);
            $this->error('Demand cannot be counted, so no file will ever be promoted automatically.');
        }

        if (!$demand->isPersistentStore()) {
            $this->newLine();
            $this->warn('The demand store is "' . $demand->storeName() . '": counters are discarded after every');
            $this->warn('request, so no file will ever be promoted automatically. Set CACHE_DRIVER=redis');
            $this->warn('(or RELAY_STATE_STORE=redis) to enable demand-based cache tiering.');
        }

        return 0;
    }
}
