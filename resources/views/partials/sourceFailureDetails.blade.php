{{-- The details row under one failed source: the quarantine verdict (dead
     links only) and the last attempts with their own errors. A live row with
     no history renders nothing, so the table gets no empty second row. --}}
@php
    $quarantineReason = (string) ($source->quarantine_reason ?? '');
@endphp
@if($quarantineReason !== '' || count($attempts))
    <tr>
        <td></td>
        <td colspan="12" class="pt-0 pb-2">
            @if($quarantineReason !== '')
                <div class="small text-danger mb-1" dir="auto">
                    <strong>دلیل قرنطینه:</strong> {{ $quarantineReason }}
                </div>
            @endif
            @if($attempts)
                @include('partials.ingestAttemptHistory', ['attempts' => $attempts])
            @endif
        </td>
    </tr>
@endif
