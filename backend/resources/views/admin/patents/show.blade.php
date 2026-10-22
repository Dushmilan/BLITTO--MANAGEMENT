<x-layouts.app :title="$application->title">
    <div class="space-y-6">
        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-heading-3 font-semibold text-ink">{{ $application->title }}</h1>
                    <p class="mt-1 font-mono text-caption text-steel">
                        Reference: {{ $application->application_number ?: substr($application->id, 0, 8) }}
                    </p>
                    <p class="mt-1 text-body-sm text-steel">
                        {{ $application->inventor_name ?: 'No inventor recorded' }}
                        &middot; {{ $application->technology_area ?: 'No technology area' }}
                    </p>
                </div>

                <x-ui.status-badge :status="$application->status" />
            </div>

            <p class="mt-4 text-caption text-steel">
                Created {{ $application->created_at?->toDayDateTimeString() }}
                &middot; Updated {{ $application->updated_at?->toDayDateTimeString() }}
            </p>
        </x-ui.card>
    </div>
</x-layouts.app>
