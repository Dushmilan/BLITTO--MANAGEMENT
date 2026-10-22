<x-layouts.app :title="'Dashboard'">
    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.stat-card label="Total patents" :value="$summary['total_applications']" />
            <x-ui.stat-card label="Filed" :value="$summary['by_status']['FILED'] ?? 0" />
            <x-ui.stat-card label="Granted" :value="$summary['by_status']['GRANTED'] ?? 0" />
            <x-ui.stat-card label="Open deadlines" :value="$deadlines['open_deadlines']" />
            @if ($users !== null)
                <x-ui.stat-card label="Users" :value="$users->count()" />
                <x-ui.stat-card label="Inventors" :value="$users->where('role', 'user')->count()" />
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card title="Recent applications">
                @if ($recent->isEmpty())
                    <x-ui.empty-state title="No applications yet." description="New disclosures will appear here." />
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

            <x-ui.card title="Missing documents" subtitle="Granted patents that still have no uploaded document.">
                @if ($missing->isEmpty())
                    <x-ui.empty-state title="Nothing is missing." />
                @else
                    <ul class="divide-y divide-hairline-soft text-body-sm">
                        @foreach ($missing as $application)
                            <li class="py-3 text-ink">{{ $application->title }}</li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</x-layouts.app>
