<x-layouts.app :title="'Patents'">
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-heading-3 font-semibold text-ink">Patents</h1>
                <p class="mt-1 text-body-sm text-steel">Manage the patent portfolio and its documents.</p>
            </div>

            <x-ui.button variant="primary" :href="route('admin.patents.create')">New patent</x-ui.button>
        </div>

        <form method="GET" action="{{ route('admin.patents.index') }}" class="flex flex-wrap items-end gap-4 rounded-card border border-hairline bg-canvas p-4">
            <div class="min-w-64 flex-1">
                <label for="q" class="mb-1 block text-body-sm font-medium text-ink">Search patents</label>
                <input id="q" name="q" type="search" value="{{ $query }}" class="w-full rounded-input border border-hairline bg-canvas px-3 py-2 text-body-md text-ink">
            </div>

            <div>
                <label for="status" class="mb-1 block text-body-sm font-medium text-ink">Status</label>
                <select id="status" name="status" class="rounded-input border border-hairline bg-canvas px-3 py-2 text-body-md text-ink">
                    <option value="all" @selected($status === 'all')>All statuses</option>
                    @foreach ($statuses as $case)
                        <option value="{{ $case->value }}" @selected($status === $case->value)>{{ ucfirst(strtolower(str_replace('_', ' ', $case->value))) }}</option>
                    @endforeach
                </select>
            </div>

            <x-ui.button variant="secondary" type="submit">Filter</x-ui.button>
            <a href="{{ route('admin.patents.index') }}" class="text-body-sm text-steel underline">Clear</a>
        </form>

        <x-ui.card>
            @if ($applications->isEmpty())
                @if ($total === 0 && $query === '' && $status === 'all')
                    <x-ui.empty-state title="No patents yet." description="Create the first disclosure to get started.">
                        <x-ui.button variant="primary" :href="route('admin.patents.create')">New patent</x-ui.button>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state title="No patents match these filters." description="Try a different search or clear the filters.">
                        <x-ui.button variant="primary" :href="route('admin.patents.create')">New patent</x-ui.button>
                        <a href="{{ route('admin.patents.index') }}" class="text-body-sm text-steel underline">Clear filters</a>
                    </x-ui.empty-state>
                @endif
            @else
                <p class="mb-4 text-caption text-steel">{{ $applications->count() }} of {{ $total }} patents shown</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-body-sm">
                        <thead class="text-caption uppercase tracking-wide text-steel">
                            <tr>
                                <th scope="col" class="pb-2">Title</th>
                                <th scope="col" class="pb-2">Reference</th>
                                <th scope="col" class="pb-2">Status</th>
                                <th scope="col" class="pb-2">Inventors</th>
                                <th scope="col" class="pb-2">Technology area</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline-soft">
                            @foreach ($applications as $application)
                                <tr>
                                    <td class="py-3">
                                        <a href="{{ route('admin.patents.show', $application) }}" class="font-medium text-ink hover:underline">{{ $application->title }}</a>
                                    </td>
                                    <td class="py-3 font-mono text-caption text-steel">{{ $application->application_number ?: substr($application->id, 0, 8) }}</td>
                                    <td class="py-3"><x-ui.status-badge :status="$application->status" /></td>
                                    <td class="py-3 text-steel">{{ $application->inventor_name ?: '—' }}</td>
                                    <td class="py-3 text-steel">{{ $application->technology_area ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-6">{{ $applications->withQueryString()->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
