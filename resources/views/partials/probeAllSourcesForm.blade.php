{{-- Probes every row of the failed-source table. The checkboxes above belong
     to the requeue form and are disabled for retrying rows, so the rows are
     posted here as hidden fields instead: every displayed row is probed. --}}
<form method="POST" action="{{ route('probeRelaySources') }}" class="d-flex flex-wrap align-items-center gap-2 mb-3">
    @csrf
    @foreach($failedSources as $source)
        <input type="hidden" name="sources[]" value="{{ $source->source_type }}:{{ $source->id }}">
    @endforeach
    <button type="submit" class="btn btn-sm btn-outline-secondary">
        تست لینک همهٔ موارد این لیست
    </button>
    <span class="text-muted small">{{ $failedSources->count() }} ردیف نمایش‌داده‌شده بررسی می‌شود، از جمله موارد در انتظار تلاش مجدد.</span>
</form>
