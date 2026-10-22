@props(['title', 'description' => null])

<div class="rounded-card border border-dashed border-hairline px-6 py-10 text-center">
    <p class="text-body-md font-medium text-ink">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 text-body-sm text-steel">{{ $description }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
