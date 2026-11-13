<div class="row" id="relayWorkerSettings">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <div class="page-title w-100">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <h4 class="mb-1 fw-semibold">{{ __('relayWorkersTitle') }}</h4>
                            <div class="text-muted small">{{ __('relayWorkersHint') }}</div>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge bg-success">{{ __('relayWorkerOnline') }}: {{ $workers->where('last_status', 'ok')->count() }}</span>
                            <span class="badge bg-danger">{{ __('relayWorkerOffline') }}: {{ $workers->where('last_status', 'failed')->count() }}</span>
                            <span class="badge bg-secondary">{{ __('relayWorkerStatus') }}: {{ $workers->count() }}</span>
                            <span class="badge bg-info">{{ __('relayRuntimeActive') }}: <span id="relayRuntimeActiveValue">{{ (int) $relayRuntime['active'] }}</span></span>
                            <span class="badge bg-primary">{{ __('relayRuntimeWorkers') }}: <span id="relayRuntimeWorkersValue">{{ (int) $relayRuntime['workers_live'] }}/{{ (int) $relayRuntime['workers_desired'] }}</span></span>
                            <span class="badge bg-secondary">{{ __('relayRuntimeQueued') }}: <span id="relayRuntimeQueuedValue">{{ (int) $relayRuntime['queued'] }}</span></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body px-4">
                <form id="workerSystemForm" method="POST" autocomplete="off">
                    @csrf
                    <div class="card w-auto p-3 mb-4">
                        <div class="checkbox-slider d-flex align-items-center justify-content-between">
                            <span class="me-3">
                                {{ __('relayWorkerSystemEnabled') }}
                                <br>
                                <span class="text-muted small">{{ __('relayWorkerSystemHint') }}</span>
                            </span>
                            <label>
                                <input type="checkbox" class="d-none" id="workerSystemEnabled" name="relay_worker_enabled" value="1" {{ $workerSystemEnabled ? 'checked' : '' }}>
                                <span class="toggle_background">
                                    <div class="circle-icon"></div>
                                    <div class="vertical_line"></div>
                                </span>
                            </label>
                        </div>
                    </div>
                    <hr>
                    <h6 class="fw-semibold mb-2">{{ __('relayIngestTuning') }}</h6>
                    <div class="text-muted small mb-3">{{ __('relayIngestTuningHint') }}</div>
                    <div class="row">
                        <div class="col-lg-3 col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerSelectionStrategy') }}</label>
                                <select class="form-control" name="relay_worker_selection_strategy">
                                    <option value="round_robin" {{ $relay['relay_worker_selection_strategy'] === 'round_robin' ? 'selected' : '' }}>{{ __('relayWorkerSelectionRoundRobin') }}</option>
                                    <option value="fastest" {{ $relay['relay_worker_selection_strategy'] === 'fastest' ? 'selected' : '' }}>{{ __('relayWorkerSelectionFastest') }}</option>
                                    <option value="least_used" {{ $relay['relay_worker_selection_strategy'] === 'least_used' ? 'selected' : '' }}>{{ __('relayWorkerSelectionLeastUsed') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayIngestMaxWorkers') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_max_workers" min="0" max="100" value="{{ (int) $relay['relay_ingest_max_workers'] }}">
                                <div class="text-muted small">{{ __('relayIngestMaxWorkersHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayIngestConnections') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_connections" min="1" max="128" value="{{ (int) $relay['relay_ingest_connections'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayIngestConcurrency') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_concurrency" min="1" max="4" value="{{ (int) $relay['relay_ingest_concurrency'] }}">
                                <div class="text-muted small">{{ __('relayIngestConcurrencyHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayIngestTotalConnections') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_total_connections" min="0" max="512" value="{{ (int) $relay['relay_ingest_total_connections'] }}">
                                <div class="text-muted small">{{ __('relayIngestTotalConnectionsHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayIngestChunkMb') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_chunk_mb" min="1" max="16" value="{{ (int) $relay['relay_ingest_chunk_mb'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayIngestStreams') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_streams" min="1" max="8" value="{{ (int) $relay['relay_ingest_streams'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayIngestMaxPending') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_max_pending_chunks" min="0" max="10000" value="{{ (int) $relay['relay_ingest_max_pending_chunks'] }}">
                                <div class="text-muted small">{{ __('relayIngestMaxPendingHint') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-3 col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerMinSpeed') }}</label>
                                <input type="number" class="form-control" name="relay_worker_min_speed_kbps" min="0" max="100000" value="{{ (int) $relay['relay_worker_min_speed_kbps'] }}">
                                <div class="text-muted small">{{ __('relayWorkerMinSpeedHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerMaxLatency') }}</label>
                                <input type="number" class="form-control" name="relay_worker_max_latency_ms" min="0" max="60000" value="{{ (int) $relay['relay_worker_max_latency_ms'] }}">
                                <div class="text-muted small">{{ __('relayWorkerMaxLatencyHint') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer p-0 mb-4">
                        <button type="submit" class="btn theme-btn text-white">{{ __('save') }}</button>
                    </div>
                </form>

                <hr>

                <h6 class="fw-semibold mb-3">{{ __('relayWorkerAdd') }}</h6>
                <div class="text-muted small mb-3">{{ __('relayWorkerAddHint') }}</div>
                <form id="workerAddForm" method="POST" autocomplete="off">
                    @csrf
                    <div class="row">
                        <div class="col-lg-3 col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerName') }}</label>
                                <input type="text" class="form-control" name="name" maxlength="80" required placeholder="worker-1">
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerBaseUrl') }}</label>
                                <input type="url" class="form-control" name="base_url" maxlength="500" required placeholder="https://name.account.workers.dev">
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerApiKey') }}</label>
                                <input type="password" class="form-control" name="api_key" maxlength="512" required autocomplete="new-password" placeholder="x-proxy-key">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerPriority') }}</label>
                                <input type="number" class="form-control" name="priority" min="0" max="10000" value="100">
                                <div class="text-muted small">{{ __('relayWorkerPriorityHint') }}</div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="enabled" value="0">
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="workerAddEnabled" name="enabled" value="1" checked>
                        <label class="form-check-label" for="workerAddEnabled">{{ __('relayWorkerEnabled') }}</label>
                    </div>
                    <div id="workerAddResult" class="small mb-2"></div>
                    <button type="submit" class="btn theme-btn text-white">{{ __('relayWorkerAdd') }}</button>
                </form>

                <hr>

                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="fw-semibold mb-0">{{ __('relayWorkerStatus') }}</h6>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="testAllWorkersBtn">{{ __('relayWorkerTestAll') }}</button>
                </div>
                <div id="workerTestResult" class="small mb-2"></div>
                <div class="table-responsive">
                    <table class="table table-striped w-100 mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('relayWorkerName') }}</th>
                                <th>{{ __('relayWorkerBaseUrl') }}</th>
                                <th>{{ __('relayWorkerStatus') }}</th>
                                <th>{{ __('relayWorkerLatency') }}</th>
                                <th>{{ __('relayWorkerSpeed') }}</th>
                                <th>{{ __('relayWorkerLastChecked') }}</th>
                                <th>{{ __('relayWorkerActions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($workers as $worker)
                                @php
                                    $status = $worker->last_status ?: 'unknown';
                                    $statusClass = $status === 'ok' ? 'bg-success' : ($status === 'failed' ? 'bg-danger' : 'bg-secondary');
                                    $statusLabel = $status === 'ok' ? __('relayWorkerOnline') : ($status === 'failed' ? __('relayWorkerOffline') : __('relayWorkerUnknown'));
                                @endphp
                                <tr data-worker-id="{{ $worker->id }}">
                                    <td>
                                        <strong>{{ $worker->name }}</strong>
                                        <br>
                                        <span class="badge {{ $worker->has_api_key ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                            {{ $worker->has_api_key ? __('relayWorkerKeyStored') : __('relayWorkerNoKey') }}
                                        </span>
                                    </td>
                                    <td class="text-break" title="{{ $worker->base_url }}">{{ \Illuminate\Support\Str::limit($worker->base_url, 55) }}</td>
                                    <td>
                                        <span class="badge worker-status {{ $statusClass }}">{{ $statusLabel }}</span>
                                        @if($worker->last_error)
                                            <div class="text-danger small mt-1 worker-error" title="{{ $worker->last_error }}">{{ \Illuminate\Support\Str::limit($worker->last_error, 60) }}</div>
                                        @endif
                                    </td>
                                    <td class="worker-latency">{{ $worker->last_latency_ms !== null ? $worker->last_latency_ms . ' ms' : '-' }}</td>
                                    <td class="worker-speed">{{ $worker->human_speed }}</td>
                                    <td class="worker-checked">{{ $worker->last_checked_at ? $worker->last_checked_at->diffForHumans() : __('never') }}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input worker-toggle" type="checkbox" data-worker-id="{{ $worker->id }}" {{ $worker->enabled ? 'checked' : '' }}>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-primary worker-test" data-worker-id="{{ $worker->id }}">{{ __('relayWorkerTest') }}</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger worker-delete" data-worker-id="{{ $worker->id }}">{{ __('relayWorkerDelete') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">{{ __('relayWorkerNoWorkers') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
