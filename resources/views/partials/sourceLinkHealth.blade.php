<td class="small">
    @php
        $linkState = strtolower((string) ($source->link_status ?? ''));
        $linkBadge = ['alive' => ['success', 'سالم'], 'dead' => ['danger', 'مرده']][$linkState] ?? ['secondary', 'بررسی نشده'];
    @endphp
    <span class="badge bg-{{ $linkBadge[0] }}">{{ $linkBadge[1] }}</span>
    @if((int) ($source->link_fail_count ?? 0) > 0)
        <div class="text-danger">خطای پیاپی: {{ (int) $source->link_fail_count }}</div>
    @endif
    @if($source->link_checked_at)
        <div class="text-muted" title="{{ $source->link_checked_at }}">{{ \Illuminate\Support\Carbon::parse($source->link_checked_at)->diffForHumans() }}</div>
    @endif
</td>
