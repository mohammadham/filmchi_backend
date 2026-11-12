@extends('include.app')
@section('script')
<script src="{{ asset('assets/script/mediaRelay.js') }}?v={{ @filemtime(public_path('assets/script/mediaRelay.js')) }}"></script>
@endsection

@section('content')
<section class="section">
    <div class="card">
        <div class="card-header">
            <div class="page-title w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <h4 class="mb-0 fw-semibold">{{ __('mediaRelay') }}</h4>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-secondary text-light px-4" data-bs-toggle="modal" data-bs-target="#addRelayUrlModal">
                            {{ __('addFromLink') }}
                        </button>
                        <button type="button" class="btn btn-primary text-light px-4" data-bs-toggle="modal" data-bs-target="#addRelayFileModal">
                            {{ __('addRelayFile') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body">

            <div class="alert alert-info">
                {{ __('mediaRelayHint') }}
            </div>

            @if(!$stateStoreOk)
            <div class="alert alert-danger">
                {{ __('stateStore') }}: <strong>{{ $stateStore }}</strong> — this store is not usable, so demand cannot be
                counted and no file will ever be promoted automatically. Check the cache configuration
                (for example, <code>redis</code> needs the phpredis extension or predis installed).
            </div>
            @endif

            @if(!$statePersistent)
            <div class="alert alert-warning">
                {{ __('stateStore') }}: <strong>{{ $stateStore }}</strong> — demand counters are discarded after every
                request, so files will never be promoted automatically. Set <code>CACHE_DRIVER=redis</code>
                (or <code>RELAY_STATE_STORE=redis</code>) to enable demand-based cache tiering.
            </div>
            @endif

            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card mb-0">
                        <div class="card-body">
                            <div class="text-muted small">{{ __('primaryStorage') }}</div>
                            <h5 class="mb-0 fw-semibold">
                                @if(!$telegramConfigured)
                                    <span class="text-warning">{{ __('relayNotConfigured') }}</span>
                                @elseif($telegram['ok'])
                                    <span class="text-success">online</span>
                                @else
                                    <span class="text-danger">offline</span>
                                @endif
                            </h5>
                            <div class="text-muted small mt-1">{{ $telegram['message'] }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card mb-0">
                        <div class="card-body">
                            <div class="text-muted small">{{ __('cacheTier') }}</div>
                            <h5 class="mb-0 fw-semibold">
                                @if(!$ninedriveConfigured)
                                    <span class="text-warning">{{ __('relayNotConfigured') }}</span>
                                @elseif($ninedrive['ok'])
                                    <span class="text-success">online</span>
                                @else
                                    <span class="text-danger">offline</span>
                                @endif
                            </h5>
                            <div class="text-muted small mt-1">{{ $ninedrive['message'] }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card mb-0">
                        <div class="card-body">
                            <div class="text-muted small">{{ __('warmFiles') }} / {{ __('coldFiles') }}</div>
                            <h5 class="mb-0 fw-semibold">{{ $cached }} / {{ $cold }}</h5>
                            <div class="text-muted small mt-1">{{ __('queueWorker') }}: {{ $queue }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card mb-0">
                        <div class="card-body">
                            <div class="text-muted small">{{ __('demandThreshold') }}</div>
                            <h5 class="mb-0 fw-semibold">{{ $threshold }}</h5>
                            <div class="text-muted small mt-1">{{ __('stateStore') }}: {{ $stateStore }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-4 col-sm-6 mb-3">
                    <div class="card mb-0">
                        <div class="card-body">
                            <div class="text-muted small">{{ __('relayUploadAvgSpeed') }}</div>
                            <h5 class="mb-0 fw-semibold">{{ \App\Services\Relay\UploadMetrics::formatSpeed($uploadStats['avg_speed_bps']) }}</h5>
                            <div class="text-muted small mt-1">{{ __('relayUploadSamples') }}: {{ $uploadStats['count'] }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6 mb-3">
                    <div class="card mb-0">
                        <div class="card-body">
                            <div class="text-muted small">{{ __('relayUploadAvgTime') }}</div>
                            <h5 class="mb-0 fw-semibold">{{ \App\Services\Relay\UploadMetrics::formatDuration($uploadStats['avg_duration_ms']) }}</h5>
                            <div class="text-muted small mt-1">{{ __('relayUploadSampleHint') }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6 mb-3">
                    <div class="card mb-0">
                        <div class="card-body">
                            <div class="text-muted small">{{ __('relayUploadPhase') }}</div>
                            <div class="small mt-2">{{ __('relayUploadPhaseHint') }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="mb-0 fw-semibold">آپلودنشده‌ها / ناموفق‌ها</h5>
                    <span class="badge bg-danger">{{ $failedSourcesCount }}</span>
                </div>
                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success py-2">{{ session('success') }}</div>
                    @endif
                    @if(session('error'))
                        <div class="alert alert-danger py-2">{{ session('error') }}</div>
                    @endif

                    @if($failedSources->count())
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th width="60px">ID</th>
                                        <th width="80px">کیفیت</th>
                                        <th>منبع</th>
                                        <th width="110px">وضعیت آپلود</th>
                                        <th width="70px">تلاش</th>
                                        <th>خطا</th>
                                        <th width="130px">آخرین تغییر</th>
                                        <th width="130px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($failedSources as $source)
                                        <tr>
                                            <td>{{ $source->id }}</td>
                                            <td>{{ $source->quality ?: '—' }}</td>
                                            <td dir="ltr" style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                                <span title="{{ $source->source }}">{{ \Illuminate\Support\Str::limit($source->source, 90) }}</span>
                                            </td>
                                            <td>{{ $source->upload_status ?: 'قبل از آپلود' }}</td>
                                            <td>{{ (int) $source->upload_attempts }}</td>
                                            <td class="text-danger" style="max-width:300px">{{ \Illuminate\Support\Str::limit((string) $source->ingest_error, 140) }}</td>
                                            <td>{{ optional($source->updated_at)->diffForHumans() }}</td>
                                            <td>
                                                <form method="POST" action="{{ route('requeueRelaySource') }}" onsubmit="return confirm('این منبع دوباره به صف اضافه شود؟');">
                                                    @csrf
                                                    <input type="hidden" name="source_id" value="{{ $source->id }}">
                                                    <button type="submit" class="btn btn-sm btn-primary text-light">افزودن به صف</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if($failedSourcesCount > $failedSources->count())
                            <p class="text-muted small mt-2 mb-0">نمایش {{ $failedSources->count() }} مورد از {{ $failedSourcesCount }} مورد؛ قدیمی‌ترها در دیتابیس باقی هستند.</p>
                        @endif
                    @else
                        <p class="text-muted mb-0">فایل ناموفقی که به آپلود نرسیده باشد وجود ندارد.</p>
                    @endif
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
                <div class="d-flex align-items-center gap-2">
                    <select id="relayStateFilter" class="form-control form-control-sm" style="width:auto">
                        <option value="">{{ __('all') }}</option>
                        <option value="warm">{{ __('warmFiles') }}</option>
                        <option value="cold">{{ __('coldFiles') }}</option>
                        <option value="queued">queued</option>
                        <option value="failed">failed</option>
                    </select>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span id="relayBulkCount" class="text-muted small"></span>
                    <button type="button" id="bulkPromote" class="btn btn-sm btn-warning text-white" disabled>{{ __('promote') }}</button>
                    <button type="button" id="bulkEvict" class="btn btn-sm btn-secondary" disabled>{{ __('evict') }}</button>
                    <button type="button" id="bulkDelete" class="btn btn-sm btn-danger" disabled>{{ __('delete') }}</button>
                </div>
            </div>

            <table class="table table-striped w-100" id="relayFileTable">
                <thead>
                        <tr>
                            <th width="40px"><input type="checkbox" id="relaySelectAll"></th>
                            <th width="90px">{{ __('thumb') }}</th>
                            <th>{{ __('title') }}</th>
                        <th width="110px">{{ __('relaySize') }}</th>
                        <th width="125px">{{ __('relayUploadSpeed') }}</th>
                        <th width="125px">{{ __('relayUploadTime') }}</th>
                        <th width="110px">{{ __('relayTier') }}</th>
                        <th width="90px">{{ __('relayDemand') }}</th>
                        <th width="90px">{{ __('relayHits') }}</th>
                        <th width="150px">{{ __('relayLastAccess') }}</th>
                        <th class="text-end" width="340px">{{ __('action') }}</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</section>

<!-- Upload to Telegram Drive -->
<div class="modal fade" id="addRelayFileModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold mb-0 text-white">{{ __('addRelayFile') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addRelayFileForm" method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="form-group">
                            <label for="relay_title" class="form-label">{{ __('title') }}</label>
                            <input name="name" id="relay_title" type="text" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="relay_file" class="form-label">{{ __('sourceFile') }}</label>
                            <div class="file-input form-control w-100">
                                <input name="file" id="relay_file" type="file" class="form-control" required>
                                <span class='button'>{{ __('choose') }}</span>
                                <span class='label' data-js-label>{{ __('noFileSelected') }}</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="progress mb-2" id="relayProgress" style="height: 25px; display: none;">
                                <div class="progress-bar" id="relayProgressBar" role="progressbar" style="width: 0%;">0%</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn text-light" data-bs-dismiss="modal">{{ __('close') }}</button>
                    <button type="submit" class="btn theme-btn text-light px-4 saveButton">{{ __('save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add from link -->
<div class="modal fade" id="addRelayUrlModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold mb-0 text-white">{{ __('addFromLink') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addRelayUrlForm" method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="relay_url" class="form-label">{{ __('sourceURL') }}</label>
                        <input name="url" id="relay_url" type="url" class="form-control" placeholder="https://..." required>
                        <span class="text-muted small">{{ __('addFromLinkHint') }}</span>
                    </div>
                    <div class="form-group">
                        <label for="relay_url_name" class="form-label">{{ __('title') }}</label>
                        <input name="name" id="relay_url_name" type="text" class="form-control" placeholder="{{ __('optional') }}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn text-light" data-bs-dismiss="modal">{{ __('close') }}</button>
                    <button type="submit" class="btn theme-btn text-light px-4 saveButton">{{ __('save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Relay links -->
<div class="modal fade" id="relayLinksModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold mb-0 text-white">{{ __('relayLinks') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="relay_link_file_id">

                <div class="row align-items-end mb-3">
                    <div class="col-md-3">
                        <label class="form-label">{{ __('type') }}</label>
                        <select id="relay_link_mode" class="form-control selectric">
                            <option value="stream">stream</option>
                            <option value="download">download</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('password') }}</label>
                        <input type="text" id="relay_link_password" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Max</label>
                        <input type="number" min="0" id="relay_link_max" class="form-control" value="0">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">TTL (s)</label>
                        <input type="number" min="0" id="relay_link_ttl" class="form-control" value="0">
                    </div>
                    <div class="col-md-2 text-end">
                        <button type="button" id="createRelayLink" class="btn theme-btn text-light w-100">{{ __('createLink') }}</button>
                    </div>
                </div>

                <table class="table table-striped w-100" id="relayLinkTable">
                    <thead>
                        <tr>
                            <th>{{ __('links') }}</th>
                            <th width="110px">{{ __('type') }}</th>
                            <th width="110px">{{ __('relayHits') }}</th>
                            <th width="110px">{{ __('status') }}</th>
                            <th width="160px">{{ __('expires') }}</th>
                            <th class="text-end" width="320px">{{ __('action') }}</th>
                        </tr>
                    </thead>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn text-light" data-bs-dismiss="modal">{{ __('close') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection
