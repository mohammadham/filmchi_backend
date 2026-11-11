<?php

namespace Tests\Unit;

use App\RelayFile;
use App\Services\Relay\UploadMetrics;
use Tests\TestCase;

class UploadMetricsTest extends TestCase
{
    public function test_speed_is_bytes_over_seconds(): void
    {
        $this->assertSame(1000000, UploadMetrics::speed(2000000, 2000));
        $this->assertNull(UploadMetrics::speed(0, 2000));
        $this->assertNull(UploadMetrics::speed(100, 0));
    }

    public function test_labels_are_operator_friendly(): void
    {
        $this->assertSame('1.0 MB/s', UploadMetrics::formatSpeed(1048576));
        $this->assertSame('500 KB/s', UploadMetrics::formatSpeed(512000));
        $this->assertSame('2s', UploadMetrics::formatDuration(2000));
        $this->assertSame('1m 5s', UploadMetrics::formatDuration(65000));
        $this->assertSame('-', UploadMetrics::formatDuration(null));
    }

    public function test_result_metrics_are_converted_to_persisted_fields(): void
    {
        $metrics = UploadMetrics::fromResult([
            '_metrics' => [
                'bytes' => 2000000,
                'duration_ms' => 2000,
                'started_at' => now()->subSeconds(2),
                'finished_at' => now(),
            ],
        ]);

        $this->assertSame(UploadMetrics::STATUS_UPLOADED, $metrics['upload_status']);
        $this->assertSame(2000000, $metrics['upload_bytes']);
        $this->assertSame(2000, $metrics['upload_duration_ms']);
        $this->assertSame(1000000, $metrics['upload_speed_bps']);
    }

    public function test_deduplicated_rows_are_not_counted_as_uploads(): void
    {
        $file = new RelayFile();
        $file->size = 100;

        UploadMetrics::markDeduplicated($file, 100);

        $this->assertSame(UploadMetrics::STATUS_DEDUPLICATED, $file->upload_status);
        $this->assertNull($file->upload_speed_bps);
        $this->assertSame(__('relayUploadDeduplicated'), UploadMetrics::speedLabel($file));
    }
}
