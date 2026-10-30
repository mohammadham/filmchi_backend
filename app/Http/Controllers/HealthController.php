<?php

namespace App\Http\Controllers;

use App\Services\Relay\NineDriveClient;
use App\Services\Relay\TelegramDriveClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Liveness/readiness endpoint for docker healthchecks and uptime monitors.
 *
 * GET /healthz           -> database, cache and queue (fast, local)
 * GET /healthz?full=1    -> also probes Telegram Drive and 9drive
 */
class HealthController extends Controller
{
    public function index(
        Request $request,
        TelegramDriveClient $telegram,
        NineDriveClient $ninedrive
    ): JsonResponse {
        $checks = [
            'database' => $this->probe(function () {
                DB::select('select 1');
            }),
            'cache' => $this->probe(function () {
                $key = 'healthz:' . bin2hex(random_bytes(6));

                Cache::put($key, 'ok', 5);
                $ok = Cache::get($key) === 'ok';
                Cache::forget($key);

                if (!$ok) {
                    throw new \RuntimeException('cache round-trip failed');
                }
            }),
            'queue' => $this->probe(function () {
                Queue::size((string) config('media_relay.queue.name', 'default'));
            }),
        ];

        if ($request->boolean('full')) {
            $checks['telegram_drive'] = $this->remote($telegram->health());
            $checks['ninedrive'] = $this->remote($ninedrive->health());
        }

        $critical = $checks['database']['ok'] && $checks['cache']['ok'];

        $remoteDown = false;

        foreach ($checks as $check) {
            if (isset($check['ok']) && !$check['ok']) {
                $remoteDown = true;
            }
        }

        $status = !$critical ? 'down' : ($remoteDown ? 'degraded' : 'ok');

        return response()
            ->json([
                'status' => $status,
                'time' => now()->toIso8601String(),
                'checks' => $checks,
            ], $critical ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @param  callable  $check
     * @return array{ok:bool,ms:int}
     */
    protected function probe(callable $check): array
    {
        $start = microtime(true);

        try {
            $check();

            return ['ok' => true, 'ms' => (int) round((microtime(true) - $start) * 1000)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'ms' => (int) round((microtime(true) - $start) * 1000)];
        }
    }

    /**
     * Strip upstream details from the public response.
     *
     * @param  array{ok:bool,message:string}  $health
     * @return array{ok:bool,ms:int}
     */
    protected function remote(array $health): array
    {
        return [
            'ok' => (bool) ($health['ok'] ?? false),
            'ms' => 0,
        ];
    }
}
