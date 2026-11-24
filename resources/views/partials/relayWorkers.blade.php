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
                            <span class="badge bg-success">{{ __('relayWorkerOnline') }}: {{ $workers->filter(fn ($w) => $w->last_status === 'ok' && $w->auto_disabled_at === null)->count() }}</span>
                            <span class="badge bg-danger">{{ __('relayWorkerOffline') }}: {{ $workers->filter(fn ($w) => $w->last_status === 'failed' && $w->auto_disabled_at === null)->count() }}</span>
                            <span class="badge bg-warning text-dark">{{ __('relayWorkerAutoDisabled') }}: {{ $workers->filter(fn ($w) => $w->auto_disabled_at !== null)->count() }}</span>
                            <span class="badge bg-secondary">{{ __('relayWorkerStatus') }}: {{ $workers->count() }}</span>
                            <span class="badge bg-info">{{ __('relayRuntimeActive') }}: <span id="relayRuntimeActiveValue">{{ (int) $relayRuntime['active'] }}</span></span>
                            {{-- Pool total and lanes wrap as one group; a lane chip never splits. --}}
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span class="badge bg-primary text-nowrap">{{ __('relayRuntimeWorkers') }}: <span id="relayRuntimeWorkersValue">{{ (int) $relayRuntime['workers_live'] }}/{{ (int) $relayRuntime['workers_desired'] }}</span></span>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    @foreach($relayRuntime['roles'] as $role => $roleCounts)
                                        <span class="badge bg-primary-subtle text-primary text-nowrap" id="relayRuntimeRole{{ ucfirst($role) }}Badge">
                                            {{ __('relayRole' . ucfirst($role)) }}: <span id="relayRuntimeRole{{ ucfirst($role) }}Value">{{ (int) $roleCounts['live'] }}/{{ (int) $roleCounts['desired'] }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                            <span class="badge bg-secondary">{{ __('relayRuntimeQueued') }}: <span id="relayRuntimeQueuedValue">{{ (int) $relayRuntime['queued'] }}</span></span>
                            <span class="badge bg-warning text-dark">{{ __('relayRuntimeRetrying') }}: <span id="relayRuntimeRetryingValue">{{ (int) $relayRuntime['retrying'] }}</span></span>
                            <span class="badge bg-danger">{{ __('relayRuntimeFailed') }}: <span id="relayRuntimeFailedValue">{{ (int) $relayRuntime['failed'] }}</span></span>
                            <span class="badge bg-dark">{{ __('relayRuntimeConnections') }}: <span id="relayRuntimeConnectionsValue">{{ (int) $relayRuntime['connections_in_use'] }}</span></span>
                            <span class="badge bg-success">{{ __('relayRuntimeTotalSpeed') }}: <span id="relayRuntimeTotalSpeedValue">{{ $relayRuntime['total_speed'] }}</span></span>
                            <span class="badge bg-info">{{ __('relayIngestDiskBudget') }}: {{ $relayRuntime['disk_budget_gb'] }} GB / {{ $relayRuntime['disk_downloads'] }}</span>
                            <span class="badge {{ $relayRuntime['supervisor_down'] ? 'bg-danger' : 'bg-success' }}" id="relayHeartbeatBadge">
                                {{ __('relayRuntimeHeartbeat') }}: <span id="relayHeartbeatValue">{{ $relayRuntime['heartbeat_label'] }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body px-4">
                <div id="relaySupervisorAlert" class="alert alert-danger d-flex align-items-center gap-2 {{ $relayRuntime['supervisor_down'] ? '' : 'd-none' }}" role="alert">
                    <strong>{{ __('relayRuntimeSupervisorDown') }}</strong>
                </div>
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
                                    <option value="chunk_speed" {{ $relay['relay_worker_selection_strategy'] === 'chunk_speed' ? 'selected' : '' }}>{{ __('relayWorkerSelectionChunkSpeed') }}</option>
                                </select>
                                <div class="text-muted small">{{ __('relayWorkerSelectionChunkSpeedHint') }}</div>
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
                                <label class="form-label">{{ __('relayIngestDiskPercent') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_disk_percent" min="0" max="95" value="{{ (int) $relay['relay_ingest_disk_percent'] }}">
                                <div class="text-muted small">{{ __('relayIngestDiskPercentHint') }}</div>
                                <div class="text-muted small">
                                    {{ __('relayIngestDiskFree') }}: {{ $relayRuntime['disk_free_gb'] }} GB ·
                                    {{ __('relayIngestDiskBudget') }}: {{ $relayRuntime['disk_budget_gb'] }} GB ·
                                    {{ $relayRuntime['disk_downloads'] }} {{ __('relayIngestDiskSlots') }}
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayUploadConcurrency') }}</label>
                                <input type="number" class="form-control" name="relay_upload_concurrency" min="1" max="4" value="{{ (int) $relay['relay_upload_concurrency'] }}">
                                <div class="text-muted small">{{ __('relayUploadConcurrencyHint') }}</div>
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

                    <hr>
                    <h6 class="fw-semibold mb-2">{{ __('relayWorkerHealthPolicy') }}</h6>
                    <div class="text-muted small mb-3">{{ __('relayWorkerHealthPolicyHint') }}</div>
                    <div class="row">
                        <div class="col-lg-4 col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="workerAutoDisable" value="1" {{ $relay['relay_worker_auto_disable'] ? 'checked' : '' }}>
                                <label class="form-check-label" for="workerAutoDisable">{{ __('relayWorkerAutoDisable') }}</label>
                                <div class="text-muted small">{{ __('relayWorkerAutoDisableHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerFailureThreshold') }}</label>
                                <input type="number" class="form-control" name="relay_worker_failure_threshold" min="1" max="20" value="{{ (int) $relay['relay_worker_failure_threshold'] }}">
                                <div class="text-muted small">{{ __('relayWorkerFailureThresholdHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerFailureWindow') }}</label>
                                <input type="number" class="form-control" name="relay_worker_failure_window" min="30" max="86400" value="{{ (int) $relay['relay_worker_failure_window'] }}">
                                <div class="text-muted small">{{ __('relayWorkerFailureWindowHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerBackoffBase') }}</label>
                                <input type="number" class="form-control" name="relay_worker_backoff_base" min="1" max="3600" value="{{ (int) $relay['relay_worker_backoff_base'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerBackoffMax') }}</label>
                                <input type="number" class="form-control" name="relay_worker_backoff_max" min="1" max="86400" value="{{ (int) $relay['relay_worker_backoff_max'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerProbeSuccessThreshold') }}</label>
                                <input type="number" class="form-control" name="relay_worker_probe_success_threshold" min="1" max="10" value="{{ (int) $relay['relay_worker_probe_success_threshold'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerProbeInterval') }}</label>
                                <input type="number" class="form-control" name="relay_worker_probe_interval" min="10" max="3600" value="{{ (int) $relay['relay_worker_probe_interval'] }}">
                                <div class="text-muted small">{{ __('relayWorkerProbeIntervalHint') }}</div>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <h6 class="fw-semibold mb-2">{{ __('relayRetryPolicy') }}</h6>
                    <div class="text-muted small mb-3">{{ __('relayRetryPolicyHint') }}</div>
                    <div class="row">
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayDownloadMaxAttempts') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_retry_max_attempts" min="1" max="10" value="{{ (int) $relay['relay_ingest_retry_max_attempts'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayDownloadRetryDelay') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_retry_delay" min="5" max="3600" value="{{ (int) $relay['relay_ingest_retry_delay'] }}">
                                <div class="text-muted small">{{ __('relayRetryDelayHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayUploadMaxAttempts') }}</label>
                                <input type="number" class="form-control" name="relay_upload_retry_max_attempts" min="1" max="10" value="{{ (int) $relay['relay_upload_retry_max_attempts'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayUploadRetryDelay') }}</label>
                                <input type="number" class="form-control" name="relay_upload_retry_delay" min="5" max="3600" value="{{ (int) $relay['relay_upload_retry_delay'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayChunkRetryBudget') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_chunk_retry_budget" min="30" max="3600" value="{{ (int) $relay['relay_ingest_chunk_retry_budget'] }}">
                                <div class="text-muted small">{{ __('relayChunkRetryBudgetHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayChunkStallTimeout') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_chunk_stall_timeout" min="10" max="600" value="{{ (int) $relay['relay_ingest_chunk_stall_timeout'] }}">
                                <div class="text-muted small">{{ __('relayChunkStallTimeoutHint') }}</div>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <h6 class="fw-semibold mb-2">{{ __('relaySpeedStrategy') }}</h6>
                    <div class="text-muted small mb-3">{{ __('relaySpeedStrategyHint') }}</div>
                    <div class="row">
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relaySpeedCheckSeconds') }}</label>
                                <input type="number" step="0.1" class="form-control" name="relay_ingest_speed_check_seconds" min="3" max="60" value="{{ (float) $relay['relay_ingest_speed_check_seconds'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relaySpeedWindowSamples') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_speed_window_samples" min="2" max="60" value="{{ (int) $relay['relay_ingest_speed_window_samples'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relaySpeedDropRatio') }}</label>
                                <input type="number" step="0.05" class="form-control" name="relay_ingest_speed_drop_ratio" min="0.1" max="1" value="{{ (float) $relay['relay_ingest_speed_drop_ratio'] }}">
                                <div class="text-muted small">{{ __('relaySpeedDropRatioHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relaySpeedDropConfirmations') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_speed_drop_confirmations" min="1" max="10" value="{{ (int) $relay['relay_ingest_speed_drop_confirmations'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relaySpeedReprobeChunks') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_speed_reprobe_chunks" min="1" max="20" value="{{ (int) $relay['relay_ingest_speed_reprobe_chunks'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relaySpeedReprobeCooldown') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_speed_reprobe_cooldown" min="5" max="600" value="{{ (int) $relay['relay_ingest_speed_reprobe_cooldown'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayPoolRefreshSeconds') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_pool_refresh_seconds" min="3" max="120" value="{{ (int) $relay['relay_ingest_pool_refresh_seconds'] }}">
                                <div class="text-muted small">{{ __('relayPoolRefreshSecondsHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayWorkerProbeConcurrency') }}</label>
                                <input type="number" class="form-control" name="relay_worker_probe_concurrency" min="1" max="2" value="{{ (int) $relay['relay_worker_probe_concurrency'] }}">
                                <div class="text-muted small">{{ __('relayWorkerProbeConcurrencyHint') }}</div>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <h6 class="fw-semibold mb-2">{{ __('relayStreamAndChunkPolicy') }}</h6>
                    <div class="text-muted small mb-3">{{ __('relayStreamAndChunkPolicyHint') }}</div>
                    <div class="row">
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayStreamStallTimeout') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_stall_timeout" min="30" max="3600" value="{{ (int) $relay['relay_ingest_stall_timeout'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayStreamMinKbps') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_stream_min_kbps" min="0" max="60000" value="{{ (int) $relay['relay_ingest_stream_min_kbps'] }}">
                                <div class="text-muted small">{{ __('relayStreamMinKbpsHint') }}</div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayStreamStallSeconds') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_stream_stall_seconds" min="30" max="3600" value="{{ (int) $relay['relay_ingest_stream_stall_seconds'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayMinChunkMb') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_min_chunk_mb" min="1" max="16" value="{{ (int) $relay['relay_ingest_min_chunk_mb'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayMaxChunkMb') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_max_chunk_mb" min="1" max="64" value="{{ (int) $relay['relay_ingest_max_chunk_mb'] }}">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayIngestTimeout') }}</label>
                                <input type="number" class="form-control" name="relay_ingest_timeout" min="60" max="3600" value="{{ (int) $relay['relay_ingest_timeout'] }}">
                                <div class="text-muted small">{{ __('relayIngestTimeoutHint') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer p-0 mb-4">
                        <button type="submit" class="btn theme-btn text-white">{{ __('save') }}</button>
                    </div>
                </form>

                <hr>

                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <h6 class="fw-semibold mb-0">{{ __('relayRuntimeActive') }}</h6>
                    <span class="text-muted small">{{ count($relayRuntime['downloads']) }} / {{ (int) $relayRuntime['active'] }}</span>
                </div>
                <div class="table-responsive mb-4">
                    <table class="table table-striped align-middle w-100 mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('relayRuntimeFile') }}</th>
                                <th>{{ __('relayRuntimeStatus') }}</th>
                                <th style="min-width: 160px;">{{ __('relayRuntimeProgress') }}</th>
                                <th>{{ __('relayRuntimeSpeed') }}</th>
                            </tr>
                        </thead>
                        <tbody id="relayRuntimeDownloadsBody">
                            @forelse($relayRuntime['downloads'] as $download)
                                @php
                                    $downloadStatusClass = $download['status'] === 'uploading' ? 'bg-warning text-dark' : 'bg-info text-dark';
                                @endphp
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-break">{{ $download['name'] }}</div>
                                        @if($download['quality'])
                                            <span class="badge bg-secondary-subtle text-secondary">{{ $download['quality'] }}</span>
                                        @endif
                                    </td>
                                    <td><span class="badge {{ $downloadStatusClass }}">{{ $download['status_label'] }}</span></td>
                                    <td>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar" role="progressbar" style="width: {{ (int) $download['progress'] }}%" aria-valuenow="{{ (int) $download['progress'] }}" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <div class="small text-muted mt-1">{{ (int) $download['progress'] }}%</div>
                                    </td>
                                    <td class="text-nowrap">{{ $download['speed'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">{{ __('relayRuntimeNoActive') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

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

                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <h6 class="fw-semibold mb-0">{{ __('relayWorkerStatus') }}</h6>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-warning btn-sm" id="testDegradedWorkersBtn">{{ __('relayWorkerTestDegraded') }}</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="testAllWorkersBtn">{{ __('relayWorkerTestAll') }}</button>
                    </div>
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
                                <th>{{ __('relayWorkerFailures') }}</th>
                                <th>{{ __('relayWorkerNextProbe') }}</th>
                                <th>{{ __('relayWorkerLastChecked') }}</th>
                                <th>{{ __('relayWorkerActions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($workers as $worker)
                                @php
                                    $autoDisabled = $worker->auto_disabled_at !== null;
                                    $status = !$worker->enabled
                                        ? 'disabled'
                                        : ($autoDisabled ? 'auto_disabled' : ($worker->last_status ?: 'unknown'));
                                    $statusClass = match ($status) {
                                        'ok' => 'bg-success',
                                        'failed' => 'bg-danger',
                                        'auto_disabled' => 'bg-warning text-dark',
                                        'disabled' => 'bg-secondary',
                                        default => 'bg-secondary',
                                    };
                                    $statusLabel = match ($status) {
                                        'ok' => __('relayWorkerOnline'),
                                        'failed' => __('relayWorkerOffline'),
                                        'auto_disabled' => __('relayWorkerAutoDisabled'),
                                        'disabled' => __('relayWorkerDisabled'),
                                        default => __('relayWorkerUnknown'),
                                    };
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
                                        @if($worker->disabled_reason)
                                            <div class="text-warning small mt-1 worker-disabled-reason" title="{{ $worker->disabled_reason }}">{{ \Illuminate\Support\Str::limit($worker->disabled_reason, 70) }}</div>
                                        @endif
                                        @if($worker->last_error)
                                            <div class="text-danger small mt-1 worker-error" title="{{ $worker->last_error }}">{{ \Illuminate\Support\Str::limit($worker->last_error, 60) }}</div>
                                        @endif
                                    </td>
                                    <td class="worker-latency">{{ $worker->last_latency_ms !== null ? $worker->last_latency_ms . ' ms' : '-' }}</td>
                                    <td class="worker-speed">{{ $worker->human_speed }}</td>
                                    <td class="worker-failures">{{ (int) $worker->failure_count }}</td>
                                    <td class="worker-next-probe">{{ $worker->next_probe_at ? $worker->next_probe_at->diffForHumans() : '-' }}</td>
                                    <td class="worker-checked">{{ $worker->last_checked_at ? $worker->last_checked_at->diffForHumans() : __('never') }}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <div class="form-check form-switch mb-0" title="{{ $worker->enabled ? __('relayWorkerEnabled') : __('relayWorkerDisabled') }}">
                                                <input class="form-check-input worker-toggle" type="checkbox" data-worker-id="{{ $worker->id }}" {{ $worker->enabled ? 'checked' : '' }}>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-primary worker-test" data-worker-id="{{ $worker->id }}">{{ __('relayWorkerTest') }}</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger worker-delete" data-worker-id="{{ $worker->id }}">{{ __('relayWorkerDelete') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">{{ __('relayWorkerNoWorkers') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
