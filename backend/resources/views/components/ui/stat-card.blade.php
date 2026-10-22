@props(['label', 'value', 'hint' => null, 'href' => null])

@php
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'block rounded-card border border-hairline bg-canvas p-6']) }}>
    <p class="text-micro font-semibold uppercase tracking-wider text-steel">{{ $label }}</p>
    <p class="mt-2 text-heading-3 font-semibold text-ink">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-caption text-steel">{{ $hint }}</p>
    @endif
</{{ $tag }}>
