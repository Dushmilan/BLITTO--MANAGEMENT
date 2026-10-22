@props(['tone' => 'success'])

@php
    $tones = [
        'success' => 'border-brand-green bg-surface text-ink',
        'warning' => 'border-brand-warn bg-surface text-ink',
        'danger' => 'border-brand-error bg-surface text-ink',
    ];
@endphp

<div role="status" data-toast class="mb-4 flex items-start justify-between gap-4 rounded-card border-l-4 px-4 py-3 text-body-sm shadow-sm {{ $tones[$tone] ?? $tones['success'] }}">
    <span>{{ $slot }}</span>
    <button type="button" data-toast-dismiss class="text-steel hover:text-ink" aria-label="Dismiss">&times;</button>
</div>
