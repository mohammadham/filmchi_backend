<?php

namespace App;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RelayWorker extends Model
{
    use HasFactory;

    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_OK = 'ok';
    public const STATUS_FAILED = 'failed';

    protected $table = 'relay_workers';

    protected $fillable = [
        'name',
        'base_url',
        'api_key',
        'enabled',
        'priority',
        'last_status',
        'last_latency_ms',
        'last_speed_bps',
        'last_speed_at',
        'success_count',
        'last_checked_at',
        'last_error',
        'failure_count',
        'last_failure_at',
        'cooldown_until',
        'auto_disabled_at',
        'disabled_reason',
        'probe_success_count',
        'next_probe_at',
        'min_speed_bps',
        'max_latency_ms',
        'last_used_at',
    ];

    /**
     * The key is write-only from the panel's point of view. Never serialise it
     * into a JSON response, an exception payload or a log line.
     */
    protected $hidden = ['api_key'];

    protected $appends = ['has_api_key'];

    protected $casts = [
        'api_key' => EncryptedString::class,
        'enabled' => 'boolean',
        'priority' => 'integer',
        'last_latency_ms' => 'integer',
        'last_speed_bps' => 'integer',
        'last_speed_at' => 'datetime',
        'success_count' => 'integer',
        'last_checked_at' => 'datetime',
        'failure_count' => 'integer',
        'last_failure_at' => 'datetime',
        'cooldown_until' => 'datetime',
        'auto_disabled_at' => 'datetime',
        'disabled_reason' => 'string',
        'probe_success_count' => 'integer',
        'next_probe_at' => 'datetime',
        'min_speed_bps' => 'integer',
        'max_latency_ms' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function getHasApiKeyAttribute(): bool
    {
        return trim((string) $this->api_key) !== '';
    }

    /**
     * Workers that may receive a real request right now.
     *
     * A failed Worker is deliberately excluded until a probe (automatic or
     * operator-triggered) has proved that it is healthy again. The old
     * behaviour of re-admitting it after a fixed cooldown put live downloads
     * on a known-bad endpoint and was the main source of repeated failures.
     */
    public function scopeAvailable($query)
    {
        return $query
            ->where('enabled', true)
            ->whereNull('auto_disabled_at')
            ->where('last_status', '!=', self::STATUS_FAILED)
            ->where(function ($inner) {
                $inner->whereNull('cooldown_until')
                    ->orWhere('cooldown_until', '<=', now());
            });
    }

    /**
     * Workers that need another health probe: failed, auto-disabled, or never
     * checked. Used by the background recheck loop and the panel's degraded
     * recovery button, not by normal request routing.
     */
    public function scopeDegraded($query)
    {
        return $query->where(function ($inner) {
            $inner->where('last_status', '!=', self::STATUS_OK)
                ->orWhereNotNull('auto_disabled_at')
                ->orWhereNull('last_checked_at');
        });
    }

    /**
     * Measured throughput from real ranged transfers. The panel test button
     * only measures a small health probe, so it is not used here.
     */
    public function getHumanSpeedAttribute(): string
    {
        return \App\Services\Relay\UploadMetrics::formatSpeed($this->last_speed_bps ? (int) $this->last_speed_bps : null);
    }

    public function isAutoDisabled(): bool
    {
        return $this->auto_disabled_at !== null;
    }
}
