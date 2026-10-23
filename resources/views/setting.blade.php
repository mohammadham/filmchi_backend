@extends('include.app')
@section('script')
<script src="{{ asset('assets/script/setting.js') }}?v={{ @filemtime(public_path('assets/script/setting.js')) }}"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const awsConfig = @json($awsConfig);
        const doConfig = @json($doConfig);

        const isAwsConfigComplete = Object.values(awsConfig).every(value => value !== null && value !== '');
        const awsRadio = document.getElementById('awsRadio');
        const awsNote = document.getElementById('awsNote');
        if (!isAwsConfigComplete) {
            awsRadio.disabled = true;
            $('#awsNote').removeClass('d-none');
        } else {
            $('#localRadio').attr('checked');
            $('#awsNote').addClass('d-none');
        }

        const isDoConfigComplete = Object.values(doConfig).every(value => value !== null && value !== '');
        const doRadio = document.getElementById('doRadio');
        if (!isDoConfigComplete) {
            doRadio.disabled = true;
            $('#doNote').removeClass('d-none');
        } else {
            $('#localRadio').attr('checked');
            $('#doNote').addClass('d-none');
        }
    });
</script>

@endsection

@section('content')

<div class="row same-height-card">
    <div class="col-lg-6 col-md-12 col-sm-12">
        <div class="card">
            <div class="card-header">
                <div class="page-title w-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <h4 class="mb-0 fw-semibold">{{ __('settings') }}</h4>
                    </div>
                </div>
            </div>
            <div class="card-body px-4">
                <form id="settingsForm" method="post" enctype="multipart/form-data" class="form-border" autocomplete="off">
                    @csrf
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="currency" class="form-label">{{ __('appNameTitle') }}</label>
                                <input value="{{ $setting->app_name }}" type="text" name="app_name" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="currency" class="form-label">{{ __('skipTime') }}</label>
                                <input value="{{ $setting->videoad_skip_time }}" type="text" name="videoad_skip_time" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer p-0">
                        <button type="button" class="btn"></button>
                        <button type="submit" class="btn theme-btn text-white">{{ __('save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6 col-md-12 col-sm-12">
        <div class="card">
            <div class="card-header">
                <div class="page-title w-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <h4 class="mb-0 fw-semibold">{{ __('changePassword') }}</h4>
                    </div>
                </div>
            </div>
            <div class="card-body px-4">
                <form id="changePasswordForm" method="POST">
                    <div class="row">
                        <div class="col-lg-6 col-md-6 col-sm-12 position-relative">
                            <div class="form-group">
                                <label for="appName" class="form-label">{{ __('oldPassword') }}</label>
                                <input type="password" class="form-control" name="user_password" id="userPassword" required="">
                                <div class="password-icon">
                                    <i data-feather="eye"></i>
                                    <i data-feather="eye-off"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12 position-relative">
                            <div class="form-group">
                                <label for="appName" class="form-label">{{ __('newPassword') }}</label>
                                <input type="password" class="form-control" name="new_password" id="newPassword" required="">
                                <div class="password-icon">
                                    <i data-feather="eye" class="eye1"></i>
                                    <i data-feather="eye-off" class="eye-off1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer p-0">
                        <button type="button" class="btn"></button>
                        <button type="submit" class="btn theme-btn text-white">{{ __('changePassword') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-3">
        <div class="card">
            <div class="card-header">
                <div class="page-title w-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <h4 class="mb-0 fw-semibold">{{ __('storageSetting') }}</h4>
                    </div>
                </div>
            </div>
            <div class="card-body px-4">
                <form id="storageSettingForm" method="POST">
                    <div class="card w-auto p-3">
                        <div class="checkbox-slider d-flex align-items-center justify-content-between">
                            <span class="me-3">
                                {{ __('local') }}
                            </span>
                            <label>
                                <input type="radio" class="d-none" name="storage_type" value="0" id="localRadio" {{ $setting->storage_type == 0 ? 'checked' : '' }}>
                                <span class="toggle_background">
                                    <div class="circle-icon"></div>
                                    <div class="vertical_line"></div>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div class="card w-auto p-3">
                        <div class="checkbox-slider d-flex align-items-center justify-content-between">
                            <span class="me-3">
                                {{ __('AWSS3') }}
                                <br>
                                <span id="awsNote" class="text-danger"> {{ __('pleaseAddValuesInEnvFile') }} </span>
                            </span>
                            <label>
                                <input type="radio" class="d-none" name="storage_type" value="1" id="awsRadio" {{ $setting->storage_type == 1 ? 'checked' : '' }}>
                                <span class="toggle_background">
                                    <div class="circle-icon"></div>
                                    <div class="vertical_line"></div>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div class="card w-auto p-3">
                        <div class="checkbox-slider d-flex align-items-center justify-content-between">
                            <span class="me-3">
                                {{ __('digitalOceanSpace') }}
                                <br>
                                <span id="doNote" class="text-danger"> {{ __('pleaseAddValuesInEnvFile')}} </span>
                            </span>
                            <label>
                                <input type="radio" class="d-none" name="storage_type" value="2" id="doRadio" {{ $setting->storage_type == 2 ? 'checked' : '' }}>
                                <span class="toggle_background">
                                    <div class="circle-icon"></div>
                                    <div class="vertical_line"></div>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div class="card w-auto p-3">
                        <div class="checkbox-slider d-flex align-items-center justify-content-between">
                            <span class="me-3">
                                {{ __('combinedStorage') }}
                            </span>
                            <label>
                                <input type="radio" class="d-none" name="storage_type" value="3" id="combinedRadio" {{ $setting->storage_type == 3 ? 'checked' : '' }}>
                                <span class="toggle_background">
                                    <div class="circle-icon"></div>
                                    <div class="vertical_line"></div>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer p-0">
                        <button type="submit" class="btn theme-btn text-white">{{ __('save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <div class="page-title w-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <h4 class="mb-0 fw-semibold">{{ __('adsSettings') }}</h4>
                    </div>
                </div>
            </div>
            <div class="card-body px-4">
                <div class="row">
                    <div class="col-lg-6">
                        <div class="row">
                            <div class="col-xl-12 col-lg-6 mb-3">
                                <div class="card w-auto p-3 m-0">
                                    <div class="checkbox-slider d-flex align-items-center justify-content-between">
                                        <span class="me-3">
                                            {{ __('admobAndroidAds') }}
                                        </span>
                                        <label>
                                            <input type="checkbox" class="d-none is_admob_android" {{ $setting->is_admob_android == 1 ? 'checked' : '' }} name="is_admob_android">
                                            <span class="toggle_background">
                                                <div class="circle-icon"></div>
                                                <div class="vertical_line"></div>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-12 col-lg-6">
                                <div class="card w-auto p-3 m-0">
                                    <div class="checkbox-slider d-flex align-items-center justify-content-between">
                                        <span class="me-3">
                                            {{ __('admobiOSAds') }}
                                        </span>
                                        <label>
                                            <input type="checkbox" class="d-none is_admob_ios" {{ $setting->is_admob_ios == 1 ? 'checked' : '' }} name="is_admob_ios">
                                            <span class="toggle_background">
                                                <div class="circle-icon"></div>
                                                <div class="vertical_line"></div>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="row">
                            <div class="col-xl-12 col-lg-6 mb-3">
                                <div class="card w-auto p-3 m-0">
                                    <div class="checkbox-slider d-flex align-items-center justify-content-between">
                                        <span class="me-3">
                                            {{ __('customAndroidAds') }}
                                        </span>
                                        <label>
                                            <input type="checkbox" class="d-none is_custom_android" {{ $setting->is_custom_android == 1 ? 'checked' : '' }} name="is_custom_android">
                                            <span class="toggle_background">
                                                <div class="circle-icon"></div>
                                                <div class="vertical_line"></div>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-12 col-lg-6">
                                <div class="card w-auto p-3 m-0">
                                    <div class="checkbox-slider d-flex align-items-center justify-content-between">
                                        <span class="me-3">
                                            {{ __('customiOSAds') }}
                                        </span>
                                        <label>
                                            <input type="checkbox" class="d-none is_custom_ios" {{ $setting->is_custom_ios == 1 ? 'checked' : '' }} name="is_custom_ios">
                                            <span class="toggle_background">
                                                <div class="circle-icon"></div>
                                                <div class="vertical_line"></div>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card w-auto p-3">
            <div class="checkbox-slider d-flex align-items-center justify-content-between">
                <span class="me-3">
                    {{ __('enableDisableLiveTvTab') }}
                </span>
                <label>
                    <input type="checkbox" class="d-none enableDisableLiveTvTab" {{ $setting->is_live_tv_enable == 1 ? 'checked' : '' }} name="is_live_tv_enable">
                    <span class="toggle_background">
                        <div class="circle-icon"></div>
                        <div class="vertical_line"></div>
                    </span>
                </label>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <div class="page-title w-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <h4 class="mb-0 fw-semibold">{{ __('relayStorage') }}</h4>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary" id="tgStatusBadge">{{ __('primaryStorage') }}: -</span>
                            <span class="badge bg-secondary" id="nineStatusBadge">{{ __('cacheTier') }}: -</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body px-4">
                <form id="relaySettingForm" method="POST" autocomplete="off">
                    @csrf
                    <div class="card w-auto p-3 mb-3">
                        <div class="checkbox-slider d-flex align-items-center justify-content-between">
                            <span class="me-3">
                                {{ __('relayEnabled') }}
                                <br>
                                <span class="text-muted small">{{ __('relayStorageHint') }}</span>
                            </span>
                            <label>
                                <input type="checkbox" class="d-none" id="relayEnabled" name="relay_enabled" value="1" {{ $relay['relay_enabled'] ? 'checked' : '' }}>
                                <span class="toggle_background">
                                    <div class="circle-icon"></div>
                                    <div class="vertical_line"></div>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6">
                            <h6 class="fw-semibold mb-3">{{ __('relayTgSection') }}</h6>
                            <div class="form-group">
                                <label class="form-label">{{ __('relayBaseUrl') }}</label>
                                <input type="text" class="form-control" name="relay_tg_base_url" value="{{ $relay['relay_tg_base_url'] }}" placeholder="http://tdrive-app-1:8000">
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('relayUsername') }}</label>
                                        <input type="text" class="form-control" name="relay_tg_username" value="{{ $relay['relay_tg_username'] }}">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('relayPassword') }}</label>
                                        <input type="password" class="form-control" name="relay_tg_password" value="{{ $relay['relay_tg_password'] }}" placeholder="{{ __('leaveBlankToKeep') }}">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('relayApiKey') }}</label>
                                        <input type="password" class="form-control" name="relay_tg_api_key" value="{{ $relay['relay_tg_api_key'] }}" placeholder="{{ __('leaveBlankToKeep') }}">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('relayStorageChat') }}</label>
                                        <input type="text" class="form-control" name="relay_tg_storage_chat" value="{{ $relay['relay_tg_storage_chat'] }}">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <h6 class="fw-semibold mb-3">{{ __('relayNineSection') }}</h6>
                            <div class="form-group">
                                <label class="form-label">{{ __('relayBaseUrl') }}</label>
                                <input type="text" class="form-control" name="relay_nine_base_url" value="{{ $relay['relay_nine_base_url'] }}" placeholder="http://9drive-backend-1:4000">
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ __('relayApiKey') }}</label>
                                <input type="password" class="form-control" name="relay_nine_api_key" value="{{ $relay['relay_nine_api_key'] }}" placeholder="{{ __('leaveBlankToKeep') }}">
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('relayEmail') }}</label>
                                        <input type="text" class="form-control" name="relay_nine_email" value="{{ $relay['relay_nine_email'] }}">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('relayPassword') }}</label>
                                        <input type="password" class="form-control" name="relay_nine_password" value="{{ $relay['relay_nine_password'] }}" placeholder="{{ __('leaveBlankToKeep') }}">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('relayFolderId') }}</label>
                                        <input type="text" class="form-control" name="relay_nine_folder_id" value="{{ $relay['relay_nine_folder_id'] }}">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('relayServeMode') }}</label>
                                        <select class="form-control selectric" name="relay_nine_serve_mode">
                                            @foreach(['auto', 'share', 'drive'] as $mode)
                                                <option value="{{ $mode }}" {{ $relay['relay_nine_serve_mode'] == $mode ? 'selected' : '' }}>{{ $mode }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ __('relayFrontendUrl') }}</label>
                                <input type="text" class="form-control" name="relay_nine_frontend_url" value="{{ $relay['relay_nine_frontend_url'] }}" placeholder="https://9drive.sanduqche.top">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayPromoteThreshold') }}</label>
                                <input type="number" min="1" class="form-control" name="relay_promote_threshold" value="{{ $relay['relay_promote_threshold'] }}">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('relayDemandWindow') }}</label>
                                <input type="number" min="60" class="form-control" name="relay_demand_window" value="{{ $relay['relay_demand_window'] }}">
                            </div>
                        </div>
                    </div>

                    <div id="relayTestResult" class="small mb-2"></div>

                    <div class="modal-footer p-0">
                        <button type="button" class="btn btn-outline-primary" id="testRelayBtn">{{ __('testConnection') }}</button>
                        <button type="submit" class="btn theme-btn text-white">{{ __('save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection