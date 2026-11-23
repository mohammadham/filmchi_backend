        @php
            // The card is included from the relay page today; a caller that does
            // not pass the list still gets the empty state instead of an error.
            $botSubmissions = $botSubmissions ?? collect();
        @endphp

        <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0 fw-semibold">{{ __('botSubmissions') }}</h5>
                <span class="badge bg-secondary">{{ $botSubmissions->count() }}</span>
            </div>
            <div class="card-body">
                @if($botSubmissions->isEmpty())
                    <p class="text-muted mb-0">{{ __('botSubmissionsEmpty') }}</p>
                @else
                    <div class="table-responsive">
                        {{-- the themed striping leaves even rows on Bootstrap's dark text --}}
                        <table class="table table-sm table-striped align-middle mb-0" style="--bs-table-color: var(--text-white, #fff)">
                            <thead>
                                <tr>
                                    <th width="60px">ID</th>
                                    <th>{{ __('title') }}</th>
                                    <th width="110px">{{ __('botSubmissionUser') }}</th>
                                    <th width="80px">{{ __('quality') }}</th>
                                    <th width="90px">{{ __('botSubmissionSeries') }}</th>
                                    <th width="100px">{{ __('botSubmissionStatus') }}</th>
                                    <th width="130px">{{ __('botSubmissionReceived') }}</th>
                                    <th width="80px">{{ __('action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($botSubmissions as $submission)
                                    <tr>
                                        <td>{{ $submission->id }}</td>
                                        <td>
                                            <div class="text-break">{{ \Illuminate\Support\Str::limit((string) $submission->name, 60) }}</div>
                                            <div class="small text-muted text-break" dir="ltr">{{ $submission->mime ?: $submission->file_unique_id }}</div>
                                        </td>
                                        <td dir="ltr">{{ $submission->telegram_user_id }}</td>
                                        <td>
                                            @if($submission->quality)
                                                <span class="badge bg-secondary-subtle text-dark">{{ $submission->quality }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($submission->is_series)
                                                <span class="badge bg-info-subtle text-dark">{{ __('botSubmissionSeriesYes') }}</span>
                                            @else
                                                <span class="text-muted small">{{ __('botSubmissionSeriesNo') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @php
                                                $statusClass = $submission->status === 'done'
                                                    ? 'bg-success'
                                                    : ($submission->status === 'failed' ? 'bg-danger' : 'bg-warning text-dark');
                                            @endphp
                                            <span class="badge {{ $statusClass }}">{{ $submission->status }}</span>
                                        </td>
                                        <td class="small" title="{{ $submission->created_at }}">
                                            {{ \Illuminate\Support\Carbon::parse($submission->created_at)->diffForHumans() }}
                                        </td>
                                        <td class="text-end">
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary botSubmissionEdit"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#botSubmissionEditModal"
                                                    data-id="{{ $submission->id }}"
                                                    data-name="{{ $submission->name }}"
                                                    data-quality="{{ $submission->quality }}"
                                                    data-series="{{ $submission->is_series ? 1 : 0 }}">
                                                {{ __('edit') }}
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted small mt-2 mb-0">{{ __('botSubmissionsHint') }}</p>
                @endif
            </div>
        </div>
