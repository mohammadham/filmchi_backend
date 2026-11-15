<?php

namespace App\Http\Controllers;

use App\GlobalSettings;
use App\RelayWorker;
use App\Services\Relay\RelaySettings;
use App\Services\Relay\WorkerRegistry;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    public function store(Request $request, WorkerRegistry $workers)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'base_url' => ['required', 'string', 'max:500'],
            'api_key' => ['required', 'string', 'max:512'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'enabled' => ['nullable'],
        ]);

        $baseUrl = $workers->validateBaseUrl($data['base_url']);

        if (RelayWorker::where('base_url', $baseUrl)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'This Worker URL is already registered.',
            ], 422);
        }

        $worker = RelayWorker::create([
            'name' => trim($data['name']),
            'base_url' => $baseUrl,
            'api_key' => trim($data['api_key']),
            'enabled' => $request->boolean('enabled'),
            'priority' => (int) ($data['priority'] ?? 100),
            'last_status' => RelayWorker::STATUS_UNKNOWN,
        ]);

        $workers->bumpPoolVersion();

        return response()->json([
            'status' => true,
            'message' => 'Worker added.',
            'worker' => $worker,
        ]);
    }

    public function toggle(Request $request, RelayWorker $worker)
    {
        $request->validate(['enabled' => ['required', 'boolean']]);

        $worker->enabled = $request->boolean('enabled');

        if ($worker->enabled) {
            // Re-enabling is an explicit operator decision; do not make the
            // operator wait out a cooldown left by an old failed test. An
            // auto-disabled Worker still needs a successful test; use the
            // "Test failed" button to recover it.
            $worker->cooldown_until = null;
        }

        $worker->save();
        app(WorkerRegistry::class)->bumpPoolVersion();

        return response()->json([
            'status' => true,
            'message' => $worker->enabled ? 'Worker enabled.' : 'Worker disabled.',
            'worker' => $worker,
        ]);
    }

    public function destroy(RelayWorker $worker)
    {
        $worker->delete();
        app(WorkerRegistry::class)->bumpPoolVersion();

        return response()->json([
            'status' => true,
            'message' => 'Worker removed.',
        ]);
    }

    public function test(RelayWorker $worker, WorkerRegistry $workers)
    {
        // A single button press is an explicit operator decision, so one
        // successful request is enough to recover an auto-disabled Worker.
        $result = $workers->test($worker, true);

        return response()->json([
            'status' => true,
            'ok' => $result['ok'],
            'result' => $result,
        ]);
    }

    /**
     * Test every failed/auto-disabled Worker and return the healthy ones to
     * the pool. Manually disabled Workers are tested but never re-enabled by
     * this action; enabled=false remains an operator-only decision.
     */
    public function testDegraded(WorkerRegistry $workers)
    {
        $degraded = RelayWorker::query()
            ->degraded()
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $results = $degraded->map(function (RelayWorker $worker) use ($workers) {
            $result = $workers->test($worker, true);
            $worker->refresh();

            return array_merge($result, [
                'enabled' => (bool) $worker->enabled,
                'auto_disabled' => $worker->auto_disabled_at !== null,
                'failure_count' => (int) $worker->failure_count,
            ]);
        })->values();

        $recovered = $results->filter(function (array $result) {
            return !empty($result['ok']) && !empty($result['enabled']) && empty($result['auto_disabled']);
        })->count();

        return response()->json([
            'status' => true,
            'ok' => $results->every(fn (array $result) => !empty($result['ok'])),
            'tested' => $results->count(),
            'recovered' => $recovered,
            'failed' => $results->filter(fn (array $result) => empty($result['ok']))->count(),
            'results' => $results,
        ]);
    }

    public function testAll(WorkerRegistry $workers)
    {
        $results = RelayWorker::query()
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(function (RelayWorker $worker) use ($workers) {
                return $workers->test($worker, true);
            })
            ->values();

        return response()->json([
            'status' => true,
            'ok' => $results->every(function (array $result) {
                return $result['ok'];
            }),
            'results' => $results,
        ]);
    }

    public function saveSystem(Request $request)
    {
        $setting = GlobalSettings::first();

        if (!$setting) {
            return response()->json([
                'status' => false,
                'message' => 'Setting not found.',
            ], 422);
        }

        $input = RelaySettings::filterInput($request->all());
        $input['relay_worker_enabled'] = $request->boolean('relay_worker_enabled');

        RelaySettings::save($setting, $input);
        app(WorkerRegistry::class)->bumpPoolVersion();

        return response()->json([
            'status' => true,
            'message' => $request->boolean('relay_worker_enabled')
                ? 'Worker system enabled.'
                : 'Worker system disabled.',
            'enabled' => (bool) config('media_relay.workers.enabled'),
        ]);
    }
}
