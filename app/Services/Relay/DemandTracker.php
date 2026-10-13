<?php

namespace App\Services\Relay;

use App\RelayFile;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks how often each file is requested and decides when it deserves to be
 * promoted into the 9drive cache.
 *
 * The counters live in the configured cache store — point CACHE_DRIVER (or
 * RELAY_STATE_STORE) at Redis to get the "redis managed" tiering described in
 * the architecture. Any store works; the durable state is kept in the DB.
 */
class DemandTracker
{
    /**
     * @return \Illuminate\Contracts\Cache\Repository
     */
    protected function store()
    {
        $store = config('media_relay.cache.store');

        return $store ? Cache::store($store) : Cache::store();
    }

    public function prefix(): string
    {
        return (string) config('media_relay.cache.key_prefix', 'relay:demand');
    }

    public function key($fileId): string
    {
        return $this->prefix() . ':' . $fileId;
    }

    public function window(): int
    {
        return (int) config('media_relay.cache.window', 86400);
    }

    public function threshold(): int
    {
        return (int) config('media_relay.cache.promote_threshold', 3);
    }

    /**
     * Count one request against the rolling window.
     */
    public function record(RelayFile $file): int
    {
        $store = $this->store();
        $key = $this->key($file->id);

        $count = (int) $store->get($key, 0) + 1;
        $store->put($key, $count, $this->window());

        return $count;
    }

    public function count(RelayFile $file): int
    {
        return (int) $this->store()->get($this->key($file->id), 0);
    }

    public function reset(RelayFile $file): void
    {
        $store = $this->store();
        $store->forget($this->key($file->id));
        $store->forget($this->touchKey($file));
    }

    public function touchKey(RelayFile $file): string
    {
        return $this->prefix() . ':touch:' . $file->id;
    }

    public function touch(RelayFile $file): void
    {
        $this->store()->put($this->touchKey($file), time(), $this->window());
        $file->last_access_at = now();
        $file->save();
    }

    public function lastTouch(RelayFile $file): ?int
    {
        $value = $this->store()->get($this->touchKey($file));

        return $value === null ? null : (int) $value;
    }

    /**
     * Whether the demand for this file justifies a cache promotion.
     */
    public function shouldPromote(RelayFile $file): bool
    {
        if (!config('media_relay.cache.auto_promote')) {
            return false;
        }

        if ($file->is_blocked) {
            return false;
        }

        if (!in_array($file->cache_state, [RelayFile::COLD, RelayFile::FAILED], true)) {
            return false;
        }

        if ($file->cache_attempts >= (int) config('media_relay.cache.max_attempts', 5)) {
            return false;
        }

        return $this->count($file) >= $this->threshold();
    }

    /**
     * Claim the promotion slot so concurrent requests do not promote twice.
     */
    public function claim(RelayFile $file, int $seconds = 900): bool
    {
        return (bool) $this->store()->add($this->lockKey($file), time(), $seconds);
    }

    public function release(RelayFile $file): void
    {
        $this->store()->forget($this->lockKey($file));
    }

    public function lockKey(RelayFile $file): string
    {
        return $this->prefix() . ':lock:' . $file->id;
    }

    /**
     * The store name in use — surfaced in the panel so operators can see
     * whether Redis is actually wired up.
     */
    public function storeName(): string
    {
        return (string) config('media_relay.cache.store') ?: (string) config('cache.default');
    }

    /**
     * Whether the demand counters survive between requests.
     *
     * The in-memory ``array`` store is discarded at the end of every request,
     * so demand can never reach the promotion threshold. Cache tiering only
     * works with a persistent store (redis, database, file, memcached).
     */
    public function isPersistentStore(): bool
    {
        return !in_array($this->storeName(), ['array', 'null', ''], true);
    }

    /**
     * Round-trip probe of the demand store.
     *
     * A store name alone proves nothing: configuring redis without the
     * phpredis extension (or predis) fails at the first write, which would
     * silently stop all tiering. Report that as broken instead.
     *
     * @return array{ok:bool,message:string}
     */
    public function checkStore(): array
    {
        $name = $this->storeName();

        try {
            $key = $this->prefix() . ':probe';
            $value = (string) time();

            $this->store()->put($key, $value, 60);
            $read = (string) $this->store()->get($key);
            $this->store()->forget($key);

            if ($read !== $value) {
                return ['ok' => false, 'message' => $name . ' did not return the value it stored'];
            }

            return ['ok' => true, 'message' => $name];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $name . ': ' . $e->getMessage()];
        }
    }
}
