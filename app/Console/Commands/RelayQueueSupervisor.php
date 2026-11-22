<?php

namespace App\Console\Commands;

use App\Jobs\ImportBotFileJob;
use App\Services\Relay\DiskBudget;
use App\Services\Relay\IngestPartSweeper;
use App\Services\Relay\RelaySettings;
use App\Services\Relay\WorkerRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps the download and upload worker pools alive independently.
 *
 * Laravel's queue:work process handles one job at a time. Ingest files are
 * large, so a single pool would let both slots end up in the upload phase and
 * leave the download list empty. The download queue (`default`) and upload
 * queue (`uploads`) therefore get their own supervised child counts.
 *
 * Bot imports (`bot`) get a third lane for the same reason: a 2 GB copyMessage
 * import must not sit in front of a panel download, and without its own role
 * nothing would ever consume that queue.
 */
class RelayQueueSupervisor extends Command
{
    protected $signature = 'relay:queue-supervisor
        {--queue=default : Download queue name}
        {--poll=3 : Seconds between desired-concurrency checks}';

    protected $description = 'Supervise panel-controlled download and upload queue workers';

    /** Cache key read by the settings page. */
    public const STATUS_KEY = 'relay:queue:supervisor';

    /**
     * Every lane the supervisor reconciles, in spawn order. One list, so a lane
     * cannot be spawned in one place and forgotten in another: the bot queue
     * only works because ImportBotFileJob names the same queue this list does.
     */
    public const ROLES = ['download', 'upload', 'probe', 'bot'];

    /** @var array<int, array{process:resource,pipes:array,stopping:bool,role:string,started_at:int}> */
    protected $children = [];

    /** @var bool */
    protected $stopping = false;

    /** @var int */
    protected $spawned = 0;

    /** @var int */
    protected $lastRecheckAt = 0;

    /** @var int */
    protected $lastSweepAt = 0;

    /** @var int */
    protected $lastBotRequeueAt = 0;

    public function handle(WorkerRegistry $workers): int
    {
        $this->installSignalHandlers();

        $this->info('relay queue supervisor started (' . implode(', ', array_map(
            fn (string $role): string => $role . '=' . $this->desiredRoleConcurrency($role),
            self::ROLES
        )) . ')');

        while (!$this->stopping) {
            $this->reapChildren();

            foreach (self::ROLES as $role) {
                $this->reconcileRole($role);
            }

            // Re-probe failed/auto-disabled Workers on their own backoff
            // schedule. This is deliberately independent of the healthy-pool
            // floor: one bad Worker is still retested while 15 good ones are
            // serving, and a sleeping Fly.io machine cannot stall a download.
            $probePollSeconds = min(60, max(10, (int) config('media_relay.workers.probe_interval_seconds', 60)));

            if (time() - $this->lastRecheckAt >= $probePollSeconds) {
                $this->lastRecheckAt = time();

                try {
                    $dispatched = $workers->recheckDegraded();

                    if ($dispatched > 0) {
                        $this->info(sprintf('queued %d degraded Worker probe(s)', $dispatched));
                    }
                } catch (Throwable $e) {
                    // A transient DB/Redis hiccup must not stop the pool.
                }
            }

            $this->sweepDeferredParts();
            $this->requeueStaleBotImports();

            $this->heartbeat();

            if (!$this->stopping) {
                sleep(max(1, (int) $this->option('poll')));
            }
        }

        $this->stopAllWorkers();

        return 0;
    }

    /**
     * Match the live child count for one role to its configured target.
     */
    protected function reconcileRole(string $role): void
    {
        $desired = $this->desiredRoleConcurrency($role);

        while ($this->countRole($role) < $desired && !$this->stopping) {
            if (!$this->spawnWorker($role)) {
                // A missing DB/Redis at boot is transient; retry on the next
                // poll instead of spinning or exiting the container.
                break;
            }
        }

        while ($this->countRole($role) > $desired && !$this->stopping) {
            if (!$this->stopOneWorker($role)) {
                break;
            }
        }
    }

    protected function countRole(string $role): int
    {
        $count = 0;

        foreach ($this->children as $child) {
            if (($child['role'] ?? '') === $role) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Publish a small heartbeat for the panel: how many queue workers are
     * actually alive, plus the configured target. A stale heartbeat means the
     * supervisor itself is down, which the panel renders as 0 live.
     */
    protected function heartbeat(): void
    {
        $roles = [];

        foreach (self::ROLES as $role) {
            $roles[$role] = $this->countRole($role);
        }

        try {
            $status = ['updated_at' => now()->toIso8601String()];

            foreach ($roles as $role => $live) {
                $status[$role . '_workers'] = $live;
                $status[$role . '_desired'] = $this->desiredRoleConcurrency($role);
            }

            $status['workers'] = array_sum($roles);
            $status['desired'] = array_sum(array_map(
                fn (string $role): int => $status[$role . '_desired'],
                self::ROLES
            ));

            Cache::put(self::STATUS_KEY, $status, now()->addMinutes(10));
        } catch (Throwable $e) {
            // The panel will show "no heartbeat" rather than failing a job.
        }
    }

    /**
     * Delete .part files whose retry will never resume them.
     *
     * A retried source keeps its partial file; when the retry is lost the file
     * stays on the same disk as MySQL and Redis until someone removes it. The
     * sweeper is deliberately conservative (see IngestPartSweeper) and runs
     * once at supervisor start, then on its configured interval.
     */
    protected function sweepDeferredParts(): void
    {
        $interval = (int) config('media_relay.ingest.stale_sweep_seconds', 900);

        if ($interval <= 0) {
            return;
        }

        if ($this->lastSweepAt > 0 && time() - $this->lastSweepAt < $interval) {
            return;
        }

        $this->lastSweepAt = time();

        try {
            $result = (new IngestPartSweeper())->sweep();

            if ($result['deleted'] > 0 || $result['sidecars'] > 0) {
                $this->info(sprintf(
                    'swept %d stale ingest part(s), %d MB, %d orphan chunk manifest(s)',
                    $result['deleted'],
                    (int) round($result['bytes'] / 1048576),
                    $result['sidecars']
                ));
            }
        } catch (Throwable $e) {
            // A transient DB hiccup must not stop the pool; retry next tick.
            $this->lastSweepAt = 0;
        }
    }

    /**
     * A worker can die between the dispatch and the row update (OOM, deploy,
     * killed container) and nothing would pick that submission up again: the
     * Telegram retry of the same update_id is a no-op by dedup. Rows untouched
     * for longer than the job's own retry budget are queued once more; a job
     * that is only waiting out its backoff has a fresh updated_at.
     */
    protected function requeueStaleBotImports(): void
    {
        $every = (int) config('media_relay.bot.requeue_seconds', 300);

        if ($every <= 0 || time() - $this->lastBotRequeueAt < $every) {
            return;
        }

        $this->lastBotRequeueAt = time();

        try {
            $stale = DB::table('bot_submissions')
                ->whereIn('status', ['queued', 'retrying'])
                ->where('updated_at', '<', now()->subSeconds(max(60, (int) config('media_relay.bot.stale_seconds', 600))))
                ->orderBy('id')
                ->limit(50)
                ->pluck('id');
        } catch (Throwable $e) {
            // A transient DB hiccup must not stop the pool; retry next tick.
            $this->lastBotRequeueAt = 0;

            return;
        }

        foreach ($stale as $id) {
            ImportBotFileJob::dispatch((int) $id);
        }

        // A direct instance has no console: the sweep still has to work there
        // (tests, and any embedder that reconciles without a terminal).
        if ($stale->isNotEmpty() && $this->output !== null) {
            $this->info(sprintf('requeued %d stale bot import(s)', $stale->count()));
        }
    }

    protected function desiredRoleConcurrency(string $role): int
    {
        try {
            RelaySettings::apply();
        } catch (Throwable $e) {
            // Keep the last effective config if the database is briefly down.
        }

        if ($role === 'upload') {
            return max(1, min(4, (int) config('media_relay.upload.concurrency', 2)));
        }

        if ($role === 'probe') {
            return max(1, min(2, (int) config('media_relay.workers.probe_concurrency', 1)));
        }

        if ($role === 'bot') {
            // A bot import is one Telegram copyMessage plus a zero-byte adopt,
            // so two workers already cover a burst; more would only add logins.
            return max(1, min(2, (int) config('media_relay.bot.concurrency', 1)));
        }

        $max = max(1, min(4, (int) config('media_relay.ingest.concurrency', 1)));

        // May be zero: a full ingest budget stops the download pool instead of
        // keeping one worker feeding more files onto the disk.
        return DiskBudget::desiredDownloads($max);
    }

    protected function spawnWorker(string $role): bool
    {
        $this->spawned++;

        $queue = $this->workerQueue($role);
        $connection = (string) (config('media_relay.queue.connection') ?: config('queue.default', 'redis'));
        $prefix = match ($role) {
            'upload' => 'upload',
            'probe' => 'probe',
            'bot' => 'bot',
            default => 'ingest',
        };
        $name = $prefix . '-' . getmypid() . '-' . $this->spawned;

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
            // Segmented downloads keep a pool of cURL handles plus pending
            // chunks; Laravel's 128 MB default made workers recycle at the
            // worst moment. Give them enough room for a multi-GB transfer.
            '--memory=512',
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
            'role' => $role,
            'started_at' => time(),
        ];

        $this->info(sprintf('started %s queue worker %s (total=%d)', $role, $name, count($this->children)));

        return true;
    }

    /** The queue a role's workers consume; ImportBotFileJob dispatches to the same name. */
    protected function workerQueue(string $role): string
    {
        return match ($role) {
            'upload' => (string) config('media_relay.upload.queue', 'uploads'),
            'probe' => (string) config('media_relay.workers.probe_queue', 'probes'),
            'bot' => (string) config('media_relay.bot.queue', 'bot'),
            default => $this->downloadQueue(),
        };
    }

    /** `--queue` when the command really runs; the configured name otherwise. */
    protected function downloadQueue(): string
    {
        $option = $this->input === null ? null : $this->option('queue');

        return (string) ($option ?: config('media_relay.queue.name', 'default'));
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
                '%s queue worker exited (code=%s, total=%d)',
                $child['role'] ?? 'queue',
                $status['exitcode'] ?? '?',
                count($this->children)
            ));
        }

        $this->children = array_values($this->children);
    }

    protected function stopOneWorker(?string $role = null): bool
    {
        if (empty($this->children)) {
            return false;
        }

        $index = null;

        // A worker already shutting down still counts as a child; pick the
        // next live one so scaling down cannot spin on the same entry.
        foreach (array_reverse(array_keys($this->children)) as $candidate) {
            if ($role !== null && ($this->children[$candidate]['role'] ?? '') !== $role) {
                continue;
            }

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

        $this->info(sprintf(
            'stopping %s queue worker (total=%d)',
            $child['role'] ?? 'queue',
            count($this->children)
        ));

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
                'download_workers' => 0,
                'upload_workers' => 0,
                'probe_workers' => 0,
                'bot_workers' => 0,
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
