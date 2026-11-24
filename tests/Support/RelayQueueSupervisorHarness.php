<?php

namespace Tests\Support;

use App\Console\Commands\RelayQueueSupervisor;

/**
 * The real supervisor with only proc_open replaced: reconcile/queue/heartbeat
 * stay production code, but a test never starts PHP child processes.
 */
class RelayQueueSupervisorHarness extends RelayQueueSupervisor
{
    /** @var array<int, string> */
    public array $spawnedRoles = [];

    public function reconcile(string $role): void
    {
        $this->reconcileRole($role);
    }

    public function desired(string $role): int
    {
        return $this->desiredRoleConcurrency($role);
    }

    public function queueFor(string $role): string
    {
        return $this->workerQueue($role);
    }

    public function sweepParts(): void
    {
        $this->sweepDeferredParts();
    }

    public function requeueBotImports(): void
    {
        $this->requeueStaleBotImports();
    }

    /** Unix time of the last requeue attempt; 0 means the next tick retries. */
    public function botRequeueAt(): int
    {
        return $this->lastBotRequeueAt;
    }

    public function publishHeartbeat(): void
    {
        $this->heartbeat();
    }

    protected function spawnWorker(string $role): bool
    {
        $this->children[] = [
            'process' => fopen('php://memory', 'rb'),
            'pipes' => [],
            'stopping' => false,
            'role' => $role,
            'started_at' => time(),
        ];

        $this->spawnedRoles[] = $role;

        return true;
    }
}
