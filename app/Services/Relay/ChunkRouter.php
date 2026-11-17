<?php

namespace App\Services\Relay;

/**
 * Chooses the Worker for one chunk from per-chunk probe speeds.
 *
 * The fastest sample wins. The only exception is diversity: if the leader has
 * already received every chunk of this file and the runner-up is within 5% of
 * it, the runner-up gets this chunk. That prevents a noisy 1% lead from
 * collapsing an entire file onto one Worker, while still allowing a clearly
 * faster Worker to serve every chunk.
 */
class ChunkRouter
{
    /** Runner-up must be at least this fraction of the leader to break a streak. */
    public const DIVERSITY_RATIO = 0.95;

    /**
     * @param array<int, int> $speeds   Worker id => measured bytes/sec
     * @param array<int, int> $assigned Worker id => chunks assigned so far
     */
    public static function choose(array $speeds, array $assigned, int $totalAssigned): ?int
    {
        $speeds = array_filter($speeds, fn ($speed) => (int) $speed > 0);

        if (empty($speeds)) {
            return null;
        }

        arsort($speeds);
        $ids = array_keys($speeds);
        $best = (int) $ids[0];

        if (count($ids) === 1) {
            return $best;
        }

        $runnerUp = (int) $ids[1];
        $bestCount = (int) ($assigned[$best] ?? 0);

        if ($totalAssigned > 0
            && $bestCount === $totalAssigned
            && (int) $speeds[$runnerUp] >= (int) $speeds[$best] * self::DIVERSITY_RATIO) {
            return $runnerUp;
        }

        return $best;
    }
}
