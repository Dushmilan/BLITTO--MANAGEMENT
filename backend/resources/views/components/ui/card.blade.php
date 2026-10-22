@props(['title' => null, 'subtitle' => null])

<section {{ $attributes->merge(['class' => 'rounded-card border border-hairline bg-canvas p-6']) }}>
    @if ($title)
        <header class="mb-4">
            <h2 class="text-heading-5 font-semibold text-ink">{{ $title }}</h2>
            @if ($subtitle)
                <p class="mt-1 text-body-sm text-steel">{{ $subtitle }}</p>
            @endif
        </header>
    @endif

    {{ $slot }}
</section>
