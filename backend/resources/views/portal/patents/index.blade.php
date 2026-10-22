<x-layouts.app :title="'My Patents'">
    <div class="space-y-6">
        <div>
            <h1 class="text-heading-3 font-semibold text-ink">My Patents</h1>
            <p class="mt-1 text-body-sm text-steel">Every disclosure submitted on your behalf.</p>
        </div>

        <x-ui.card>
            @if ($applications->isEmpty())
                <x-ui.empty-state title="No patents found." description="Your patents will appear here once submitted." />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-body-sm">
                        <thead class="text-caption uppercase tracking-wide text-steel">
                            <tr>
                                <th scope="col" class="pb-2">Title</th>
                                <th scope="col" class="pb-2">Reference</th>
                                <th scope="col" class="pb-2">Status</th>
                                <th scope="col" class="pb-2">Technology area</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline-soft">
                            @foreach ($applications as $application)
                                <tr>
                                    <td class="py-3">
                                        <a href="{{ route('portal.patents.show', $application) }}" class="font-medium text-ink hover:underline">{{ $application->title }}</a>
                                    </td>
                                    <td class="py-3 font-mono text-caption text-steel">{{ $application->application_number ?: substr($application->id, 0, 8) }}</td>
                                    <td class="py-3"><x-ui.status-badge :status="$application->status" /></td>
                                    <td class="py-3 text-steel">{{ $application->technology_area ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
