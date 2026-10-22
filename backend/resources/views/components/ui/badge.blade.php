@props(['tone' => 'neutral'])

@php
    $tones = [
        'neutral' => 'bg-surface text-slate border-hairline',
        'info' => 'bg-surface text-brand-tag border-brand-tag',
        'success' => 'bg-surface text-ink border-brand-green',
        'warning' => 'bg-surface text-ink border-brand-warn',
        'danger' => 'bg-surface text-ink border-brand-error',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-pill border px-3 py-1 text-micro font-medium '.($tones[$tone] ?? $tones['neutral'])]) }}>
    {{ $slot }}
</span>
