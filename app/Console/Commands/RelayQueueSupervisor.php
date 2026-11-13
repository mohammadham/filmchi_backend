<?php

namespace App\Console\Commands;

use App\Services\Relay\RelaySettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Keeps N ingest queue workers alive.
 *
 * Laravel's queue:work processes one job at a time. Ingest files are large,
 * so "download two files at once" means running two queue workers. This
 * supervisor reads media_relay.ingest.concurrency (fed by the panel) every few
 * seconds, starts or gracefully stops workers to match it, and restarts any
 * worker that exits after --max-time or a crash.
 */
class RelayQueueSupervisor extends Command
{
    protected $signature = 'relay:queue-supervisor
        {--queue=default : Queue name}
        {--poll=3 : Seconds between desired-concurrency checks}';

    protected $description = 'Supervise a panel-controlled number of ingest queue workers';

    /** Cache key read by the settings page. */
    public const STATUS_KEY = 'relay:queue:supervisor';

    /** @var array<int, array{process:resource,pipes:array,stopping:bool,started_at:int}> */
    protected $children = [];

    /** @var bool */
    protected $stopping = false;

    /** @var int */
    protected $spawned = 0;

    public function handle(): int
    {
        $this->installSignalHandlers();

        $this->info(sprintf(
            'relay queue supervisor started (desired=%d)',
            $this->desiredConcurrency()
        ));

        while (!$this->stopping) {
            $this->reapChildren();

            $desired = $this->desiredConcurrency();

            while (count($this->children) < $desired && !$this->stopping) {
                if (!$this->spawnWorker()) {
                    // A missing DB/Redis at boot is transient; retry on the
                    // next poll instead of spinning or exiting the container.
                    break;
                }
            }

            while (count($this->children) > $desired && !$this->stopping) {
                if (!$this->stopOneWorker()) {
                    break;
                }
            }

            $this->heartbeat($desired);

            if (!$this->stopping) {
                sleep(max(1, (int) $this->option('poll')));
            }
        }

        $this->stopAllWorkers();

        return 0;
    }

    /**
     * Publish a small heartbeat for the panel: how many queue workers are
     * actually alive, plus the panel's desired count. A stale heartbeat means
     * the supervisor itself is down, which the panel renders as 0 live.
     */
    protected function heartbeat(int $desired): void
    {
        $live = 0;

        foreach ($this->children as $child) {
            if (empty($child['stopping'])) {
                $live++;
            }
        }

        try {
            Cache::put(self::STATUS_KEY, [
                'workers' => $live,
                'desired' => $desired,
                'updated_at' => now()->toIso8601String(),
            ], now()->addMinutes(10));
        } catch (Throwable $e) {
            // The panel will show "no heartbeat" rather than failing a job.
        }
    }

    protected function desiredConcurrency(): int
    {
        try {
            RelaySettings::apply();
        } catch (Throwable $e) {
            // Keep the last effective config if the database is briefly down.
        }

        return max(1, min(4, (int) config('media_relay.ingest.concurrency', 1)));
    }

    protected function spawnWorker(): bool
    {
        $this->spawned++;

        $queue = (string) $this->option('queue');
        $connection = (string) (config('media_relay.queue.connection') ?: config('queue.default', 'redis'));
        $name = 'ingest-' . getmypid() . '-' . $this->spawned;

        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'queue:work',
            $connection,
            '--queue=' . $queue,
            '--name=' . $name,
            '--sleep=1',
            '--tries=3',
            '--timeout=7000',
            '--max-time=21600',
        ];

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => STDOUT,
            2 => STDERR,
        ];

        $pipes = [];
        $environment = getenv();
        $environment['FLIXY_QUEUE_CHILD'] = '1';
        $process = @proc_open($command, $descriptors, $pipes, null, $environment);

        if (!is_resource($process)) {
            $this->error('could not start queue worker ' . $name);

            return false;
        }

        $this->children[] = [
            'process' => $process,
            'pipes' => $pipes,
            'stopping' => false,
            'started_at' => time(),
        ];

        $this->info(sprintf('started queue worker %s (total=%d)', $name, count($this->children)));

        return true;
    }

    protected function reapChildren(): void
    {
        foreach ($this->children as $index => $child) {
            $status = @proc_get_status($child['process']);

            if ($status === false || !empty($status['running'])) {
                continue;
            }

            @proc_close($child['process']);
            unset($this->children[$index]);

            $this->warn(sprintf(
                'queue worker exited (code=%s, total=%d)',
                $status['exitcode'] ?? '?',
                count($this->children)
            ));
        }

        $this->children = array_values($this->children);
    }

    protected function stopOneWorker(): bool
    {
        if (empty($this->children)) {
            return false;
        }

        $index = null;

        // A worker already shutting down still counts as a child; pick the
        // next live one so scaling down cannot spin on the same entry.
        foreach (array_reverse(array_keys($this->children)) as $candidate) {
            if (!$this->children[$candidate]['stopping']) {
                $index = $candidate;
                break;
            }
        }

        if ($index === null) {
            return false;
        }

        $child = &$this->children[$index];
        $child['stopping'] = true;

        if (function_exists('posix_kill') && is_resource($child['process'])) {
            $pid = @proc_get_status($child['process'])['pid'] ?? null;

            if ($pid) {
                @posix_kill((int) $pid, 15);
            }
        } else {
            @proc_terminate($child['process'], 15);
        }

        $this->info(sprintf('stopping queue worker (total=%d)', count($this->children)));

        return true;
    }

    protected function stopAllWorkers(): void
    {
        $deadline = time() + 20;

        foreach ($this->children as &$child) {
            if ($child['stopping']) {
                continue;
            }

            $child['stopping'] = true;

            if (function_exists('posix_kill') && is_resource($child['process'])) {
                $pid = @proc_get_status($child['process'])['pid'] ?? null;

                if ($pid) {
                    @posix_kill((int) $pid, 15);
                }
            } else {
                @proc_terminate($child['process'], 15);
            }
        }
        unset($child);

        while (!empty($this->children) && time() < $deadline) {
            $this->reapChildren();

            if (!empty($this->children)) {
                usleep(250000);
            }
        }

        foreach ($this->children as $child) {
            @proc_terminate($child['process'], 9);
            @proc_close($child['process']);
        }

        $this->children = [];

        try {
            Cache::put(self::STATUS_KEY, [
                'workers' => 0,
                'desired' => 0,
                'updated_at' => now()->toIso8601String(),
            ], now()->addMinutes(10));
        } catch (Throwable $e) {
            // Ignore: the container is already shutting down.
        }

        $this->info('relay queue supervisor stopped');
    }

    protected function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        $stop = function () {
            $this->stopping = true;
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
