@props(['variant' => 'primary', 'href' => null, 'type' => 'submit'])

@php
    $variants = [
        'primary' => 'bg-ink text-canvas hover:opacity-90',
        'accent' => 'bg-brand-green text-[#0a0a0a] hover:bg-brand-green-deep',
        'secondary' => 'border border-hairline text-ink hover:bg-surface',
        'danger' => 'bg-brand-error text-[#0a0a0a] hover:opacity-90',
        'ghost' => 'text-ink hover:bg-surface',
    ];

    $classes = 'inline-flex items-center justify-center gap-2 rounded-pill px-5 py-2.5 text-body-sm font-medium transition-colors disabled:opacity-50 '
        .($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
