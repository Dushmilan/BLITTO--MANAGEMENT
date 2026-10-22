<x-layouts.app :title="'Overview'">
    <div class="space-y-6">
        <h1 class="text-heading-3 font-semibold text-ink">Welcome back</h1>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.stat-card label="My patents" :value="$applications->count()" />
            <x-ui.stat-card label="Notifications" :value="0" />
        </div>

        <x-ui.card title="Recent applications">
            @if ($recent->isEmpty())
                <x-ui.empty-state title="No patents found." description="Your patents will appear here once submitted." />
            @else
                <table class="w-full text-left text-body-sm">
                    <thead class="text-caption uppercase tracking-wide text-steel">
                        <tr>
                            <th scope="col" class="pb-2">Title</th>
                            <th scope="col" class="pb-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline-soft">
                        @foreach ($recent as $application)
                            <tr>
                                <td class="py-3 text-ink">{{ $application->title }}</td>
                                <td class="py-3"><x-ui.status-badge :status="$application->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
