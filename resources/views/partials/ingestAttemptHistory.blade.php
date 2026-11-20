{{-- The last ingest attempts of one source: time, outcome, bytes, workers, error. --}}
@php
    $attemptStyles = [
        'downloaded' => ['success', 'دانلود شد'],
        'retrying' => ['warning text-dark', 'تلاش مجدد'],
        'failed' => ['danger', 'ناموفق'],
        'deferred' => ['secondary', 'تعویق'],
        'quarantined' => ['dark', 'قرنطینه'],
        'requeued' => ['info text-dark', 'صف مجدد'],
    ];
@endphp
<div class="small text-muted d-flex flex-column gap-1">
    <span>تلاش‌های اخیر:</span>
    @foreach($attempts as $attempt)
        @php
            [$style, $label] = $attemptStyles[$attempt->outcome] ?? ['secondary', $attempt->outcome];
            $bytes = (int) $attempt->bytes;
            $bytesLabel = $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : ($bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B');
            $error = trim((string) $attempt->error);
        @endphp
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-{{ $style }}">
                #{{ (int) $attempt->attempt_no }}
                · {{ optional($attempt->finished_at)->format('m/d H:i') ?? '—' }}
                · {{ $label }}
            </span>
            <span>{{ $bytesLabel }}</span>
            <span>{{ $attempt->route ? 'ورکر ' . $attempt->route : 'مستقیم' }}</span>
            @if($error !== '')
                <span class="text-danger" dir="auto" title="{{ \Illuminate\Support\Str::limit($error, 500) }}">
                    {{ \Illuminate\Support\Str::limit($error, 100) }}
                </span>
            @endif
        </div>
    @endforeach
</div>
