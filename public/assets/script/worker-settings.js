$(document).ready(function () {
    var csrf = $('meta[name="csrf-token"]').attr('content') || '';
    var labels = window.workerSettingsLabels || {};
    var $addResult = $('#workerAddResult');
    var $testResult = $('#workerTestResult');

    function label(key, fallback) {
        return labels[key] || fallback;
    }

    function workerApiUrl(path) {
        return String(domainUrl || '').replace(/\/+$/, '') + '/' + path;
    }

    function workerError(xhr) {
        if (xhr && xhr.status === 422 && xhr.responseJSON) {
            var errors = xhr.responseJSON.errors || {};
            var messages = [];

            Object.keys(errors).forEach(function (key) {
                messages.push(errors[key].join(' '));
            });

            if (messages.length) {
                return messages.join(' ');
            }

            if (xhr.responseJSON.message) {
                return xhr.responseJSON.message;
            }
        }

        return 'Request failed.';
    }

    function setBusy(button, busy) {
        $(button).prop('disabled', busy);
    }

    $('#workerSystemForm').on('submit', function (event) {
        event.preventDefault();

        var formData = new FormData(this);
        formData.set('relay_worker_enabled', $('#workerSystemEnabled').prop('checked') ? 1 : 0);

        var $button = $(this).find('button[type="submit"]');
        setBusy($button, true);

        $.ajax({
            type: 'POST',
            url: workerApiUrl('saveWorkerSystem'),
            data: formData,
            contentType: false,
            processData: false,
            success: function (response) {
                if (response.status) {
                    showSuccessToast(label('systemSaved', 'Worker system saved.'));
                } else {
                    somethingWentWrongToast();
                }
            },
            error: function (xhr) {
                $testResult.removeClass('text-success').addClass('text-danger').text(workerError(xhr));
                somethingWentWrongToast();
            },
            complete: function () {
                setBusy($button, false);
            },
        });
    });

    $('#workerAddForm').on('submit', function (event) {
        event.preventDefault();

        var formData = new FormData(this);
        formData.set('enabled', $('#workerAddEnabled').prop('checked') ? 1 : 0);

        var $button = $(this).find('button[type="submit"]');
        setBusy($button, true);
        $addResult.removeClass('text-success text-danger').text('');

        $.ajax({
            type: 'POST',
            url: workerApiUrl('relayWorkers'),
            data: formData,
            contentType: false,
            processData: false,
            success: function (response) {
                if (response.status) {
                    showSuccessToast(label('saved', 'Worker saved.'));
                    window.location.reload();
                } else {
                    $addResult.addClass('text-danger').text(response.message || 'Request failed.');
                }
            },
            error: function (xhr) {
                $addResult.addClass('text-danger').text(workerError(xhr));
                somethingWentWrongToast();
            },
            complete: function () {
                setBusy($button, false);
            },
        });
    });

    $(document).on('change', '.worker-toggle', function () {
        var $toggle = $(this);
        var id = $toggle.data('worker-id');
        var enabled = $toggle.prop('checked') ? 1 : 0;

        $toggle.prop('disabled', true);

        $.ajax({
            type: 'POST',
            url: workerApiUrl('relayWorkers/' + id + '/toggle'),
            data: {
                _token: csrf,
                enabled: enabled,
            },
            success: function (response) {
                if (response.status) {
                    showSuccessToast();
                } else {
                    $toggle.prop('checked', !enabled);
                    somethingWentWrongToast();
                }
            },
            error: function (xhr) {
                $toggle.prop('checked', !enabled);
                $testResult.removeClass('text-success').addClass('text-danger').text(workerError(xhr));
                somethingWentWrongToast();
            },
            complete: function () {
                $toggle.prop('disabled', false);
            },
        });
    });

    $(document).on('click', '.worker-test', function () {
        var $button = $(this);
        var id = $button.data('worker-id');

        setBusy($button, true);
        $testResult.removeClass('text-success text-danger').text('');

        $.ajax({
            type: 'POST',
            url: workerApiUrl('relayWorkers/' + id + '/test'),
            data: { _token: csrf },
            success: function (response) {
                var result = response.result || {};
                var $row = $('tr[data-worker-id="' + id + '"]');
                var ok = !!result.ok;

                $row.find('.worker-status')
                    .removeClass('bg-success bg-danger bg-secondary')
                    .addClass(ok ? 'bg-success' : 'bg-danger')
                    .text(ok ? label('online', 'online') : label('offline', 'offline'));

                $row.find('.worker-latency').text(result.latency_ms ? result.latency_ms + ' ms' : '-');
                $row.find('.worker-checked').text(label('justNow', 'just now'));
                $row.find('.worker-error').text(ok ? '' : (result.message || ''));

                $testResult
                    .removeClass('text-success text-danger')
                    .addClass(ok ? 'text-success' : 'text-danger')
                    .text((result.worker_name || 'Worker') + ': ' + (result.message || ''));

                if (ok) {
                    showSuccessToast(label('testOk', 'Worker is healthy.'));
                } else {
                    somethingWentWrongToast();
                }
            },
            error: function (xhr) {
                $testResult.removeClass('text-success').addClass('text-danger').text(workerError(xhr));
                somethingWentWrongToast();
            },
            complete: function () {
                setBusy($button, false);
            },
        });
    });

    $(document).on('click', '.worker-delete', function () {
        var $button = $(this);
        var id = $button.data('worker-id');

        if (!window.confirm(label('deleteConfirm', 'Delete this Worker?'))) {
            return;
        }

        setBusy($button, true);

        $.ajax({
            type: 'POST',
            url: workerApiUrl('relayWorkers/' + id),
            data: {
                _token: csrf,
                _method: 'DELETE',
            },
            success: function (response) {
                if (response.status) {
                    showSuccessToast(label('removed', 'Worker removed.'));
                    $('tr[data-worker-id="' + id + '"]').remove();
                } else {
                    somethingWentWrongToast();
                }
            },
            error: function (xhr) {
                $testResult.removeClass('text-success').addClass('text-danger').text(workerError(xhr));
                somethingWentWrongToast();
            },
            complete: function () {
                setBusy($button, false);
            },
        });
    });

    $('#testAllWorkersBtn').on('click', function () {
        var $button = $(this);
        setBusy($button, true);
        $testResult.removeClass('text-success text-danger').text('');

        $.ajax({
            type: 'POST',
            url: workerApiUrl('relayWorkers/test-all'),
            data: { _token: csrf },
            success: function (response) {
                if (response.status) {
                    showSuccessToast(response.ok ? label('testOk', 'All workers are healthy.') : label('testFailed', 'One or more workers failed.'));
                    window.location.reload();
                } else {
                    somethingWentWrongToast();
                }
            },
            error: function (xhr) {
                $testResult.removeClass('text-success').addClass('text-danger').text(workerError(xhr));
                somethingWentWrongToast();
            },
            complete: function () {
                setBusy($button, false);
            },
        });
    });
});
