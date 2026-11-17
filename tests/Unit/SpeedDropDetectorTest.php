<?php

namespace Tests\Unit;

use App\Services\Relay\SpeedDropDetector;
use PHPUnit\Framework\TestCase;

class SpeedDropDetectorTest extends TestCase
{
    public function test_it_confirms_a_drop_only_after_the_configured_samples(): void
    {
        $detector = new SpeedDropDetector(4, 0.70, 2);

        $this->assertFalse($detector->observe(1000));
        $this->assertFalse($detector->observe(1000));
        $this->assertFalse($detector->observe(500));
        $this->assertTrue($detector->observe(500));
    }

    public function test_a_small_dip_is_ignored(): void
    {
        $detector = new SpeedDropDetector(4, 0.70, 2);

        $detector->observe(1000);
        $detector->observe(1000);

        $this->assertFalse($detector->observe(800));
        $this->assertFalse($detector->observe(800));
    }

    public function test_an_old_peak_expires_from_the_window(): void
    {
        $detector = new SpeedDropDetector(2, 0.50, 5);

        $detector->observe(1000);
        $detector->observe(600);
        $detector->observe(600);

        $this->assertSame(600.0, $detector->baseline());
    }

    public function test_reset_starts_a_fresh_window(): void
    {
        $detector = new SpeedDropDetector(4, 0.70, 1);

        $detector->observe(1000);
        $this->assertTrue($detector->observe(500));
        $detector->reset(500);

        $this->assertSame(500.0, $detector->baseline());
        $this->assertFalse($detector->observe(450));
    }
}
