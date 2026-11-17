<?php

namespace App\Services\Relay;

/**
 * Detects a sustained throughput drop against the recent sample window.
 *
 * Samples are taken at a fixed interval. The baseline is the highest sample
 * in the window, so a short spike cannot hide a real drop and an old peak
 * expires after the window. A trigger resets the window to the current value
 * so the caller can re-probe Workers without firing again on every sample.
 */
class SpeedDropDetector
{
    /** @var array<int, float> */
    protected array $samples = [];

    protected int $dropStreak = 0;

    public function __construct(
        protected int $windowSamples = 6,
        protected float $dropRatio = 0.70,
        protected int $confirmations = 2
    ) {
        $this->windowSamples = max(2, $windowSamples);
        $this->dropRatio = max(0.10, min(1.0, $dropRatio));
        $this->confirmations = max(1, $confirmations);
    }

    /**
     * Record one throughput sample and return true when a sustained drop is
     * confirmed. The detector resets itself on a trigger.
     */
    public function observe(float $bytesPerSecond): bool
    {
        $speed = max(0.0, $bytesPerSecond);
        $baseline = $this->samples === [] ? 0.0 : max($this->samples);
        $dropped = $baseline > 0 && $speed < ($baseline * $this->dropRatio);

        $this->samples[] = $speed;

        if (count($this->samples) > $this->windowSamples) {
            array_shift($this->samples);
        }

        $this->dropStreak = $dropped ? $this->dropStreak + 1 : 0;

        if ($this->dropStreak >= $this->confirmations) {
            $this->reset($speed);

            return true;
        }

        return false;
    }

    public function reset(?float $currentSpeed = null): void
    {
        $this->samples = $currentSpeed === null ? [] : [max(0.0, $currentSpeed)];
        $this->dropStreak = 0;
    }

    public function baseline(): float
    {
        return $this->samples === [] ? 0.0 : max($this->samples);
    }
}
