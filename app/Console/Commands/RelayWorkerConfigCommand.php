<?php

namespace App\Console\Commands;

use App\RelayWorker;
use Illuminate\Console\Command;

class RelayWorkerConfigCommand extends Command
{
    protected $signature = 'relay:worker-config
        {worker_id? : Worker ID (optional, if omitted lists all)}
        {--min-speed= : Minimum speed in bytes per second (0 = no limit)}
        {--max-latency= : Maximum latency in ms (0 = no limit)}
        {--priority= : Priority weight (lower = preferred)}
        {--cooldown= : Cooldown seconds after failure}
        {--enabled : Enable worker}
        {--disabled : Disable worker}
        {--test : Run health test after config}';
    protected $description = 'Configure Cloudflare Worker settings (min speed, latency, priority, etc.)';

    public function handle(): int
    {
        $workerId = $this->argument('worker_id');

        if ($workerId) {
            return $this->configureWorker($workerId);
        } else {
            return $this->listWorkers();
        }
    }

    private function configureWorker(int $workerId): int
    {
        $worker = RelayWorker::find($workerId);

        if (!$worker) {
            $this->error("Worker {$workerId} not found.");
            return 1;
        }

        $changed = false;

        if ($this->option('min-speed') !== null) {
            $worker->min_speed_bps = (int) $this->option('min-speed');
            $changed = true;
            $this->info("Set min_speed_bps to {$worker->min_speed_bps}");
        }

        if ($this->option('max-latency') !== null) {
            $worker->max_latency_ms = (int) $this->option('max-latency');
            $changed = true;
            $this->info("Set max_latency_ms to {$worker->max_latency_ms}");
        }

        if ($this->option('priority') !== null) {
            $worker->priority = (int) $this->option('priority');
            $changed = true;
            $this->info("Set priority to {$worker->priority}");
        }

        if ($this->option('cooldown') !== null) {
            $worker->cooldown_until = now()->addSeconds((int) $this->option('cooldown'));
            $changed = true;
            $this->info("Set cooldown to " . (int) $this->option('cooldown') . " seconds");
        }

        if ($this->option('enabled')) {
            $worker->enabled = 1;
            $changed = true;
            $this->info("Worker enabled");
        }

        if ($this->option('disabled')) {
            $worker->enabled = 0;
            $changed = true;
            $this->info("Worker disabled");
        }

        if ($changed) {
            $worker->save();
        }

        if ($this->option('test')) {
            $this->line("");
            $this->info("Running health test...");
            $registry = app(\App\Services\Relay\WorkerRegistry::class);
            $result = $registry->test($worker);

            if ($result['ok']) {
                $this->info("Test passed ({$result['latency_ms']}ms)");
            } else {
                $this->error("Test failed: {$result['message']}");
            }
        }

        return 0;
    }

    private function listWorkers(): int
    {
        $workers = RelayWorker::query()
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($workers->isEmpty()) {
            $this->error('No workers found.');
            return 1;
        }

        $headers = ['ID', 'Name', 'Enabled', 'Priority', 'Last Status', 'Speed (B/s)', 'Latency (ms)', 'Cooldown Until'];
        $rows = [];

        foreach ($workers as $worker) {
            $rows[] = [
                $worker->id,
                $worker->name,
                $worker->enabled ? 'Yes' : 'No',
                $worker->priority,
                $worker->last_status,
                $worker->last_speed_bps ?: 'N/A',
                $worker->last_latency_ms ?: 'N/A',
                $worker->cooldown_until ? $worker->cooldown_until->toDateTimeString() : 'None',
            ];
        }

        $this->table($headers, $rows);

        $this->line("");
        $this->info("Total: {$workers->count()} worker(s)");

        return 0;
    }
}