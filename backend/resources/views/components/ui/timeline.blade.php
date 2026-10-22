@props(['entries'])

@if ($entries->isEmpty())
    <p class="text-body-sm text-steel">No status changes recorded yet.</p>
@else
    <ol class="space-y-4">
        @foreach ($entries->sortByDesc('changed_at') as $entry)
            <li class="border-l-2 border-hairline pl-4">
                <p class="text-body-sm text-ink">
                    {{ $entry->old_status ?: '—' }} &rarr; <strong>{{ $entry->new_status }}</strong>
                </p>
                <p class="mt-1 text-caption text-steel">
                    {{ $entry->changed_by ?: 'system' }} &middot; {{ $entry->changed_at?->toDayDateTimeString() }}
                </p>
                @if ($entry->reason)
                    <p class="mt-1 text-body-sm text-slate">{{ $entry->reason }}</p>
                @endif
            </li>
        @endforeach
    </ol>
@endif
