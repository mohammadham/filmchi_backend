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

    public function requeueBotImports(): void
    {
        $this->requeueStaleBotImports();
    }

    public function pruneUpdates(): void
    {
        $this->pruneBotUpdates();
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
