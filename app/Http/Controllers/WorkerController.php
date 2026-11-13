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
            // operator wait out a cooldown left by an old failed test.
            $worker->cooldown_until = null;
        }

        $worker->save();

        return response()->json([
            'status' => true,
            'message' => $worker->enabled ? 'Worker enabled.' : 'Worker disabled.',
            'worker' => $worker,
        ]);
    }

    public function destroy(RelayWorker $worker)
    {
        $worker->delete();

        return response()->json([
            'status' => true,
            'message' => 'Worker removed.',
        ]);
    }

    public function test(RelayWorker $worker, WorkerRegistry $workers)
    {
        $result = $workers->test($worker);

        return response()->json([
            'status' => true,
            'ok' => $result['ok'],
            'result' => $result,
        ]);
    }

    public function testAll(WorkerRegistry $workers)
    {
        $results = RelayWorker::query()
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(function (RelayWorker $worker) use ($workers) {
                return $workers->test($worker);
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

        return response()->json([
            'status' => true,
            'message' => $request->boolean('relay_worker_enabled')
                ? 'Worker system enabled.'
                : 'Worker system disabled.',
            'enabled' => (bool) config('media_relay.workers.enabled'),
        ]);
    }
}
