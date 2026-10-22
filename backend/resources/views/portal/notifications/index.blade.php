<x-layouts.app :title="'Notifications'">
    <div class="space-y-6">
        <div>
            <h1 class="text-heading-3 font-semibold text-ink">Notifications</h1>
            <p class="mt-1 text-body-sm text-steel">Updates about your patents, newest first.</p>
        </div>

        @if ($notifications->isEmpty())
            <x-ui.empty-state title="No notifications yet." description="Status changes will appear here." />
        @else
            <ul class="space-y-4">
                @foreach ($notifications as $notification)
                    <li>
                        <x-ui.card>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <h2 class="text-body-md font-medium text-ink">{{ $notification->subject }}</h2>
                                <x-ui.badge tone="info">{{ $notification->kind }}</x-ui.badge>
                            </div>
                            <p class="mt-2 whitespace-pre-line text-body-sm text-slate">{{ $notification->body }}</p>
                            <p class="mt-2 text-caption text-steel">{{ $notification->sent_at?->toDayDateTimeString() }}</p>
                        </x-ui.card>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.app>
