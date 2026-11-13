<?php

namespace App\Console\Commands;

use App\Services\Relay\WorkerRegistry;
use Illuminate\Console\Command;

class RelayWorkerTestCommand extends Command
{
    protected $signature = 'relay:test-workers {--all : Test all workers, not just enabled ones}';
    protected $description = 'Test all Cloudflare Workers and update their health status';

    public function handle(WorkerRegistry $registry): int
    {
        $workers = $this->option('all')
            ? $registry->allWorkers()
            : $registry->activeWorkers();

        if ($workers->isEmpty()) {
            $this->error('No workers found.');
            return 1;
        }

        $this->info("Testing {$workers->count()} worker(s)...");
        $passed = 0;

        foreach ($workers as $worker) {
            $this->write("Testing {$worker->name}... ");
            $result = $registry->test($worker);

            if ($result['ok']) {
                $this->info("OK ({$result['latency_ms']}ms)");
                $passed++;
            } else {
                $this->error("FAILED: {$result['message']}");
            }
        }

        $this->line("");
        $this->info("Completed: {$passed}/{$workers->count()} passed");

        return $passed === $workers->count() ? 0 : 1;
    }
}