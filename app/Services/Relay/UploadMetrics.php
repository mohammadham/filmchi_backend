<?php

namespace App\Services\Relay;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Records the user-visible upload phase of a Telegram Drive transfer.
 *
 * The HTTP request finishes before Telegram Drive finishes processing a large
 * file: TelegramDriveClient waits for the remote "ready" state, so these
 * timings cover the whole upload-to-ready window. That is deliberately the
 * number an operator expects when asking "how long did this upload take?".
 */
class UploadMetrics
{
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_DEDUPLICATED = 'deduplicated';
    public const STATUS_FAILED = 'failed';

    /**
     * Mark the start of an upload and keep an attempt counter on the row.
     */
    public static function markStarted(Model $model): void
    {
        $model->upload_status = self::STATUS_UPLOADING;
        $model->upload_started_at = now();
        $model->upload_finished_at = null;
        $model->upload_duration_ms = null;
        $model->upload_speed_bps = null;
        $model->upload_bytes = 0;
        $model->upload_attempts = (int) ($model->upload_attempts ?? 0) + 1;
        $model->save();
    }

    /**
     * Build persisted metrics from the result returned by uploadFromPath().
     *
     * @return array<string,mixed>
     */
    public static function fromResult(array $result, int $fallbackBytes = 0, ?CarbonInterface $fallbackStartedAt = null): array
    {
        $raw = is_array($result['_metrics'] ?? null) ? $result['_metrics'] : [];
        $bytes = max(0, (int) ($raw['bytes'] ?? $fallbackBytes));
        $durationMs = max(0, (int) ($raw['duration_ms'] ?? 0));

        return [
            'upload_status' => self::STATUS_UPLOADED,
            'upload_started_at' => $raw['started_at'] ?? $fallbackStartedAt ?? now(),
            'upload_finished_at' => $raw['finished_at'] ?? now(),
            'upload_bytes' => $bytes,
            'upload_duration_ms' => $durationMs > 0 ? $durationMs : null,
            'upload_speed_bps' => self::speed($bytes, $durationMs),
        ];
    }

    /**
     * Apply metrics to a model without saving it, so callers can keep their
     * existing create/save transaction in one place.
     *
     * @param array<string,mixed> $metrics
     */
    public static function apply(Model $model, array $metrics): void
    {
        $model->upload_status = $metrics['upload_status'] ?? self::STATUS_UPLOADED;
        $model->upload_started_at = $metrics['upload_started_at'] ?? now();
        $model->upload_finished_at = $metrics['upload_finished_at'] ?? now();
        $model->upload_bytes = max(0, (int) ($metrics['upload_bytes'] ?? 0));
        $model->upload_duration_ms = isset($metrics['upload_duration_ms'])
            ? max(0, (int) $metrics['upload_duration_ms'])
            : null;
        $model->upload_speed_bps = isset($metrics['upload_speed_bps'])
            ? max(0, (int) $metrics['upload_speed_bps'])
            : null;
        $model->upload_attempts = max(1, (int) ($model->upload_attempts ?? 0));
    }

    /**
     * Mark a failed upload while retaining the elapsed time for diagnosis.
     */
    public static function markFailed(Model $model, int $bytes = 0, ?CarbonInterface $startedAt = null): void
    {
        $startedAt = $startedAt ?: $model->upload_started_at;
        $durationMs = $startedAt
            ? max(0, (int) round(now()->diffInMilliseconds($startedAt, true)))
            : null;

        $model->upload_status = self::STATUS_FAILED;
        $model->upload_finished_at = now();
        $model->upload_bytes = max(0, $bytes);
        $model->upload_duration_ms = $durationMs ?: null;
        $model->upload_speed_bps = null;
        $model->upload_attempts = max(1, (int) ($model->upload_attempts ?? 0));
        $model->save();
    }

    /**
     * The upload was skipped because the same bytes already exist.
     */
    public static function markDeduplicated(Model $model, int $bytes = 0): void
    {
        $model->upload_status = self::STATUS_DEDUPLICATED;
        $model->upload_started_at = null;
        $model->upload_finished_at = now();
        $model->upload_bytes = max(0, $bytes);
        $model->upload_duration_ms = null;
        $model->upload_speed_bps = null;
        $model->upload_attempts = max(1, (int) ($model->upload_attempts ?? 0));
    }

    public static function speed(int $bytes, ?int $durationMs): ?int
    {
        if ($bytes <= 0 || !$durationMs || $durationMs <= 0) {
            return null;
        }

        return max(0, (int) round($bytes / ($durationMs / 1000)));
    }

    /**
     * Short operator-facing speed label, e.g. "18.4 MB/s".
     */
    public static function speedLabel(Model $model): string
    {
        $status = (string) ($model->upload_status ?? '');

        if ($status === self::STATUS_DEDUPLICATED) {
            return __('relayUploadDeduplicated');
        }

        if (!empty($model->upload_speed_bps)) {
            return self::formatSpeed((int) $model->upload_speed_bps);
        }

        // During an upload, show the average from its start. The final value is
        // replaced by the exact measurement when Telegram Drive reports ready.
        if ($status === self::STATUS_UPLOADING && $model->upload_started_at) {
            $elapsedMs = max(0, (int) round(now()->diffInMilliseconds($model->upload_started_at, true)));
            $bytes = (int) ($model->upload_bytes ?: $model->size ?: 0);
            $speed = self::speed($bytes, $elapsedMs);

            if ($speed !== null) {
                return self::formatSpeed($speed);
            }
        }

        return '-';
    }

    public static function durationLabel(Model $model): string
    {
        $status = (string) ($model->upload_status ?? '');

        if ($status === self::STATUS_DEDUPLICATED) {
            return '-';
        }

        if ($model->upload_status === self::STATUS_UPLOADING && $model->upload_started_at) {
            return self::formatDuration((int) round(now()->diffInMilliseconds($model->upload_started_at, true)));
        }

        return self::formatDuration($model->upload_duration_ms ? (int) $model->upload_duration_ms : null);
    }

    public static function formatSpeed(?int $bytesPerSecond): string
    {
        if (!$bytesPerSecond || $bytesPerSecond <= 0) {
            return '-';
        }

        if ($bytesPerSecond >= 1048576) {
            return number_format($bytesPerSecond / 1048576, 1) . ' MB/s';
        }

        return number_format($bytesPerSecond / 1024, 0) . ' KB/s';
    }

    public static function formatDuration(?int $durationMs): string
    {
        if (!$durationMs || $durationMs <= 0) {
            return '-';
        }

        $seconds = (int) round($durationMs / 1000);

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        if ($minutes < 60) {
            return $minutes . 'm ' . $remaining . 's';
        }

        return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
    }

    /**
     * Weighted average over recent completed uploads. Weighting by bytes keeps
     * a 10 MB file from carrying the same influence as a 2 GB file.
     *
     * @return array{count:int,avg_speed_bps:int|null,avg_duration_ms:int|null}
     */
    public static function summary(string $modelClass, int $limit = 20): array
    {
        $rows = $modelClass::query()
            ->where('upload_status', self::STATUS_UPLOADED)
            ->whereNotNull('upload_duration_ms')
            ->whereNotNull('upload_speed_bps')
            ->orderByDesc('upload_finished_at')
            ->limit($limit)
            ->get(['upload_bytes', 'upload_duration_ms', 'upload_speed_bps']);

        if ($rows->isEmpty()) {
            return ['count' => 0, 'avg_speed_bps' => null, 'avg_duration_ms' => null];
        }

        $bytes = (int) $rows->sum(fn ($row) => (int) $row->upload_bytes);
        $durationMs = (int) $rows->sum(fn ($row) => (int) $row->upload_duration_ms);
        $avgSpeed = self::speed($bytes, $durationMs);
        $avgDuration = (int) round($durationMs / max(1, $rows->count()));

        return [
            'count' => $rows->count(),
            'avg_speed_bps' => $avgSpeed,
            'avg_duration_ms' => $avgDuration > 0 ? $avgDuration : null,
        ];
    }
}
