<?php

namespace Tests\Feature;

use App\RelayFile;
use App\Services\Relay\CachePromoter;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RelayEvictTest extends TestCase
{
    protected function makeWarm(int $sizeMb, $lastAccess = null): RelayFile
    {
        $f = new RelayFile();
        $f->name = 'warm-' . uniqid() . '.bin';
        $f->size = $sizeMb * 1048576;
        $f->mime = 'application/octet-stream';
        $f->cache_state = RelayFile::WARM;
        $f->nine_file_id = 'nine-' . uniqid();
        $f->last_access_at = $lastAccess;
        $f->save();

        return $f;
    }

    protected function tearDown(): void
    {
        DB::table('relay_files')->delete();
        DB::table('relay_events')->delete();

        parent::tearDown();
    }

    public function test_no_eviction_without_budget()
    {
        config(['media_relay.cache.max_cache_bytes' => 0]);

        $promoter = app(CachePromoter::class);

        $this->assertSame(0, $promoter->overBudgetBytes());
        $this->assertSame(0, $promoter->evictToBudget(5)['evicted']);
    }

    public function test_lru_eviction_until_within_budget()
    {
        config(['media_relay.cache.max_cache_bytes' => 250 * 1048576]);
        config(['media_relay.cache.evict_min_idle' => 3600]);

        $old = $this->makeWarm(100, now()->subHours(5));
        $mid = $this->makeWarm(100, now()->subHours(3));
        $fresh = $this->makeWarm(100, now()->subMinutes(2));

        $promoter = app(CachePromoter::class);
        $this->assertSame(300 * 1048576, $promoter->cacheUsage());

        $result = $promoter->evictToBudget(10);

        // 300MB used, 250MB budget -> 50MB over -> evict the oldest only.
        $this->assertSame(1, $result['evicted']);
        $this->assertSame(0, $result['over']);

        $this->assertDatabaseHas('relay_files', ['id' => $old->id, 'cache_state' => RelayFile::COLD]);
        $this->assertDatabaseHas('relay_files', ['id' => $mid->id, 'cache_state' => RelayFile::WARM]);
        $this->assertDatabaseHas('relay_files', ['id' => $fresh->id, 'cache_state' => RelayFile::WARM]);
    }

    public function test_never_accessed_files_are_evicted_first()
    {
        config(['media_relay.cache.max_cache_bytes' => 10 * 1048576]);
        config(['media_relay.cache.evict_min_idle' => 60]);

        $touched = $this->makeWarm(50, now()->subHours(10));
        $untouched = $this->makeWarm(50, null);

        $promoter = app(CachePromoter::class);
        $result = $promoter->evictToBudget(10);

        // 100MB used against a 10MB budget: both must go, untouched first.
        $this->assertSame(2, $result['evicted']);
        $this->assertSame(0, $result['over']);
        $this->assertDatabaseHas('relay_files', ['id' => $untouched->id, 'cache_state' => RelayFile::COLD]);
        $this->assertDatabaseHas('relay_files', ['id' => $touched->id, 'cache_state' => RelayFile::COLD]);

        $order = DB::table('relay_events')
            ->where('event', 'evicted')
            ->orderBy('id')
            ->pluck('relay_file_id');

        $this->assertSame($untouched->id, (int) $order->first());
    }

    public function test_idle_window_protects_active_files()
    {
        config(['media_relay.cache.max_cache_bytes' => 10 * 1048576]);
        config(['media_relay.cache.evict_min_idle' => 3600]);

        $this->makeWarm(50, now()->subSeconds(30));

        $promoter = app(CachePromoter::class);
        $result = $promoter->evictToBudget(10);

        $this->assertSame(0, $result['evicted']);
        $this->assertGreaterThan(0, $result['over']);
    }

    public function test_limit_caps_the_pass()
    {
        config(['media_relay.cache.max_cache_bytes' => 1]);
        config(['media_relay.cache.evict_min_idle' => 0]);

        $a = $this->makeWarm(10, now()->subHours(9));
        $b = $this->makeWarm(10, now()->subHours(8));
        $c = $this->makeWarm(10, now()->subHours(7));

        $promoter = app(CachePromoter::class);
        $result = $promoter->evictToBudget(2);

        $this->assertSame(2, $result['evicted']);
        $this->assertDatabaseHas('relay_files', ['id' => $a->id, 'cache_state' => RelayFile::COLD]);
        $this->assertDatabaseHas('relay_files', ['id' => $b->id, 'cache_state' => RelayFile::COLD]);
        $this->assertDatabaseHas('relay_files', ['id' => $c->id, 'cache_state' => RelayFile::WARM]);
    }
}
