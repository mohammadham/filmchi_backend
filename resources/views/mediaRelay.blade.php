@extends('include.app')
@section('script')
<script src="{{ asset('assets/script/mediaRelay.js') }}"></script>
@endsection

@section('content')
<section class="section">
    <div class="card">
        <div class="card-header">
            <div class="page-title w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <h4 class="mb-0 fw-semibold">{{ __('mediaRelay') }}</h4>
                    <button type="button" class="btn btn-primary text-light px-4" data-bs-toggle="modal" data-bs-target="#addRelayFileModal">
                        {{ __('addRelayFile') }}
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">

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

            <table class="table table-striped w-100" id="relayFileTable">
                <thead>
                    <tr>
                        <th>{{ __('title') }}</th>
                        <th width="110px">{{ __('relaySize') }}</th>
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
